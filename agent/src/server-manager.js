/**
 * Менеджер игровых серверов на ноде.
 *
 * Держит реестр серверов, следит за процессами, стримит консоль в панель,
 * собирает метрики, ловит падения и перезапускает (watchdog).
 */

import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { EventEmitter } from 'node:events';
import { query as gameQuery } from './query/index.js';
import { PathGuard, directorySize, ensureDir } from './utils/fsx.js';
import { buildRuntimeSpec } from './spec.js';

export class ServerManager extends EventEmitter {
    constructor(config, logger, runtimes) {
        super();

        this.config = config;
        this.log = logger;
        this.runtimes = runtimes;

        /** @type {Map<number, object>} */
        this.servers = new Map();
        this.consoleBuffers = new Map();
        this.pendingSecrets = new Set();
    }

    serverPath(serverId) {
        return path.join(this.config.serversRoot, String(serverId));
    }

    backupPath(serverId) {
        return path.join(this.config.backupsRoot, String(serverId));
    }

    get(serverId) {
        return this.servers.get(Number(serverId));
    }

    list() {
        return [...this.servers.values()];
    }

    runningCount() {
        return this.list().filter((s) => s.instance?.isRunning?.()).length;
    }

    /**
     * Регистрация сервера (после server.create от панели).
     */
    async register(raw) {
        const serverId = Number(raw.server.id);
        const dir = this.serverPath(serverId);

        await ensureDir(dir, 0o750);
        await ensureDir(this.backupPath(serverId), 0o700);

        // Панель присылает игру, стартовую команду, порты и лимиты внутри
        // payload.server, а драйверы читают их с верхнего уровня spec.*, и
        // каталог сервера знаем только мы. Приводим здесь — единственное
        // место, где путь известен.
        const spec = buildRuntimeSpec(raw, dir, this.config.nodeId);

        const instance = {
            spec,
            path: dir,
            runtime: this.runtimes.get(spec.server.runtime || this.config.runtime),
            consoleBuffer: [],
            lastStats: null,
            lastQuery: null,
            lastDiskMb: 0,
            startedAt: null,
            restarts: 0,
            restartHistory: [],
            lastStatus: 'pending',
            subscribers: new Set(),
        };

        this.servers.set(serverId, instance);
        this.consoleBuffers.set(serverId, []);

        this.log.info('Сервер зарегистрирован', {
            server_id: serverId, game: spec.game?.slug, runtime: spec.server.runtime, path: dir,
        });

        return { ok: true, server_id: serverId, path: dir };
    }

    unregister(serverId) {
        this.servers.delete(Number(serverId));
        this.consoleBuffers.delete(Number(serverId));
    }

    require(serverId) {
        const instance = this.get(serverId);

        if (!instance) {
            throw new ServerNotFoundError(`Сервер ${serverId} не зарегистрирован на этой ноде`);
        }

        return instance;
    }

    // ── Консоль ──────────────────────────────────────────────────────

    pushConsole(serverId, text, stream = 'stdout') {
        const lines = Array.isArray(text) ? text : String(text).split('\n');

        for (const rawLine of lines) {
            const line = {
                server_id: Number(serverId),
                stream,
                text: String(rawLine),
                ts: Math.floor(Date.now() / 1000),
            };

            const buffer = this.consoleBuffers.get(Number(serverId)) || [];
            buffer.push(line);

            if (buffer.length > this.config.limits.consoleBuffer) {
                buffer.splice(0, buffer.length - this.config.limits.consoleBuffer);
            }

            this.consoleBuffers.set(Number(serverId), buffer);

            this.emit('console', line);
        }
    }

    getConsole(serverId, limit = 200) {
        const buffer = this.consoleBuffers.get(Number(serverId)) || [];
        return buffer.slice(-limit);
    }

    subscribe(serverId, callback) {
        const instance = this.require(serverId);
        instance.subscribers.add(callback);

        return () => instance.subscribers.delete(callback);
    }

    emitConsole(serverId, line) {
        const instance = this.servers.get(Number(serverId));
        if (!instance) return;

        for (const callback of instance.subscribers) {
            try {
                callback(line);
            } catch { /* подписчик отвалился */ }
        }
    }

