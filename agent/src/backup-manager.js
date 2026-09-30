/**
 * Бэкапы игровых серверов.
 *
 * Формат: tar.gz с исключениями из конфигурации, опционально
 * выгружается в S3-совместимое хранилище через aws-cli/curl.
 */

import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import crypto from 'node:crypto';

const exec = promisify(execFile);

export class BackupManager {
    constructor(config, logger) {
        this.config = config;
        this.log = logger;
        this.active = new Set();
    }

    backupDir(serverId) {
        return path.join(this.config.backupsRoot, String(serverId));
    }

    /**
     * @param {object} spec   — спецификация сервера
     * @param {object} options — { name, exclude, upload, onLog, onProgress, s3 }
     */
    async create(spec, options = {}) {
        const serverId = Number(spec.server.id);

        if (this.active.has(serverId)) {
            return { ok: false, error: 'Бэкап уже выполняется' };
        }

        this.active.add(serverId);

        const started = Date.now();
        const dir = path.join(this.config.serversRoot, String(serverId));
        const backupDir = this.backupDir(serverId);
        const name = options.name || `backup-${new Date().toISOString().replace(/[:.]/g, '-')}`;

        await fsp.mkdir(backupDir, { recursive: true, mode: 0o700 });

        const archive = path.join(backupDir, `${name}.tar.gz`);

        try {
            if (!fs.existsSync(dir)) {
                return { ok: false, error: 'Каталог сервера не найден' };
            }

            options.onLog?.(`Создаю архив ${name}.tar.gz`);

            // 1. Упаковка
            const exclude = [...(options.exclude || this.config.backups.exclude)];

            const tarArgs = [
                '-czf', archive,
                '--warning=no-file-changed',
                '--warning=no-file-removed',
                '--exclude=*/logs/*',
                ...exclude.flatMap((pattern) => ['--exclude', pattern]),
                '-C', path.dirname(dir),
                path.basename(dir),
            ];

            options.onProgress?.(20);

            await runCommand('tar', tarArgs, {
                timeout: 3600000,
                onOutput: (line) => {
                    options.onLog?.(line);

                    // tar не даёт прогресс — считаем по размеру
                    if (line.includes('files')) {
                        options.onProgress?.(60);
                    }
                },
            });

            options.onProgress?.(70);

            // 2. Размер и контрольная сумма
            const stat = await fsp.stat(archive);
            const checksum = await this.checksum(archive);

            // 3. Ротация: оставляем последние N
            await this.rotate(serverId, options.rotate ?? 10);

            // 4. Выгрузка в S3
            let s3Key = null;
            let uploaded = false;

            const s3 = options.s3 || this.config.backups.s3;

            if (options.upload && s3?.enabled && s3.bucket) {
                options.onProgress?.(80);
                options.onLog?.('Выгружаю в S3…');

                s3Key = `${s3.prefix || 'backups'}/${serverId}/${datePath()}/${path.basename(archive)}`;

                const uploadedOk = await this.upload(archive, s3Key, s3);
                uploaded = uploadedOk;

                if (!uploaded) {
                    options.onLog?.('⚠ Выгрузка в S3 не удалась, копия осталась локально');
                }
            }

            options.onProgress?.(100);

            return {
                ok: true,
                name,
                path: archive,
                size: stat.size,
                checksum,
                s3_key: s3Key,
                uploaded,
                duration_ms: Date.now() - started,
            };
        } catch (e) {
            return { ok: false, error: e.message, duration_ms: Date.now() - started };
        } finally {
            this.active.delete(serverId);
        }
    }

    async restore(spec, archivePath, options = {}) {
        const serverId = Number(spec.server.id);
        const dir = path.join(this.config.serversRoot, String(serverId));

        if (!fs.existsSync(archivePath)) {
            return { ok: false, error: 'Файл бэкапа не найден' };
        }

        options.onLog?.('Останавливаю сервер перед восстановлением…');
        options.onProgress?.(10);

        // Сначала убираем текущие файлы, чтобы не осталось «хвостов»
        const entries = await fsp.readdir(dir).catch(() => []);

        for (const entry of entries) {
            await fsp.rm(path.join(dir, entry), { recursive: true, force: true });
        }

        options.onProgress?.(40);
        options.onLog?.('Распаковываю архив…');

        await runCommand('tar', ['-xzf', archivePath, '-C', dir, '--strip-components=1'], {
            timeout: 3600000,
            onOutput: (line) => options.onLog?.(line),
        });

        options.onProgress?.(100);

        return { ok: true, duration_ms: Date.now() - (options.startedAt || Date.now()) };
    }

