/**
 * Установка игр на ноде.
 *
 * Поддерживаемые способы (поле installer.type из описания игры):
 *   steamcmd — AppID через SteamCMD
 *   script   — готовый скрипт из репозитория game-images
 *   download — скачивание архива по URL и распаковка
 *   none     — игра уже на месте или устанавливается вручную
 *
 * Весь вывод установки стримится в панель пошагово, чтобы пользователь
 * видел, что происходит.
 */

import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { EventEmitter } from 'node:events';

const exec = promisify(execFile);

export class Installer extends EventEmitter {
    constructor(config, logger) {
        super();

        this.config = config;
        this.log = logger;
        this.steamcmdPath = this.findSteamcmd();
    }

    findSteamcmd() {
        for (const candidate of [
            '/usr/games/steamcmd/steamcmd',
            '/usr/local/bin/steamcmd',
            '/opt/steamcmd/steamcmd',
            '/usr/bin/steamcmd',
        ]) {
            if (fs.existsSync(candidate)) return candidate;
        }

        return null;
    }

    /**
     * @param {object} spec        — спецификация сервера
     * @param {string} serverDir   — каталог установки
     * @param {object} options     — { build, onStep, onLog, onProgress }
     */
    async install(spec, serverDir, options = {}) {
        const game = spec.game || {};
        const installer = spec.installer || game.installer || { type: 'none' };
        const build = options.build || spec.build || null;

        // Сборка может переопределить способ установки
        let effective = installer;

        if (build) {
            const buildDef = findBuild(game.builds, build);
            if (buildDef?.installer) {
                effective = { ...installer, ...buildDef.installer };
            }
        }

        const steps = [];
        let stepNumber = 0;

        const nextStep = async (name, fn) => {
            const number = ++stepNumber;
            const started = Date.now();

            options.onStep?.({ step: number, name, status: 'running' });
            options.onLog?.(`${name}…`);

            try {
                const result = await fn();

                steps.push({ step: number, name, status: 'done', duration_ms: Date.now() - started });
                options.onStep?.({ step: number, name, status: 'done', duration_ms: Date.now() - started });

                return result;
            } catch (e) {
                steps.push({ step: number, name, status: 'failed', error: e.message, duration_ms: Date.now() - started });
                options.onStep?.({ step: number, name, status: 'failed', error: e.message, duration_ms: Date.now() - started });

                throw e;
            }
        };

        const totalSteps = effective.type === 'none' ? 2 : 4;
        const progress = () => Math.round((stepNumber / totalSteps) * 100);

        try {
            await nextStep('Подготовка каталога', async () => {
                await fsp.mkdir(serverDir, { recursive: true, mode: 0o750 });
                await fsp.mkdir(path.join(serverDir, 'logs'), { recursive: true });
            });

            options.onProgress?.(progress());

            if (effective.type === 'steamcmd') {
                await nextStep('SteamCMD', () => this.installSteam(spec, serverDir, effective, options));
            } else if (effective.type === 'script') {
                await nextStep('Скрипт установки', () => this.runScript(spec, serverDir, effective, options));
            } else if (effective.type === 'download') {
                await nextStep('Загрузка файлов', () => this.downloadFiles(spec, serverDir, effective, options));
            } else {
                await nextStep('Подготовка завершена', async () => {
                    options.onLog?.('Игра не требует установки — используются имеющиеся файлы.');
                });
            }

            options.onProgress?.(progress());

            // Постустановочный скрипт: конфиги, плагины, карты
            if (effective.post_install) {
                await nextStep('Настройка игры', () => this.runPostInstall(spec, serverDir, effective, options));
            }

            await nextStep('Создание файлов конфигурации', async () => {
                await this.createBootstrapFiles(spec, serverDir, options);
            });

            await nextStep('Финализация', async () => {
                await this.finalize(spec, serverDir, options);
            });

            await fsp.writeFile(
                path.join(serverDir, '.gamedock-ready'),
                JSON.stringify({ installed_at: new Date().toISOString(), build, game: game.slug }, null, 2),
            );

            const totalMs = steps.reduce((sum, s) => sum + (s.duration_ms || 0), 0);

            return { ok: true, steps, duration_ms: totalMs };
        } catch (e) {
            return { ok: false, error: e.message, steps };
        }
    }

