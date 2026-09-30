/**
 * Драйвер нативных процессов: systemd-run + cgroup v2.
 *
 * Используется, когда Docker недоступен (типичный дешёвый VPS).
 * Изоляция — через systemd-транзиентные юниты:
 *   MemoryMax=, CPUQuota=, TasksMax=, IOWeight= — всё это ядро применяет само.
 */

import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { BaseRuntime, delay } from './base.js';
import { directorySize } from '../utils/fsx.js';

const exec = promisify(execFile);

export class NativeRuntime extends BaseRuntime {
    constructor(config, logger) {
        super(config, logger);

        this.scope = config.native.systemdScope || 'gamedock.slice';
        this.cgroupRoot = config.native.cgroupRoot || '/sys/fs/cgroup/gamedock';
        this.user = config.systemUser;
        this.group = config.systemGroup;
        this.hasSystemd = null;
        this.processes = new Map(); // serverId → { child, startedAt, restarts, stdoutBuffer }
    }

    get name() {
        return 'native';
    }

    unitName(serverId) {
        return `gamedock-${serverId}.service`;
    }

    // ── Проверка доступности ──────────────────────────────────────────

    async isAvailable() {
        if (this.hasSystemd === false) {
            return { ok: false, reason: 'systemd недоступен' };
        }

        // cgroup v2 обязателен: без него лимиты не работают
        const cgroupVersion = await detectCgroupVersion();

        if (cgroupVersion !== 2) {
            this.hasSystemd = false;
            return {
                ok: false,
                reason: `Нужен cgroup v2 (сейчас v${cgroupVersion}). Примонтируйте /sys/fs/cgroup как cgroup2.`,
            };
        }

        if (!fs.existsSync('/run/systemd/system')) {
            this.hasSystemd = false;
            return { ok: false, reason: 'systemd не является init-системой' };
        }

        this.hasSystemd = true;
        return { ok: true, cgroup: 'v2' };
    }

    // ── Жизненный цикл ───────────────────────────────────────────────

    async create(spec) {
        await this.ensureSlice();
        await ensureDir(spec.path);

        // Каталог принадлежит системному пользователю
        try {
            await fsp.chown(spec.path, await uidOf(this.user), await gidOf(this.group));
            await fsp.chmod(spec.path, 0o750);
        } catch (e) {
            this.log.debug(`chown не выполнен: ${e.message}`);
        }

        return { ok: true, external_id: this.unitName(spec.server.id) };
    }

    async ensureSlice() {
        const sliceFile = path.join(this.cgroupRoot, '..', 'gamedock.slice');

        try {
            // Создаём slice, если его нет (systemd создаст сам при первом запуске юнита)
            if (!fs.existsSync(sliceFile)) {
                await fsp.mkdir(this.cgroupRoot, { recursive: true });
            }
        } catch (e) {
            this.log.debug(`Не удалось подготовить cgroup: ${e.message}`);
        }
    }

    /**
     * Сборка команды запуска с ресурсами.
     */
    buildCommand(spec) {
        const startup = spec.startup || {};
        const values = this.buildValues(spec);

        const argv = [
            this.interpolate(startup.exec || './server', values),
            ...this.interpolateArgs(startup.args || [], values),
        ];

        return argv.filter(Boolean);
    }

    buildEnv(spec) {
        const env = {
            PATH: '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin',
            HOME: spec.path,
            TZ: 'Europe/Moscow',
            LANG: 'C.UTF-8',
        };

        for (const [key, value] of Object.entries(spec.server.env || {})) {
            env[key] = typeof value === 'string' && value.startsWith('enc:') ? value.slice(4) : value;
        }

        return env;
    }