    // ── Питание ──────────────────────────────────────────────────────

    async start(serverId) {
        const instance = this.require(serverId);

        if (await instance.runtime.isRunning(instance.spec)) {
            this.setStatus(instance, 'running');
            return { ok: true, already_running: true };
        }

        // Создаём окружение, если его ещё нет
        const exists = fs.existsSync(path.join(instance.path, '.gamedock-ready'));

        if (!exists) {
            const created = await instance.runtime.create(instance.spec);
            if (!created.ok) {
                this.setStatus(instance, 'error', created.error);
                return { ok: false, error: created.error };
            }
        }

        const result = await instance.runtime.start(instance.spec);

        if (result.ok) {
            instance.startedAt = result.started_at || Math.floor(Date.now() / 1000);
            this.setStatus(instance, 'running');
            this.pushConsole(serverId, '── сервер запущен ──', 'system');
        } else {
            this.setStatus(instance, 'error', result.error);
        }

        return result;
    }

    async stop(serverId, options = {}) {
        const instance = this.require(serverId);

        const result = await instance.runtime.stop(instance.spec, options);

        if (result.ok) {
            instance.startedAt = null;
            this.setStatus(instance, 'stopped');
            this.pushConsole(serverId, '── сервер остановлен ──', 'system');
        }

        return result;
    }

    async restart(serverId) {
        const instance = this.require(serverId);
        instance.restarts++;

        this.pushConsole(serverId, '── перезапуск ──', 'system');

        return instance.runtime.restart(instance.spec);
    }

    async kill(serverId) {
        const instance = this.require(serverId);
        const result = await instance.runtime.kill(instance.spec);

        if (result.ok) {
            instance.startedAt = null;
            this.setStatus(instance, 'stopped');
            this.pushConsole(serverId, '── процесс принудительно завершён ──', 'system');
        }

        return result;
    }

    async delete(serverId, options = {}) {
        const instance = this.get(serverId);

        if (!instance) return { ok: true };

        try {
            await instance.runtime.delete(instance.spec, options);
        } catch (e) {
            this.log.warn('Ошибка удаления окружения:', e.message);
        }

        if (options.purge) {
            try {
                await fsp.rm(instance.path, { recursive: true, force: true });
                await fsp.rm(this.backupPath(serverId), { recursive: true, force: true });
            } catch (e) {
                this.log.warn('Ошибка удаления каталога:', e.message);
            }
        }

        this.unregister(serverId);
        this.log.info('Сервер удалён с ноды', { server_id: serverId });

        return { ok: true };
    }

    setStatus(instance, status, reason = null) {
        if (instance.lastStatus === status && !reason) return;

        instance.lastStatus = status;

        this.emit('status', {
            server_id: Number(instance.spec.server.id),
            status,
            reason,
        });
    }

    // ── Метрики ──────────────────────────────────────────────────────

    async collectMetrics(serverId) {
        const instance = this.require(serverId);

        try {
            const stats = await instance.runtime.stats(instance.spec);

            // Раз в минуту считаем занятый диск (это дорого)
            if (!instance.lastDiskAt || Date.now() - instance.lastDiskAt > 60000) {
                const bytes = await directorySize(instance.path);
                instance.lastDiskMb = Math.round(bytes / 1048576);
                instance.lastDiskAt = Date.now();
            }

            stats.disk_mb = instance.lastDiskMb;

            // Опрос игрового протокола
            const querySpec = instance.spec.startup?.query;
            if (querySpec?.type && instance.spec.node?.flagship) {
                const result = await gameQuery(
                    querySpec.type,
                    instance.spec.node.flagship,
                    instance.spec.ports?.query,
                );

                if (result.online) {
                    stats.players = result.players;
                    instance.lastQuery = { ...result, at: Date.now() };
                }
            }

            instance.lastStats = { ...stats, at: Date.now() };

            return instance.lastStats;
        } catch (e) {
            this.log.debug('Ошибка сбора метрик:', e.message);
            return instance.lastStats || { cpu: 0, memory_mb: 0, disk_mb: instance.lastDiskMb, net_in: 0, net_out: 0, players: 0, uptime: 0 };
        }
    }