    // ── SteamCMD ─────────────────────────────────────────────────────

    async installSteam(spec, serverDir, installer, options) {
        if (!this.steamcmdPath) {
            throw new Error(
                'SteamCMD не найден. Установите пакет steamcmd (apt install steamcmd) '
                + 'или выберите другой способ установки для игры.',
            );
        }

        const appId = installer.app_id || spec.game?.steam_appid;

        if (!appId) {
            throw new Error('Не указан Steam AppID для игры');
        }

        const args = [
            '+force_install_dir', serverDir,
            '+login', installer.anonymous === false ? 'anonymous' : 'anonymous',
            '+app_update', String(appId),
            'validate',
            '+quit',
        ];

        await this.run('steamcmd', args, {
            cwd: path.dirname(this.steamcmdPath),
            timeout: installer.timeout || 3600000,
            onOutput: (line) => {
                options.onLog?.(line);

                // Прогресс по процентам из вывода SteamCMD
                const match = line.match(/(\d{1,3})\.\d+%/);
                if (match) {
                    options.onProgress?.(Math.round(30 + (parseInt(match[1], 10) / 100) * 55));
                }
            },
        });
    }

    /**
     * Постустановочный скрипт: конфиги, плагины, генерация миров.
     * Отдельный шаг, потому что он тоже может падать и занимать время.
     */
    async runPostInstall(spec, serverDir, installer, options) {
        const scriptName = installer.post_install;

        if (!scriptName) {
            return { ok: true, skipped: true };
        }

        const scriptPath = path.isAbsolute(scriptName)
            ? scriptName
            : path.join(this.config.templatesRoot, scriptName);

        if (!fs.existsSync(scriptPath)) {
            throw new Error(
                `Постустановочный скрипт не найден: ${scriptPath}. `
                + 'Обновите репозиторий game-images на ноде.',
            );
        }

        await fsp.chmod(scriptPath, 0o755).catch(() => null);

        const env = {
            ...process.env,
            GD_SERVER_DIR: serverDir,
            GD_GAME: spec.game?.slug || '',
            GD_GAME_FAMILY: spec.game?.family || '',
            GD_BUILD: spec.build || installer.build || '',
            GD_VERSION: installer.version || '',
            GD_SLOTS: String(spec.server.slots || 10),
            GD_GAME_PORT: String(spec.server.ports?.game || 0),
            GD_QUERY_PORT: String(spec.server.ports?.query || 0),
            GD_RCON_PORT: String(spec.server.ports?.rcon || 0),
            GD_RCON_PASSWORD: (spec.server.env || {}).RCON_PASSWORD || '',
            GD_SERVER_NAME: spec.server.name || '',
            GD_RAM_MB: String(spec.server.resources?.memory_mb || 1024),
            GD_CPU_PERCENT: String(spec.server.resources?.cpu_percent || 50),
            GD_TEMPLATES_ROOT: this.config.templatesRoot,
        };

        return this.run('bash', [scriptPath], {
            cwd: serverDir,
            env,
            timeout: installer.post_install_timeout || 900000,
            onOutput: (line) => options.onLog?.(line),
        });
    }

    // ── Скрипт из репозитория ────────────────────────────────────────