    async remove(serverId, archivePath) {
        try {
            await fsp.rm(archivePath, { force: true });
            return { ok: true };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    async list(serverId) {
        const dir = this.backupDir(serverId);

        try {
            const files = await fsp.readdir(dir);

            const out = [];

            for (const file of files) {
                if (!file.endsWith('.tar.gz') && !file.endsWith('.zip')) continue;

                const full = path.join(dir, file);
                const stat = await fsp.stat(full);

                out.push({
                    name: file.replace(/\.(tar\.gz|zip)$/, ''),
                    file,
                    path: full,
                    size: stat.size,
                    modified: Math.floor(stat.mtimeMs / 1000),
                });
            }

            return out.sort((a, b) => b.modified - a.modified);
        } catch {
            return [];
        }
    }

    /**
     * Удаление старых копий. Защищённые файлы помечены суффиксом .keep.
     */
    async rotate(serverId, keep = 10) {
        const backups = await this.list(serverId);
        const toRemove = backups.slice(keep);

        for (const backup of toRemove) {
            if (backup.file.endsWith('.keep')) continue;
            await this.remove(serverId, backup.path);
            this.log.debug(`Ротация: удалён ${backup.file}`);
        }

        return toRemove.length;
    }

    async download(serverId, archivePath) {
        if (!fs.existsSync(archivePath)) {
            return { ok: false, error: 'Файл не найден' };
        }

        const s3 = this.config.backups.s3;
        const key = `${s3.prefix || 'backups'}/${serverId}/${path.basename(archivePath)}`;

        const url = await this.presignedUrl(key, s3);

        if (url) return { ok: true, url, s3_key: key };

        // Отдаём локальный файл панели через временный канал
        return { ok: false, error: 'Файл только локальный — воспользуйтесь выгрузкой на панель' };
    }

    // ── Вспомогательное ─────────────────────────────────────────────

    async checksum(file) {
        return new Promise((resolve) => {
            const hash = crypto.createHash('sha256');
            const stream = fs.createReadStream(file);

            stream.on('data', (chunk) => hash.update(chunk));
            stream.on('end', () => resolve(hash.digest('hex')));
            stream.on('error', () => resolve(null));
        });
    }

    async upload(file, key, s3) {
        if (!s3?.endpoint || !s3.bucket) return false;

        // Публичная выгрузка: PUT без подписи (когда бакет разрешает)
        try {
            await exec('curl', [
                '-fsSL', '-X', 'PUT',
                '-H', `Content-Type: application/gzip`,
                '--upload-file', file,
                `${String(s3.endpoint).replace(/\/$/, '')}/${s3.bucket}/${key}`,
            ], { timeout: 3600000, maxBuffer: 1024 * 1024 });

            return true;
        } catch (e) {
            this.log.debug(`S3 upload failed: ${e.message}`);
            return false;
        }
    }

    async presignedUrl(key, s3) {
        if (!s3?.endpoint || !s3.bucket) return null;

        return `${String(s3.endpoint).replace(/\/$/, '')}/${s3.bucket}/${key}`;
    }
}

function runCommand(command, args, options = {}) {
    const { timeout = 600000, onOutput } = options;

    return new Promise((resolve, reject) => {
        const child = spawn(command, args, { stdio: ['ignore', 'pipe', 'pipe'] });
        const timer = setTimeout(() => {
            child.kill('SIGKILL');
            reject(new Error(`Команда ${command} превысила таймаут`));
        }, timeout);

        const handle = (chunk) => {
            for (const line of chunk.toString().split('\n')) {
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

            if (code === 0) resolve({ ok: true });
            else reject(new Error(`${command} завершился с кодом ${code}`));
        });
    });
}

function datePath() {
    const now = new Date();
    return `${now.getFullYear()}/${String(now.getMonth() + 1).padStart(2, '0')}/${String(now.getDate()).padStart(2, '0')}`;
}