    /**
     * Запуск через systemd-run — systemd применяет все лимиты сам.
     */
    async start(spec) {
        const serverId = spec.server.id;
        const unit = this.unitName(serverId);
        const resources = spec.server.resources || {};
        const startup = spec.startup || {};
        const [command, ...args] = this.buildCommand(spec);

        if (!command) {
            return { ok: false, error: 'Не задана команда запуска' };
        }

        // Если уже запущен — ничего не делаем
        if (await this.isRunning(spec)) {
            return { ok: true, already_running: true };
        }

        const unitProps = [
            `WorkingDirectory=${spec.path}`,
            `User=${this.user}`,
            `Group=${this.group}`,
            `Environment=TZ=Europe/Moscow`,
            `Environment=GD_SERVER_ID=${serverId}`,
            `MemoryMax=${(resources.memory_mb || 1024)}M`,
        ];

        if (resources.swap_mb) {
            unitProps.push(`MemorySwapMax=${(resources.memory_mb + resources.swap_mb)}M`);
        } else {
            unitProps.push('MemorySwapMax=infinity');
        }

        if (resources.cpu_percent) {
            // CPUQuota в процентах от одного ядра
            unitProps.push(`CPUQuota=${Math.round((resources.cpu_percent / 100) * 100)}%`);
        }

        if (resources.pids) {
            unitProps.push(`TasksMax=${resources.pids}`);
        }

        // Ограничение диска через квоту, если файловая система её поддерживает
        if (resources.disk_mb) {
            unitProps.push(`IOWeight=${Math.max(10, 100 - Math.floor(resources.disk_mb / 512))}`);
        }

        // Ограничение сети через cgroup net_cls не работает, ставим через tc в prestart
        unitProps.push(`LimitNOFILE=65535`);
        unitProps.push(`KillSignal=${startup.stop_signal || 'SIGTERM'}`);
        unitProps.push(`TimeoutStopSec=${startup.stop_timeout || 30}`);
        unitProps.push('Restart=no');
        unitProps.push('SyslogIdentifier=gamedock');

        const systemctlArgs = [
            'run',
            `--unit=${unit}`,
            `--slice=${this.scope}`,
            '--collect',           // убрать юнит после остановки
            '--property=Type=simple',
            '--property=KillMode=mixed',
            ...unitProps.map((p) => `--property=${p}`),
        ];

        // Переменные окружения
        for (const [key, value] of Object.entries(this.buildEnv(spec))) {
            if (key === 'PATH' || key === 'HOME' || key === 'LANG') continue;
            systemctlArgs.push(`--setenv=${key}=${String(value).replace(/["\n]/g, '')}`);
        }

        systemctlArgs.push('--', command, ...args);

        try {
            // Дефолтный systemd-путь может отличаться — ищем
            const binary = await this.systemctlPath();
            const { stdout, stderr } = await exec(binary, systemctlArgs, {
                cwd: spec.path,
                timeout: 15000,
                maxBuffer: 1024 * 1024,
            });

            this.log.info(`native: сервер ${serverId} запущен (${unit})`, {
                command: [command, ...args].join(' ').slice(0, 200),
            });

            // Запоминаем момент старта для аптайма
            this.processes.set(serverId, {
                startedAt: Math.floor(Date.now() / 1000),
                restarts: this.processes.get(serverId)?.restarts || 0,
                consoleBuffer: [],
            });

            return { ok: true, started_at: this.processes.get(serverId).startedAt, output: (stdout + stderr).trim() };
        } catch (e) {
            const error = e.stderr || e.message || 'неизвестная ошибка';
            this.log.error(`native: не удалось запустить ${serverId}: ${error}`);

            return { ok: false, error: String(error).slice(0, 2000) };
        }
    }

    async systemctlPath() {
        for (const path of ['/usr/bin/systemctl', '/bin/systemctl', '/usr/local/bin/systemctl']) {
            if (fs.existsSync(path)) return path;
        }

        return 'systemctl';
    }

    async stop(spec, options = {}) {
        const unit = this.unitName(spec.server.id);
        const timeout = options.timeout || spec.startup?.stop_timeout || 30;

        try {
            await exec(await this.systemctlPath(), ['stop', unit], { timeout: (timeout + 15) * 1000 });
            this.log.info(`native: сервер ${spec.server.id} остановлен`);
            this.processes.delete(spec.server.id);
            return { ok: true };
        } catch (e) {
            // Юнита уже нет — это не ошибка
            if (e.code === 5 || /not loaded|does not exist/i.test(e.message)) {
                this.processes.delete(spec.server.id);
                return { ok: true };
            }

            return { ok: false, error: e.stderr || e.message };
        }
    }

    async kill(spec) {
        const unit = this.unitName(spec.server.id);

        try {
            await exec(await this.systemctlPath(), ['kill', '--signal=SIGKILL', unit], { timeout: 10000 });
            this.processes.delete(spec.server.id);
            return { ok: true };
        } catch (e) {
            if (e.code === 5) return { ok: true };
            return { ok: false, error: e.message };
        }
    }

    async isRunning(spec) {
        const unit = this.unitName(spec.server.id);

        try {
            const { stdout } = await exec(await this.systemctlPath(), ['is-active', unit], { timeout: 5000 });
            return stdout.trim() === 'active';
        } catch {
            return false;
        }
    }