    async runScript(spec, serverDir, installer, options) {
        const scriptName = installer.script || `${spec.game?.slug}/install.sh`;
        const scriptPath = path.join(this.config.templatesRoot, scriptName);

        if (!fs.existsSync(scriptPath)) {
            throw new Error(
                `Скрипт установки не найден: ${scriptPath}. `
                + 'Скопируйте репозиторий game-images в каталог templates_root на ноде.',
            );
        }

        await fsp.chmod(scriptPath, 0o755).catch(() => null);

        const env = {
            ...process.env,
            GD_SERVER_DIR: serverDir,
            GD_GAME: spec.game?.slug || '',
            GD_GAME_FAMILY: spec.game?.family || '',
            GD_BUILD: spec.build || '',
            GD_VERSION: installer.version || '',
            GD_SLOTS: String(spec.server.slots || 10),
            GD_RAM_MB: String(spec.server.resources?.memory_mb || 1024),
            GD_GAME_PORT: String(spec.server.ports?.game || 0),
            GD_QUERY_PORT: String(spec.server.ports?.query || 0),
            GD_RCON_PORT: String(spec.server.ports?.rcon || 0),
            GD_TEMPLATES_ROOT: this.config.templatesRoot,
            GD_PLUGINS_ROOT: this.config.pluginsRoot,
        };

        await this.run('bash', [scriptPath], {
            cwd: serverDir,
            env,
            timeout: installer.timeout || 1800000,
            onOutput: (line) => options.onLog?.(line),
        });
    }

    // ── Загрузка архива ──────────────────────────────────────────────

    async downloadFiles(spec, serverDir, installer, options) {
        const url = installer.source_url || installer.url;

        if (!url) {
            throw new Error('Не указан URL для загрузки файлов игры');
        }

        const archive = path.join(serverDir, 'game.tar.gz');

        options.onLog?.(`Загрузка ${url}`);

        const { stdout } = await exec('curl', [
            '-fsSL', '--retry', '3', '--retry-delay', '2',
            '-o', archive, url,
        ], { timeout: installer.timeout || 1800000, maxBuffer: 1024 * 1024 });

        options.onLog?.('Распаковка…');

        const extractCommand = url.endsWith('.zip')
            ? ['unzip', '-o', archive, '-d', serverDir]
            : ['tar', '-xzf', archive, '-C', serverDir];

        await this.run(extractCommand[0], extractCommand.slice(1), {
            cwd: serverDir,
            onOutput: (line) => options.onLog?.(line),
        });

        await fsp.rm(archive, { force: true });
    }

    // ── Файлы конфигурации ───────────────────────────────────────────

    async createBootstrapFiles(spec, serverDir, options) {
        const files = spec.game?.bootstrap_files || spec.bootstrap_files || [];
        const configValues = spec.server?.config_values || {};

        for (const file of files) {
            const target = path.join(serverDir, file.path);
            const content = renderTemplate(file.content || '', {
                ...configValues,
                game_port: spec.server.ports?.game,
                query_port: spec.server.ports?.query,
                rcon_port: spec.server.ports?.rcon,
                server_name: spec.server.name,
                slots: spec.server.slots,
            });

            if (fs.existsSync(target)) {
                options.onLog?.(`Файл ${file.path} уже существует — пропускаем.`);
                continue;
            }

            await fsp.mkdir(path.dirname(target), { recursive: true });
            await fsp.writeFile(target, content, 'utf8');

            options.onLog?.(`Создан ${file.path}`);
        }
    }

    async finalize(spec, serverDir, options) {
        // Права на каталог — системному пользователю
        try {
            const { stdout } = await exec('id', ['-u', this.config.systemUser]);
            const uid = parseInt(stdout.trim(), 10);
            if (!Number.isNaN(uid)) {
                await exec('chown', ['-R', `${uid}:${uid}`, serverDir], { timeout: 120000 });
            }
        } catch (e) {
            options.onLog?.(`chown пропущен: ${e.message}`);
        }

        // Права на исполняемые файлы
        try {
            for (const name of ['run.sh', 'start.sh', 'launch.sh']) {
                const candidate = path.join(serverDir, name);
                if (fs.existsSync(candidate)) {
                    await fsp.chmod(candidate, 0o755);
                }
            }
        } catch { /* noop */ }
    }

    /**
     * Обновление игры (SteamCMD app_update без полной валидации).
     */
    async update(spec, serverDir, build = null) {
        const game = spec.game || {};
        const installer = spec.installer || game.installer || { type: 'none' };

        if (installer.type !== 'steamcmd') {
            return { ok: true, message: 'Для этой игры обновление не требуется' };
        }

        if (!this.steamcmdPath) {
            return { ok: false, error: 'SteamCMD не найден на ноде' };
        }

        const appId = installer.app_id || game.steam_appid;

        try {
            await this.run('steamcmd', [
                '+force_install_dir', serverDir,
                '+login', 'anonymous',
                '+app_update', String(appId),
                '+quit',
            ], { timeout: installer.timeout || 1800000 });
        } catch (e) {
            return { ok: false, error: e.message };
        }

        return { ok: true, build: build || spec.build };
    }

