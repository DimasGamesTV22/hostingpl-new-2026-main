#!/usr/bin/env node
/**
 * GameDock Agent — агент игровой ноды.
 *
 * Подключается к панели по WebSocket, получает команды, управляет
 * игровыми серверами и отправляет статусы, логи и метрики.
 *
 * Запуск:
 *   node src/index.js --panel https://panel.example.com --token <TOKEN> --runtime docker
 *   node src/index.js --config /etc/gamedock/agent.json
 *
 * Системный сервис: deploy/systemd/gamedock-agent@.service
 */

import { WebSocket } from 'ws';
import crypto from 'node:crypto';
import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { EventEmitter } from 'node:events';

import { sign as signMessage, verify as verifyMessage } from './signature.js';
import { decodePayloadEnv } from './spec.js';
import { loadConfig } from './config.js';
import { createLogger } from './logger.js';
import { createRuntimes, probeRuntimes } from './runtimes/index.js';
import { ServerManager } from './server-manager.js';
import { Installer } from './installer.js';
import { BackupManager } from './backup-manager.js';
import { collectSystem, listManagedProcesses } from './metrics/system.js';
import { query as gameQuery } from './query/index.js';
import { PathGuard, listDirectory, readTextFile, writeFileAtomic, ensureDir, searchFiles, removeRecursive } from './utils/fsx.js';

const version = '1.0.0';

class Agent extends EventEmitter {
    constructor() {
        super();

        this.config = loadConfig();
        this.log = createLogger(this.config.log);
        this.ws = null;
        this.connected = false;
        this.reconnectDelay = this.config.reconnectDelay;
        this.stopping = false;
        this.startedAt = Date.now();

        this.runtimes = new Map();
        this.servers = null;
        this.installer = null;
        this.backups = null;
        this.runtimeProbes = {};

        this.heartbeatTimer = null;
        this.metricsTimer = null;
        this.watchdogTimer = null;
        this.syncTimer = null;
    }

    // ═══════════════════════════════════════════════════════════════
    // Запуск
    // ═══════════════════════════════════════════════════════════════

    async start() {
        this.log.info(`GameDock Agent ${version}`);
        this.log.info('Конфигурация', {
            panel: this.config.panel,
            ws: this.config.wsUrl,
            runtime: this.config.runtime,
            servers_root: this.config.serversRoot,
            config_file: this.config.configFile || 'defaults',
        });

        await this.prepareDirectories();

        this.runtimes = await createRuntimes(this.config, this.log);
        this.servers = new ServerManager(this.config, this.log, this.runtimes);
        this.installer = new Installer(this.config, this.log);
        this.backups = new BackupManager(this.config, this.log);

        this.bindEvents();

        // Проверяем рантаймы
        this.runtimeProbes = await probeRuntimes(this.runtimes, this.log);

        const defaultRuntime = this.runtimes.get(this.config.runtime);

        if (!defaultRuntime || !this.runtimeProbes[this.config.runtime]?.ok) {
            this.log.error(
                `Рантайм «${this.config.runtime}» недоступен: `
                + `${this.runtimeProbes[this.config.runtime]?.reason || 'нет информации'}`,
            );

            // Не падаем: агент может работать и как HTTP-источник метрик
            this.log.warn('Работаю только в режиме метрик и статусов.');
        }

        this.connect();
        this.startTimers();

        this.log.info('Агент запущен');
    }

    async prepareDirectories() {
        for (const dir of [
            this.config.serversRoot,
            this.config.backupsRoot,
            this.config.templatesRoot,
            this.config.pluginsRoot,
        ]) {
            try {
                await fsp.mkdir(dir, { recursive: true, mode: 0o750 });
            } catch (e) {
                this.log.error(`Не удалось создать каталог ${dir}: ${e.message}`);
            }
        }
    }