    async status(serverId) {
        const instance = this.require(serverId);

        const running = await instance.runtime.isRunning(instance.spec);

        return {
            server_id: Number(serverId),
            status: running ? 'running' : instance.lastStatus,
            uptime: instance.startedAt ? Math.floor(Date.now() / 1000) - instance.startedAt : 0,
            restarts: instance.restarts,
        };
    }

    // ── Watchdog ─────────────────────────────────────────────────────

    /**
     * Проверяет все запущенные серверы: если процесс упал сам — перезапускает
     * (если включён watchdog и не превышен лимит рестартов).
     */
    async watchdog() {
        const watchdog = this.spec?.watchdog || {};
        const results = [];

        for (const instance of this.servers.values()) {
            const serverId = Number(instance.spec.server.id);

            if (!instance.spec.server.watchdog?.enabled) continue;
            if (!['running', 'starting'].includes(instance.lastStatus)) continue;

            let running;
            try {
                running = await instance.runtime.isRunning(instance.spec);
            } catch (e) {
                this.log.debug(`watchdog: не удалось проверить ${serverId}: ${e.message}`);
                continue;
            }

            if (running) continue;

            // Чистим историю рестартов
            const windowMs = (instance.spec.server.watchdog?.window_minutes || 15) * 60000;
            instance.restartHistory = instance.restartHistory.filter((t) => Date.now() - t < windowMs);

            const maxRestarts = instance.spec.server.watchdog?.max_restarts || 5;

            if (instance.restartHistory.length >= maxRestarts) {
                this.log.error(`watchdog: сервер ${serverId} превысил лимит рестартов (${maxRestarts})`);
                this.setStatus(instance, 'error', 'Превышен лимит авто-рестартов');

                this.emit('crashed', {
                    server_id: serverId,
                    reason: 'Превышен лимит авто-рестартов',
                    restarts: instance.restartHistory.length,
                });

                results.push({ server_id: serverId, action: 'give_up' });
                continue;
            }

            const delay = instance.spec.server.watchdog?.restart_delay || 10;

            this.log.warn(`watchdog: сервер ${serverId} упал, перезапуск через ${delay}с`);

            this.emit('crashed', {
                server_id: serverId,
                reason: 'Процесс завершился неожиданно',
                uptime: instance.startedAt ? Date.now() / 1000 - instance.startedAt : 0,
            });

            setTimeout(async () => {
                try {
                    await this.restart(serverId);
                    instance.restartHistory.push(Date.now());
                } catch (e) {
                    this.log.error(`watchdog: не удалось перезапустить ${serverId}: ${e.message}`);
                }
            }, delay * 1000);

            results.push({ server_id: serverId, action: 'restart' });
        }

        return results;
    }

    // ── Секретные коды ───────────────────────────────────────────────

    /**
     * Проверяет строки чата на секретные коды.
     * Агент не знает кодов — он просто спрашивает панель.
     */
    interceptChat(serverId, text, player = {}) {
        const prefixes = this.config.secretCodes.prefixes;

        const isCode = prefixes.some((prefix) => String(text).trim().startsWith(prefix));

        if (!isCode) return false;

        this.emit('chat', {
            server_id: Number(serverId),
            text: String(text),
            player_id: player.id || null,
            player_name: player.name || null,
        });

        return true;
    }

    /**
     * Отправляет сообщение в игровой чат (ответ на секретный код).
     */
    async say(serverId, message) {
        const instance = this.require(serverId);

        // У разных игр разный синтаксис broadcast
        const command = /^\{/.test(message)
            ? message
            : message;

        if (typeof instance.runtime.writeConsole === 'function') {
            return instance.runtime.writeConsole(instance.spec, command);
        }

        const node = instance.spec.node || {};

        return instance.runtime.exec(instance.spec, `echo ${JSON.stringify(command)}`);
    }
}

export class ServerNotFoundError extends Error {
    constructor(message) {
        super(message);
        this.name = 'ServerNotFoundError';
        this.code = 'server_not_found';
    }
}