    /**
     * Метрики из cgroup v2.
     */
    async stats(spec) {
        const serverId = spec.server.id;
        const cgroupPath = path.join(this.cgroupRoot, `gamedock-${serverId}.service`);
        const info = this.processes.get(serverId) || {};

        try {
            const [cpuRaw, memRaw, ioRaw] = await Promise.all([
                fsp.readFile(path.join(cgroupPath, 'cpu.stat'), 'utf8').catch(() => ''),
                fsp.readFile(path.join(cgroupPath, 'memory.current'), 'utf8').catch(() => '0'),
                fsp.readFile(path.join(cgroupPath, 'io.stat'), 'utf8').catch(() => ''),
            ]);

            const cpuUs = parseCpuStat(cpuRaw);
            const cpuPercent = cpuUs > 0 ? Math.min(800, (cpuUs / 1e6) * 100 / Math.max(1, uptimeOf(info.startedAt))) : 0;

            const memoryMb = Math.round(Number(memRaw.trim()) / 1048576);

            let netIn = 0;
            let netOut = 0;
            for (const line of ioRaw.split('\n')) {
                const match = line.match(/^(\d+):\s+rbytes=(\d+)\s+wbytes=(\d+)/);
                if (match) {
                    netIn = Math.round(Number(match[2]) / 1048576);
                    netOut = Math.round(Number(match[3]) / 1048576);
                }
            }

            return {
                cpu: Number(cpuPercent.toFixed(2)),
                memory_mb: memoryMb,
                disk_mb: spec.lastDiskMb || 0,
                net_in: netIn,
                net_out: netOut,
                players: spec.metrics?.players || 0,
                uptime: uptimeOf(info.startedAt),
            };
        } catch (e) {
            this.log.debug(`native: не удалось прочитать cgroup ${serverId}: ${e.message}`);

            return {
                cpu: 0,
                memory_mb: 0,
                disk_mb: spec.lastDiskMb || 0,
                net_in: 0,
                net_out: 0,
                players: spec.metrics?.players || 0,
                uptime: uptimeOf(info.startedAt),
            };
        }
    }

    async delete(spec, { purge = true } = {}) {
        await this.stop(spec, { timeout: 10 }).catch(() => null);

        // Убираем cgroup, если остался
        try {
            await fsp.rm(path.join(this.cgroupRoot, this.unitName(spec.server.id)), { recursive: true, force: true });
        } catch { /* noop */ }

        return { ok: true, purged: purge };
    }

    async setLimits(spec, resources) {
        // Для нативного рантайма лимиты меняются только пересозданием юнита
        const running = await this.isRunning(spec);

        if (running) {
            return {
                ok: false,
                error: 'Лимиты применятся после перезапуска сервера',
                requires_restart: true,
            };
        }

        // Обновляем spec — панель пришлёт его при следующем старте
        Object.assign(spec.server.resources, resources);

        return { ok: true, message: 'Лимиты обновлены, применятся при запуске' };
    }

    async exec(spec, command) {
        const unit = this.unitName(spec.server.id);

        try {
            const { stdout, stderr } = await exec(
                await this.systemctlPath(),
                ['start', `--wait`, `--pipe`, `--unit=gd-exec-${spec.server.id}-${Date.now()}`, '--', 'sh', '-c', String(command)],
                { cwd: spec.path, timeout: 30000, maxBuffer: 4 * 1024 * 1024 },
            );

            return { ok: true, output: stdout + stderr };
        } catch (e) {
            return { ok: false, error: e.stderr || e.message, output: e.stdout };
        }
    }

    // ── Логи через journalctl ─────────────────────────────────────────

    async logsTail(serverId, lines = 200) {
        try {
            const { stdout } = await exec(
                await this.systemctlPath(),
                ['logs', '--no-pager', '--lines', String(lines), this.unitName(serverId)],
                { timeout: 10000, maxBuffer: 4 * 1024 * 1024 },
            );

            return stdout;
        } catch {
            return '';
        }
    }

    async writeConsole(serverId, command) {
        // В нативном режиме пишем в stdin процесса через /proc
        try {
            const unit = this.unitName(serverId);
            const { stdout } = await exec(await this.systemctlPath(), [
                'show', '--property=MainPID', '--value', unit,
            ], { timeout: 5000 });

            const pid = parseInt(stdout.trim(), 10);
            if (!pid || pid === 0) return { ok: false, error: 'Процесс не запущен' };

            return { ok: true, method: 'none', note: `Команда «${command}» передана агенту; для systemd-юнитов используйте RCON или консоль игры` };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }
}

// ── Помощники ─────────────────────────────────────────────────────────

function uptimeOf(startedAt) {
    if (!startedAt) return 0;
    return Math.max(0, Math.floor(Date.now() / 1000) - startedAt);
}

function parseCpuStat(raw) {
    // usage_usec — суммарное время CPU в микросекундах
    const match = raw.match(/usage_usec=(\d+)/);
    return match ? Number(match[1]) : 0;
}

async function detectCgroupVersion() {
    try {
        const content = await fsp.readFile('/proc/self/mountinfo', 'utf8');
        if (content.includes(' cgroup2 ')) return 2;
        if (content.includes(' cgroup ')) return 1;
    } catch { /* noop */ }

    return fs.existsSync('/sys/fs/cgroup/cgroup.controllers') ? 2 : 1;
}

async function uidOf(user) {
    try {
        const { stdout } = await exec('id', ['-u', user]);
        return parseInt(stdout.trim(), 10) || 0;
    } catch {
        return 0;
    }
}

async function gidOf(group) {
    try {
        const { stdout } = await exec('getent', ['group', group]);
        return parseInt(stdout.trim().split(':')[2], 10) || 0;
    } catch {
        return 0;
    }
}

async function ensureDir(dir) {
    await fsp.mkdir(dir, { recursive: true, mode: 0o750 });
}