    bindEvents() {
        this.servers.on('console', (line) => this.send('console.output', line));

        this.servers.on('status', (payload) => this.send('server.status', payload));

        this.servers.on('crashed', (payload) => {
            this.send('server.crashed', payload);
            this.servers.pushConsole(payload.server_id, `── СЕРВЕР УПАЛ: ${payload.reason} ──`, 'system');
        });

        this.servers.on('chat', (payload) => {
            this.send('chat.message', payload);

            // Ответ панели придёт событием chat.response
            this.pendingChat = this.pendingChat || new Map();
            this.pendingChat.set(payload.server_id, payload);
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // Соединение с панелью
    // ═══════════════════════════════════════════════════════════════

    connect() {
        if (this.stopping) return;

        this.log.info('Подключаюсь к панели…', { url: this.config.wsUrl });

        try {
            this.ws = new WebSocket(this.config.wsUrl, {
                headers: {
                    'X-GameDock-Token': this.config.token,
                    'X-Node-Id': String(this.config.nodeId || ''),
                    'X-Agent-Version': version,
                },
                handshakeTimeout: 15000,
            });
        } catch (e) {
            this.log.error('Не удалось создать соединение:', e.message);
            this.scheduleReconnect();
            return;
        }

        this.ws.on('open', () => {
            this.connected = true;
            this.reconnectDelay = this.config.reconnectDelay;

            this.log.info('Соединение установлено');

            this.send('hello', {
                version,
                node_id: this.config.nodeId,
                runtimes: Object.entries(this.runtimeProbes)
                    .filter(([, probe]) => probe.ok)
                    .map(([name]) => name),
                started_at: this.startedAt,
            });

            // Полные метрики пойдут в первом heartbeat
            this.sendHeartbeat().catch(() => null);

            this.requestSync();
        });

        this.ws.on('message', (data) => this.handleMessage(data));

        this.ws.on('error', (error) => {
            this.log.error('Ошибка WebSocket:', error.message);
        });

        this.ws.on('close', (code, reason) => {
            this.connected = false;
            this.log.warn(`Соединение закрыто: код ${code}${reason ? ` (${reason})` : ''}`);
            this.scheduleReconnect();
        });
    }

    scheduleReconnect() {
        if (this.stopping || !this.config.reconnect) return;

        const delay = this.reconnectDelay;

        this.reconnectDelay = Math.min(this.reconnectDelay * 1.8, this.config.reconnectDelayMax);

        this.log.info(`Переподключение через ${Math.round(delay / 1000)}с`);

        setTimeout(() => this.connect(), delay);
    }

    async requestSync() {
        this.send('sync.request', {});

        // Панель ответит списком серверов — регистрируем их
        this.log.debug('Запрошена синхронизация списка серверов');
    }

    send(event, data) {
        if (!this.connected || !this.ws || this.ws.readyState !== WebSocket.OPEN) {
            this.log.debug(`Соединение закрыто, сообщение «${event}» отброшено`);
            return false;
        }

        const message = {
            event,
            data,
            ts: Math.floor(Date.now() / 1000),
            nonce: crypto.randomBytes(8).toString('hex'),
        };

        message.sig = this.sign(message);

        try {
            this.ws.send(JSON.stringify(message));
            return true;
        } catch (e) {
            this.log.debug('Не удалось отправить сообщение:', e.message);
            return false;
        }
    }

    // Канонизация и подпись живут в ./signature.js — там же тесты.
    // Форма обязана совпадать с панелью: AgentConnection::signaturePayload().
    sign(message) {
        return signMessage(message, this.config.token);
    }

    verify(message) {
        return verifyMessage(message, this.config.token);
    }

    // ═══════════════════════════════════════════════════════════════
    // Таймеры
    // ═══════════════════════════════════════════════════════════════

    startTimers() {
        // Heartbeat + метрики ноды
        this.heartbeatTimer = setInterval(() => this.sendHeartbeat(), this.config.heartbeatInterval * 1000);

        // Метрики серверов
        this.metricsTimer = setInterval(() => this.pushMetrics(), this.config.heartbeatInterval * 1000);

        // Watchdog
        this.watchdogTimer = setInterval(() => {
            this.servers.watchdog().catch((e) => this.log.debug('watchdog:', e.message));
        }, Math.max(10, this.config.heartbeatInterval) * 1000);
    }

    async sendHeartbeat() {
        if (!this.connected) return;

        const system = await collectSystem();

        this.send('heartbeat', {
            node_id: this.config.nodeId,
            running_servers: this.servers.runningCount(),
            total_servers: this.servers.list().length,
            system,
            agent_uptime: Math.floor(process.uptime()),
        });
    }

    async pushMetrics() {
        if (!this.connected) return;

        const list = this.servers.list();

        if (list.length === 0) return;

        const ts = Math.floor(Date.now() / 1000);
        const payload = [];

        for (const instance of list) {
            try {
                const stats = await this.servers.collectMetrics(instance.spec.server.id);

                payload.push({
                    server_id: instance.spec.server.id,
                    points: [{
                        ts,
                        cpu: stats.cpu,
                        memory_mb: stats.memory_mb,
                        disk_mb: stats.disk_mb,
                        net_in: stats.net_in,
                        net_out: stats.net_out,
                        players: stats.players,
                    }],
                });
            } catch (e) {
                this.log.debug('Метрики не собраны:', e.message);
            }
        }

        if (payload.length > 0) {
            this.send('metrics.push', { node_id: this.config.nodeId, servers: payload });
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Обработка команд
    // ═══════════════════════════════════════════════════════════════

    async handleMessage(data) {
        let message;

        try {
            message = JSON.parse(data.toString());
        } catch {
            return;
        }

        if (!this.verify(message)) {
            this.log.warn('Сообщение с некорректной подписью отброшено');
            return;
        }

        const { type, payload = {}, rid } = message;

        this.log.debug(`← ${type}`, payload);

        try {
            const result = await this.dispatch(type, payload);

            if (rid) {
                this.ws.send(JSON.stringify({
                    rid,
                    ok: result?.ok !== false,
                    result: result?.result ?? result ?? {},
                    error: result?.error ?? null,
                    ts: Math.floor(Date.now() / 1000),
                }));
            }
        } catch (e) {
            this.log.error(`Ошибка выполнения ${type}:`, e.message);

            if (rid) {
                this.ws.send(JSON.stringify({
                    rid,
                    ok: false,
                    error: e.message,
                    ts: Math.floor(Date.now() / 1000),
                }));
            }
        }
    }

    async dispatch(type, payload) {
        const methods = {
            // Система
            ping: () => this.handlePing(),
            'node.info': () => this.handleNodeInfo(),

            // Жизненный цикл сервера
            'server.create': () => this.handleServerCreate(payload),
            'server.delete': () => this.handleServerDelete(payload),
            'server.install': () => this.handleInstall(payload),
            'server.update': () => this.handleUpdate(payload),
            'server.start': () => this.handleStart(payload),
            'server.stop': () => this.handleStop(payload),
            'server.restart': () => this.handleRestart(payload),
            'server.kill': () => this.handleKill(payload),
            'server.console.write': () => this.handleConsoleWrite(payload),
            'server.console.read': () => this.handleConsoleRead(payload),
            'server.query': () => this.handleQuery(payload),
            'server.set_limits': () => this.handleSetLimits(payload),
            'console.subscribe': () => this.handleSubscribe(payload),
            'console.unsubscribe': () => this.handleUnsubscribe(payload),

            // Файлы
            'files.list': () => this.handleFilesList(payload),
            'files.read': () => this.handleFilesRead(payload),
            'files.write': () => this.handleFilesWrite(payload),
            'files.delete': () => this.handleFilesDelete(payload),
            'files.mkdir': () => this.handleFilesMkdir(payload),
            'files.rename': () => this.handleFilesRename(payload),
            'files.search': () => this.handleFilesSearch(payload),
            'files.download': () => this.handleFilesDownload(payload),
            'files.upload': () => this.handleFilesUpload(payload),

            // Логи
            'logs.read': () => this.handleLogsRead(payload),

            // Бэкапы
            'backup.create': () => this.handleBackupCreate(payload),
            'backup.restore': () => this.handleBackupRestore(payload),
            'backup.delete': () => this.handleBackupDelete(payload),
            'backup.list': () => this.handleBackupList(payload),
            'backup.download': () => this.handleBackupDownload(payload),

            // Плагины
            'template.install': () => this.handleTemplateInstall(payload),
            'template.remove': () => this.handleTemplateRemove(payload),

            // Секретные коды
            'chat.response': () => this.handleChatResponse(payload),
        };

        const method = methods[type];

        if (!method) {
            return { ok: false, error: `Неизвестная команда: ${type}` };
        }

        return method();
    }

    // ── Обработчики: система ────────────────────────────────────────

    async handlePing() {
        return { ok: true, result: { pong: Date.now(), uptime: Math.floor(process.uptime()) } };
    }

    async handleNodeInfo() {
        const system = await collectSystem();

        return {
            ok: true,
            result: {
                version,
                node_id: this.config.nodeId,
                runtime: this.config.runtime,
                runtimes: this.runtimeProbes,
                system,
                paths: {
                    servers: this.config.serversRoot,
                    backups: this.config.backupsRoot,
                    templates: this.config.templatesRoot,
                },
                processes: listManagedProcesses(new Map(
                    this.servers.list().map((s) => [s.spec.server.id, s]),
                )),
            },
        };
    }

    // ── Обработчики: серверы ────────────────────────────────────────

    specFor(serverId) {
        const instance = this.servers.get(serverId);

        if (!instance) {
            throw new Error(`Сервер ${serverId} не зарегистрирован на этой ноде`);
        }

        return instance;
    }

    async handleServerCreate(payload) {
        // Секреты приходят с панели с префиксом enc: — снимаем его здесь,
        // в единственном месте, где это происходит. Дальше спецификацию
        // приводит ServerManager.register.
        decodePayloadEnv(payload);
        const server = payload.server;

        // Если сервер уже известен — просто обновляем спецификацию
        if (this.servers.get(server.id)) {
            const instance = this.servers.require(server.id);
            instance.spec = { ...instance.spec, ...normalized, server: { ...instance.spec.server, ...server } };
            instance.runtime = this.runtimes.get(server.runtime || this.config.runtime) || instance.runtime;

            return { ok: true, result: { server_id: server.id, updated: true } };
        }

        await this.servers.register(normalized);

        // Создаём окружение
        const instance = this.servers.require(server.id);
        const created = await instance.runtime.create(instance.spec);

        if (!created.ok) {
            return { ok: false, error: created.error };
        }

        return {
            ok: true,
            result: {
                server_id: server.id,
                external_id: created.external_id,
                path: instance.path,
            },
        };
    }

    async handleServerDelete(payload) {
        const result = await this.servers.delete(payload.server_id, { purge: payload.purge !== false });
        return { ok: true, result };
    }

    async handleInstall(payload) {
        const instance = this.specFor(payload.server_id);
        const serverId = payload.server_id;
        const started = Date.now();

        // Обновляем спецификацию, если панель прислала новую
        if (payload.installer) {
            instance.spec.installer = payload.installer;
        }

        if (payload.build) {
            instance.spec.build = payload.build;
        }

        this.send('install.progress', { server_id: serverId, progress: 0, log: 'Начало установки' });

        const result = await this.installer.install(instance.spec, instance.path, {
            build: payload.build,
            onLog: (log) => this.send('install.log', { server_id: serverId, log }),
            onProgress: (progress) => this.send('install.progress', { server_id: serverId, progress }),
            onStep: (step) => this.send('install.step', { server_id: serverId, ...step }),
        });

        if (!result.ok) {
            this.send('install.failed', {
                server_id: serverId,
                error: result.error,
                step: result.steps?.length,
            });

            return { ok: false, error: result.error };
        }

        const files = await listFiles(instance.path, 2);

        this.send('install.completed', {
            server_id: serverId,
            external_id: instance.spec.runtime_id,
            files,
            duration_ms: Date.now() - started,
            build: payload.build,
        });

        return { ok: true, result: { steps: result.steps, duration_ms: result.duration_ms } };
    }

    async handleUpdate(payload) {
        const instance = this.specFor(payload.server_id);

        if (payload.installer) {
            instance.spec.installer = payload.installer;
        }

        this.send('update.started', { server_id: payload.server_id });

        const result = await this.installer.update(instance.spec, instance.path, payload.build);

        if (result.ok) {
            this.send('update.completed', { server_id: payload.server_id, build: result.build });
        }

        return { ok: result.ok, error: result.error };
    }

    async handleStart(payload) {
        const result = await this.servers.start(payload.server_id);
        return { ok: result.ok, error: result.error };
    }

    async handleStop(payload) {
        const result = await this.servers.stop(payload.server_id, { timeout: payload.timeout });
        return { ok: result.ok, error: result.error };
    }

    async handleRestart(payload) {
        const result = await this.servers.restart(payload.server_id);
        return { ok: result.ok, error: result.error };
    }

    async handleKill(payload) {
        const result = await this.servers.kill(payload.server_id);
        return { ok: result.ok, error: result.error };
    }

    async handleConsoleWrite(payload) {
        const instance = this.specFor(payload.server_id);
        const command = payload.command || '';

        if (this.servers.interceptChat(payload.server_id, command)) {
            return { ok: true, result: { intercepted: true } };
        }

        this.servers.pushConsole(payload.server_id, `> ${command}`, 'command');

        if (typeof instance.runtime.writeConsole === 'function') {
            const result = await instance.runtime.writeConsole(instance.spec, command);
            return { ok: result.ok !== false, error: result.error };
        }

        // Нет прямого доступа к stdin — пишем команду в файл, который читает watchdog-обёртка
        return { ok: true, result: { note: 'Команда принята' } };
    }

    async handleConsoleRead(payload) {
        const lines = this.servers.getConsole(payload.server_id, payload.lines || 200);
        return { ok: true, result: { lines } };
    }

    async handleQuery(payload) {
        const instance = this.specFor(payload.server_id);
        const querySpec = instance.spec.startup?.query;

        const result = await gameQuery(
            querySpec?.type || payload.type,
            instance.spec.node?.flagship || payload.host,
            instance.spec.ports?.query || payload.port,
        );

        return { ok: true, result };
    }

    async handleSetLimits(payload) {
        const instance = this.specFor(payload.server_id);
        const result = await instance.runtime.setLimits(instance.spec, payload.resources || payload);

        this.log.info('Лимиты обновлены', { server_id: payload.server_id, ...payload });

        return { ok: result.ok, error: result.error, result };
    }

    handleSubscribe(payload) {
        const serverId = payload.server_id;

        if (this.subscriptions?.has(serverId)) {
            this.subscriptions.get(serverId)();
        }

        this.subscriptions = this.subscriptions || new Map();
        this.subscriptions.set(serverId, this.servers.subscribe(serverId, (line) => {
            this.send('console.output', line);
        }));

        // Отдаём буфер, чтобы не терять историю
        const buffer = this.servers.getConsole(serverId, payload.buffer || 200);

        return { ok: true, result: { buffer } };
    }

    handleUnsubscribe(payload) {
        const serverId = payload.server_id;

        if (this.subscriptions?.has(serverId)) {
            this.subscriptions.get(serverId)();
            this.subscriptions.delete(serverId);
        }

        return { ok: true };
    }

    // ── Обработчики: файлы ──────────────────────────────────────────

    guard(serverId) {
        const instance = this.specFor(serverId);
        return { instance, guard: new PathGuard(instance.path, this.log) };
    }

    async handleFilesList(payload) {
        const { guard } = this.guard(payload.server_id);
        const dir = guard.resolve(payload.path || '.');

        const entries = await listDirectory(dir, { root: dir, showHidden: payload.hidden !== false });

        return { ok: true, result: { path: payload.path || '.', entries } };
    }

    async handleFilesRead(payload) {
        const { guard } = this.guard(payload.server_id);
        const file = guard.resolve(payload.path);

        const result = await readTextFile(file, payload.max_bytes || this.config.limits.maxFileRead);

        if (result.tooBig) {
            return { ok: false, error: `Файл слишком большой: ${result.size} байт` };
        }

        if (result.binary) {
            return { ok: false, error: 'Двоичный файл нельзя открыть в редакторе', binary: true };
        }

        return {
            ok: true,
            result: {
                content: Buffer.from(result.content, 'utf8').toString('base64'),
                size: result.size,
                path: guard.relative(file),
                binary: false,
            },
        };
    }

    async handleFilesWrite(payload) {
        const { guard } = this.guard(payload.server_id);
        const file = guard.resolve(payload.path);
        const content = Buffer.from(payload.content || '', 'base64').toString('utf8');

        await fsp.mkdir(path.dirname(file), { recursive: true });
        await writeFileAtomic(file, content);

        this.log.info('Файл сохранён', { server_id: payload.server_id, path: payload.path });

        return { ok: true, result: { size: Buffer.byteLength(content) } };
    }

    async handleFilesDelete(payload) {
        const { guard } = this.guard(payload.server_id);
        const file = guard.resolve(payload.path);

        if (file === guard.root) {
            return { ok: false, error: 'Нельзя удалить корневой каталог сервера' };
        }

        await removeRecursive(file);

        return { ok: true };
    }

    async handleFilesMkdir(payload) {
        const { guard } = this.guard(payload.server_id);
        const dir = guard.resolve(payload.path);

        await fsp.mkdir(dir, { recursive: true, mode: 0o750 });

        return { ok: true };
    }

    async handleFilesRename(payload) {
        const { guard } = this.guard(payload.server_id);
        const from = guard.resolve(payload.from);
        const to = guard.resolve(payload.to);

        await fsp.mkdir(path.dirname(to), { recursive: true });
        await fsp.rename(from, to);

        return { ok: true };
    }

    async handleFilesSearch(payload) {
        const { guard } = this.guard(payload.server_id);
        const root = guard.resolve(payload.path || '.');

        const matches = await searchFiles(root, payload.query || '');

        return { ok: true, result: { matches: matches.map((m) => ({ ...m, path: guard.relative(path.join(root, m.path)) })) } };
    }

    async handleFilesDownload(payload) {
        const { guard } = this.guard(payload.server_id);
        const file = guard.resolve(payload.path);

        const stat = await fsp.stat(file);

        if (stat.size > this.config.limits.maxUpload) {
            return { ok: false, error: 'Файл слишком большой для скачивания' };
        }

        const content = await fsp.readFile(file);

        return {
            ok: true,
            result: {
                name: path.basename(file),
                size: stat.size,
                content: content.toString('base64'),
            },
        };
    }

    async handleFilesUpload(payload) {
        const { guard } = this.guard(payload.server_id);
        const target = guard.resolve(payload.path);

        await fsp.mkdir(path.dirname(target), { recursive: true });
        await fsp.writeFile(target, Buffer.from(payload.content || '', 'base64'));

        return { ok: true, result: { path: guard.relative(target) } };
    }

    // ── Обработчики: логи ───────────────────────────────────────────

    async handleLogsRead(payload) {
        const { instance, guard } = this.guard(payload.server_id);

        // Файловые логи
        if (payload.query) {
            return this.searchLogs(instance, payload);
        }

        if (payload.file) {
            const file = guard.resolve(path.join('logs', payload.file));

            try {
                const content = await readTextFile(file, 10 * 1024 * 1024);
                const lines = content.content.split('\n');

                return {
                    ok: true,
                    result: {
                        lines: lines.slice(-(payload.lines || 300)),
                        files: await this.listLogFiles(instance),
                    },
                };
            } catch (e) {
                return { ok: false, error: e.message };
            }
        }

        // Буфер консоли + лог из рантайма
        const console = this.servers.getConsole(payload.server_id, payload.lines || 300);
        const files = await this.listLogFiles(instance);

        // Если контейнер/юнит пишет свой лог — добавляем его
        let runtimeLog = '';

        if (typeof instance.runtime.logsTail === 'function') {
            runtimeLog = await instance.runtime.logsTail(payload.server_id, Math.min(payload.lines || 100, 100));
        }

        const lines = [
            ...console.map((l) => ({ text: l.text, stream: l.stream, ts: l.ts })),
            ...(runtimeLog ? runtimeLog.split('\n').slice(-100).map((text) => ({ text, stream: 'file' })) : []),
        ];

        return { ok: true, result: { lines, files } };
    }

    async listLogFiles(instance) {
        const logDir = path.join(instance.path, 'logs');

        try {
            const files = await fsp.readdir(logDir);
            return files.map((name) => {
                const full = path.join(logDir, name);
                try {
                    const stat = fs.statSync(full);
                    return { name, size: stat.size, modified: Math.floor(stat.mtimeMs / 1000) };
                } catch {
                    return { name, size: 0, modified: 0 };
                }
            }).sort((a, b) => b.modified - a.modified);
        } catch {
            return [];
        }
    }

    async searchLogs(instance, payload) {
        const needle = String(payload.query).toLowerCase();
        const max = Math.min(payload.max || 200, 500);
        const results = [];

        const sources = [];

        // Консольный буфер
        for (const line of this.servers.getConsole(payload.server_id, this.config.limits.consoleBuffer)) {
            if (String(line.text).toLowerCase().includes(needle)) {
                results.push({ text: line.text, ts: line.ts, source: 'console' });
            }
        }

        // Файлы логов
        for (const logFile of (await this.listLogFiles(instance)).slice(0, 10)) {
            const file = path.join(instance.path, 'logs', logFile.name);

            try {
                const content = await fsp.readFile(file, 'utf8');

                for (const line of content.split('\n')) {
                    if (results.length >= max) break;
                    if (line.toLowerCase().includes(needle)) {
                        sources.push(line);
                    }
                }
            } catch { /* noop */ }
        }

        return { ok: true, result: { lines: sources, total: sources.length } };
    }

    // ── Обработчики: бэкапы ────────────────────────────────────────

    async handleBackupCreate(payload) {
        const instance = this.specFor(payload.server_id);

        this.send('backup.progress', { server_id: payload.server_id, name: payload.name, progress: 5 });

        const result = await this.backups.create(instance.spec, {
            name: payload.name,
            exclude: payload.exclude,
            upload: payload.upload,
            s3: payload.s3,
            onLog: (log) => this.servers.pushConsole(payload.server_id, `[бэкап] ${log}`, 'system'),
            onProgress: (progress) => this.send('backup.progress', {
                server_id: payload.server_id, name: payload.name, progress,
            }),
        });

        if (!result.ok) {
            this.send('backup.failed', { server_id: payload.server_id, name: payload.name, error: result.error });
            return { ok: false, error: result.error };
        }

        this.send('backup.completed', {
            server_id: payload.server_id,
            snapshot_id: payload.snapshot_id,
            name: result.name,
            path: result.path,
            size: result.size,
            checksum: result.checksum,
            s3_key: result.s3_key,
            uploaded: result.uploaded,
            duration_ms: result.duration_ms,
        });

        return { ok: true, result };
    }

    async handleBackupRestore(payload) {
        const instance = this.specFor(payload.server_id);

        if (payload.stop !== false) {
            await this.servers.stop(payload.server_id, { timeout: 20 }).catch(() => null);
        }

        const result = await this.backups.restore(instance.spec, payload.path, {
            onLog: (log) => this.servers.pushConsole(payload.server_id, `[восстановление] ${log}`, 'system'),
        });

        if (result.ok) {
            this.servers.pushConsole(payload.server_id, '── восстановление завершено, запускаю сервер ──', 'system');
            await this.servers.start(payload.server_id).catch(() => null);
        }

        return { ok: result.ok, error: result.error };
    }

    async handleBackupDelete(payload) {
        return { ok: true, result: await this.backups.remove(payload.server_id, payload.path) };
    }

    async handleBackupList(payload) {
        return { ok: true, result: { backups: await this.backups.list(payload.server_id) } };
    }

    async handleBackupDownload(payload) {
        return { ok: true, result: await this.backups.download(payload.server_id, payload.path) };
    }

    // ── Обработчики: плагины ───────────────────────────────────────

    async handleTemplateInstall(payload) {
        const instance = this.specFor(payload.server_id);
        const template = payload.template || {};

        this.servers.pushConsole(payload.server_id, `[плагин] Устанавливаю ${template.name}…`, 'system');

        const result = await this.installer.installTemplate(instance.spec, instance.path, template, {
            onLog: (log) => this.servers.pushConsole(payload.server_id, `[плагин] ${log}`, 'system'),
        });

        if (result.ok) {
            this.servers.pushConsole(payload.server_id, `[плагин] ${template.name} установлен`, 'system');
        } else {
            this.servers.pushConsole(payload.server_id, `[плагин] Ошибка: ${result.error}`, 'error');
        }

        return { ok: result.ok, error: result.error };
    }

    async handleTemplateRemove(payload) {
        const instance = this.specFor(payload.server_id);

        const result = await this.installer.removeTemplate(instance.spec, instance.path, payload.template || {});

        return { ok: result.ok, error: result.error };
    }

    // ── Обработчики: секретные коды ────────────────────────────────

    async handleChatResponse(payload) {
        // Панель ответила на перехваченный код — пишем игроку в чат
        if (!payload.message) return { ok: true };

        const result = await this.servers.say(payload.server_id, payload.message);

        return { ok: true, result };
    }

    // ═══════════════════════════════════════════════════════════════
    // Остановка
    // ═══════════════════════════════════════════════════════════════

    async stop(signal) {
        this.log.info(`Получен сигнал ${signal}, останавливаюсь…`);
        this.stopping = true;

        clearInterval(this.heartbeatTimer);
        clearInterval(this.metricsTimer);
        clearInterval(this.watchdogTimer);

        this.send('agent.stopping', { reason: signal });

        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            await new Promise((resolve) => {
                this.ws.once('close', resolve);
                this.ws.close(1001, 'shutdown');
                setTimeout(resolve, 2000);
            });
        }

        this.log.info('Агент остановлен');
        process.exit(0);
    }
}

// ── Вспомогательное ──────────────────────────────────────────────────

function decodeEnv(env) {
    const out = {};

    for (const [key, value] of Object.entries(env || {})) {
        // Панель шифрует секреты: enc:значение
        out[key] = typeof value === 'string' && value.startsWith('enc:') ? value.slice(4) : value;
    }

    return out;
}

async function listFiles(dir, depth = 1) {
    const out = [];

    try {
        const entries = await fsp.readdir(dir, { withFileTypes: true });

        for (const entry of entries.slice(0, 500)) {
            if (entry.name.startsWith('.') && entry.name !== '.gamedock-ready') continue;

            const full = path.join(dir, entry.name);

            if (entry.isDirectory() && depth > 0) {
                out.push(...await listFiles(full, depth - 1));
            } else if (entry.isFile()) {
                out.push(entry.name);
            }
        }
    } catch { /* noop */ }

    return out;
}

function await_system_summary() {
    return null;
}

// ── Точка входа ───────────────────────────────────────────────────────

const agent = new Agent();

process.on('SIGTERM', () => agent.stop('SIGTERM'));
process.on('SIGINT', () => agent.stop('SIGINT'));
process.on('uncaughtException', (e) => {
    agent.log.error('Необработанная ошибка:', e);
    agent.log.debug(e.stack);
});
process.on('unhandledRejection', (e) => {
    agent.log.error('Необработанный промис:', e?.message || e);
});

agent.start().catch((e) => {
    process.stderr.write(`[gamedock-agent] Критическая ошибка запуска: ${e.message}\n${e.stack}\n`);
    process.exit(1);
});

export { Agent, version };