    /**
     * Установка плагина/мода из каталога панели.
     */
    async installTemplate(spec, serverDir, template, options = {}) {
        const target = path.join(serverDir, template.target_path || '.');

        await fsp.mkdir(target, { recursive: true });

        if (template.source_type === 'builtin') {
            const source = path.join(this.config.templatesRoot, template.builtin_path || '');

            if (!fs.existsSync(source)) {
                return { ok: false, error: `Шаблон не найден в репозитории: ${source}` };
            }

            await fsp.cp(source, target, { recursive: true, force: true });

            return { ok: true, method: 'copy' };
        }

        if (template.source_type === 'url' || template.source_type === 's3') {
            const url = template.source_url || (this.config.backups.s3.endpoint
                ? `${this.config.backups.s3.endpoint}/${this.config.backups.s3.bucket}/${template.s3_key}`
                : null);

            if (!url) {
                return { ok: false, error: 'Не указан источник шаблона' };
            }

            const fileName = url.split('/').pop().split('?')[0];
            const destination = path.join(target, fileName);

            options.onLog?.(`Загрузка ${fileName}`);

            await exec('curl', ['-fsSL', '--retry', '3', '-o', destination, url], {
                timeout: 600000,
                maxBuffer: 1024 * 1024,
            });

            // Распаковка архива
            if (/\.(zip|tgz|tar\.gz|tar)$/.test(fileName)) {
                const command = fileName.endsWith('.zip') ? 'unzip' : 'tar';
                const args = command === 'unzip'
                    ? ['-o', destination, '-d', target]
                    : ['-xzf', destination, '-C', target];

                await this.run(command, args, { cwd: target });
                await fsp.rm(destination, { force: true });
            }

            return { ok: true, method: 'download' };
        }

        return { ok: false, error: 'Неизвестный тип источника шаблона' };
    }

    async removeTemplate(spec, serverDir, template) {
        const target = path.join(serverDir, template.target_path || '.', template.name || '');

        try {
            await fsp.rm(target, { recursive: true, force: true });
            return { ok: true };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    /**
     * Запуск внешней команды со сбором вывода.
     */
    run(command, args, options = {}) {
        const { cwd, env, timeout = 600000, onOutput } = options;

        return new Promise((resolve, reject) => {
            const child = spawn(command, args, {
                cwd,
                env: env || process.env,
                stdio: ['ignore', 'pipe', 'pipe'],
            });

            let output = '';
            const timer = setTimeout(() => {
                child.kill('SIGKILL');
                reject(new Error(`Команда ${command} превысила таймаут ${timeout / 1000}с`));
            }, timeout);

            const handle = (chunk) => {
                const text = chunk.toString();
                output += text;
                for (const line of text.split('\n')) {
                    if (line.trim()) onOutput?.(line.trim());
                }
            };

            child.stdout.on('data', handle);
            child.stderr.on('data', handle);

            child.on('error', (e) => {
                clearTimeout(timer);
                reject(new Error(`Не удалось запустить ${command}: ${e.message}`));
            });

            child.on('close', (code) => {
                clearTimeout(timer);

                if (code === 0) {
                    resolve({ output, code });
                } else {
                    const tail = output.split('\n').slice(-20).join('\n');
                    reject(new Error(`${command} завершился с кодом ${code}\n${tail}`));
                }
            });
        });
    }
}

function findBuild(builds, id) {
    if (!Array.isArray(builds)) return null;

    return builds.find((b) => b.id === id) || null;
}

/** Подстановка {переменных} в шаблон конфигурации. */
function renderTemplate(content, values) {
    return String(content).replace(/\{(\w+)\}/g, (match, key) => {
        if (key.includes('password')) return '';
        return values[key] !== undefined ? String(values[key]) : match;
    });
}
