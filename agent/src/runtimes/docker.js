/**
 * Драйвер Docker (и Podman — отличается только сокетом и синтаксисом).
 *
 * Агент общается с Docker Engine через unix-сокет, без внешних зависимостей —
 * только Node 20+ и встроенный fetch/undici.
 */

import { spawn } from 'node:child_process';
import net from 'node:net';
import http from 'node:http';
import { BaseRuntime, delay } from './base.js';

export class DockerRuntime extends BaseRuntime {
    constructor(config, logger, { socket, networkPrefix, defaultImage, variant = 'docker' }) {
        super(config, logger);

        this.socket = socket;
        this.networkPrefix = networkPrefix;
        this.defaultImage = defaultImage;
        this.variant = variant; // docker | podman

        this.serversRoot = config.serversRoot;
        this.user = config.systemUser;
    }

    get name() {
        return this.variant;
    }

    containerName(serverId) {
        return `${this.networkPrefix}-${serverId}`;
    }

    networkName() {
        return `${this.networkPrefix}-net`;
    }

    // ── Проверка доступности ──────────────────────────────────────────

    async isAvailable() {
        try {
            await this.request('GET', '/_ping');
            return { ok: true };
        } catch (e) {
            return {
                ok: false,
                reason: `${this.variant} недоступен: ${e.message}. Проверьте сокет ${this.socket}`,
            };
        }
    }

    // ── HTTP по unix-сокету ───────────────────────────────────────────

    request(method, endpoint, body = null) {
        const payload = body === null ? null : JSON.stringify(body);

        return new Promise((resolve, reject) => {
            const isUnix = this.socket.startsWith('unix://') || !this.socket.includes('://');
            const socketPath = this.socket.replace('unix://', '');

            const options = isUnix
                ? { socketPath, path: endpoint, method, headers: {} }
                : {
                    host: new URL(this.socket).hostname,
                    port: new URL(this.socket).port || 2375,
                    path: endpoint,
                    method,
                    headers: {},
                };

            if (payload) {
                options.headers['Content-Type'] = 'application/json';
                options.headers['Content-Length'] = Buffer.byteLength(payload);
            }

            const req = http.request(options, (res) => {
                const chunks = [];

                res.on('data', (chunk) => chunks.push(chunk));
                res.on('end', () => {
                    const raw = Buffer.concat(chunks).toString('utf8');

                    let parsed = null;
                    try {
                        parsed = raw ? JSON.parse(raw) : null;
                    } catch {
                        parsed = raw;
                    }

                    if (res.statusCode >= 200 && res.statusCode < 300) {
                        resolve(parsed);
                    } else {
                        const message = parsed?.message || raw || `HTTP ${res.statusCode}`;
                        const error = new Error(message);
                        error.status = res.statusCode;
                        error.body = parsed;
                        reject(error);
                    }
                });
            });

            req.on('error', (e) => {
                if (e.code === 'ENOENT' || e.code === 'ECONNREFUSED') {
                    reject(new Error(`Нет соединения с ${this.variant} (${this.socket})`));
                } else {
                    reject(e);
                }
            });

            req.setTimeout(60000, () => {
                req.destroy(new Error('Таймаут запроса к ' + this.variant));
            });

            if (payload) req.write(payload);
            req.end();
        });
    }

    // ── Жизненный цикл ───────────────────────────────────────────────

    async ensureNetwork() {
        try {
            await this.request('GET', `/networks/${this.networkName()}`);
        } catch {
            this.log.info(`${this.variant}: создаём сеть ${this.networkName()}`);

            try {
                await this.request('POST', '/networks/create', {
                    Name: this.networkName(),
                    Driver: 'bridge',
                    CheckDuplicate: true,
                });
            } catch (e) {
                this.log.warn(`${this.variant}: не удалось создать сеть: ${e.message}`);
            }
        }
    }

    async create(spec) {
        const { server } = spec;
        const name = this.containerName(server.id);

        await this.ensureNetwork();

        // Убираем контейнер, если он остался от прошлой попытки
        await this.removeContainer(name);

        const game = spec.game || {};
        const image = server.image || game.image || this.defaultImage;
        const resources = server.resources || {};

        const hostConfig = {
            Binds: [`${spec.path}:/home/server`],
            PortBindings: {},
            Memory: (resources.memory_mb || 1024) * 1024 * 1024,
            MemorySwap: resources.swap_mb
                ? (resources.memory_mb + resources.swap_mb) * 1024 * 1024
                : (resources.memory_mb || 1024) * 1024 * 1024,
            NanoCpus: Math.round(((resources.cpu_percent || 50) / 100) * 1e9),
            PidsLimit: resources.pids || 512,
            NetworkMode: this.networkName(),
            RestartPolicy: { Name: 'unless-stopped' },
            CapDrop: ['ALL'],
            CapAdd: ['NET_BIND_SERVICE', 'CHOWN', 'SETUID', 'SETGID', 'KILL'],
            SecurityOpt: ['no-new-privileges'],
            Ulimits: [
                { Name: 'nofile', Soft: 65535, Hard: 65535 },
                { Name: 'nproc', Soft: resources.pids || 512, Hard: resources.pids || 512 },
            ],
            LogConfig: { Type: 'json-file', Config: { 'max-size': '128m', 'max-file': '5' } },
        };

        // Порты
        const portMap = {
            game: server.ports?.game,
            query: server.ports?.query,
            rcon: server.ports?.rcon,
        };

        for (const [kind, port] of Object.entries(portMap)) {
            if (!port) continue;

            const containerPort = kind === 'game' ? port : port;

            hostConfig.PortBindings[`${containerPort}/tcp`] = [{ HostIp: '0.0.0.0', HostPort: String(port) }];
        }

        // Лимит скорости сети — через tc в контейнере, если доступен
        if (resources.network_mbps) {
            hostConfig.Tmpfs = ['/run'];
        }

        const envList = Object.entries(this.buildEnvList(spec))
            .map(([key, value]) => `${key}=${value}`);

        const body = {
            Image: image,
            name,
            User: '1000:1000',
            WorkingDir: '/home/server',
            Env: envList,
            Labels: {
                'gamedock.server_id': String(server.id),
                'gamedock.uuid': server.uuid || '',
                'gamedock.game': game.slug || '',
                'gamedock.managed': 'true',
            },
            ExposedPorts: Object.fromEntries(
                Object.values(portMap).filter(Boolean).map((p) => [`${p}/tcp`, {}]),
            ),
            HostConfig: hostConfig,
        };

        this.log.info(`${this.variant}: создаём контейнер ${name} (${image})`);

        const created = await this.request('POST', '/containers/create?name=' + encodeURIComponent(name), body);

        return { ok: true, external_id: created.Id, name };
    }

    buildEnvList(spec) {
        const { server } = spec;
        const env = {
            TZ: 'Europe/Moscow',
            SERVER_ID: String(server.id),
            SERVER_UUID: server.uuid || '',
        };

        for (const [key, value] of Object.entries(server.env || {})) {
            env[key] = typeof value === 'string' && value.startsWith('enc:') ? value.slice(4) : value;
        }

        // Лимиты — агенты в контейнере читают их, если умеют
        env.GD_MEMORY_LIMIT = `${server.resources?.memory_mb || 1024}M`;
        env.GD_CPU_LIMIT = String(server.resources?.cpu_percent || 50);
        env.GD_DISK_LIMIT = `${server.resources?.disk_mb || 10240}M`;

        return env;
    }

    async start(spec) {
        const name = this.containerName(spec.server.id);

        // Если контейнера нет — создаём
        const exists = await this.containerExists(name);
        if (!exists) {
            const result = await this.create(spec);
            if (!result.ok) return { ok: false, error: result.error };
        }

        try {
            await this.request('POST', `/containers/${name}/start`);
            this.log.info(`${this.variant}: сервер ${spec.server.id} запущен`);
            return { ok: true, started_at: Math.floor(Date.now() / 1000) };
        } catch (e) {
            const logs = await this.logsTail(spec.server.id, 20).catch(() => '');
            return { ok: false, error: `${e.message}\n${logs}` };
        }
    }

    async stop(spec, options = {}) {
        const name = this.containerName(spec.server.id);
        const timeout = options.timeout || spec.startup?.stop_timeout || 30;

        try {
            await this.request('POST', `/containers/${name}/stop?t=${timeout}`);
            this.log.info(`${this.variant}: сервер ${spec.server.id} остановлен`);
            return { ok: true };
        } catch (e) {
            if (e.status === 304) return { ok: true }; // уже остановлен
            return { ok: false, error: e.message };
        }
    }

    async kill(spec) {
        const name = this.containerName(spec.server.id);

        try {
            await this.request('POST', `/containers/${name}/kill`);
            return { ok: true };
        } catch (e) {
            if (e.status === 409 || e.status === 404) return { ok: true };
            return { ok: false, error: e.message };
        }
    }

    async isRunning(spec) {
        try {
            const info = await this.request('GET', `/containers/${this.containerName(spec.server.id)}/json`);
            return info?.State?.Running === true;
        } catch {
            return false;
        }
    }

    async stats(spec) {
        const name = this.containerName(spec.server.id);

        try {
            const raw = await this.request('GET', `/containers/${name}/stats?stream=false&one-shot=true`);
            return this.parseStats(raw, spec);
        } catch {
            return { cpu: 0, memory_mb: 0, disk_mb: spec.lastDiskMb || 0, net_in: 0, net_out: 0, players: 0, uptime: 0 };
        }
    }

    parseStats(raw, spec) {
        const cpuDelta = (raw.cpu_stats?.cpu_usage?.total_usage || 0)
            - (raw.precpu_stats?.cpu_usage?.total_usage || 0);
        const systemDelta = (raw.cpu_stats?.system_cpu_usage || 0)
            - (raw.precpu_stats?.system_cpu_usage || 0);
        const cores = raw.cpu_stats?.online_cpus || 1;

        // cpu_usage в Docker — проценты от одного ядра, умножаем на 100 для приведения к «% одного ядра»
        const cpu = systemDelta > 0 ? Math.min(100 * cores, (cpuDelta / systemDelta) * 100 * cores) : 0;

        const memoryMb = Math.round((raw.memory_stats?.usage || 0) / 1048576);

        return {
            cpu: Number(cpu.toFixed(2)),
            memory_mb: memoryMb,
            disk_mb: spec.lastDiskMb || 0,
            net_in: Math.round((raw.networks?.eth0?.rx_bytes || 0) / 1048576),
            net_out: Math.round((raw.networks?.eth0?.tx_bytes || 0) / 1048576),
            players: spec.metrics?.players || 0,
            uptime: Math.floor((raw.stats?.read - (raw.preread || raw.stats?.read)) / 1000000000),
        };
    }

    async delete(spec, { purge = true } = {}) {
        const name = this.containerName(spec.server.id);

        try {
            await this.request('DELETE', `/containers/${name}?force=1&v=1`);
        } catch (e) {
            if (e.status !== 404) {
                this.log.warn(`${this.variant}: ошибка удаления контейнера: ${e.message}`);
            }
        }

        return { ok: true, purged: purge };
    }

    async setLimits(spec, resources) {
        const name = this.containerName(spec.server.id);

        const update = {};

        if (resources.memory_mb) {
            update.Memory = resources.memory_mb * 1024 * 1024;
            update.MemorySwap = resources.swap_mb
                ? (resources.memory_mb + resources.swap_mb) * 1024 * 1024
                : resources.memory_mb * 1024 * 1024;
        }

        if (resources.cpu_percent) {
            update.NanoCpus = Math.round((resources.cpu_percent / 100) * 1e9);
        }

        if (resources.pids) {
            update.PidsLimit = resources.pids;
        }

        if (Object.keys(update).length === 0) {
            return { ok: true, message: 'Нечего обновлять' };
        }

        try {
            await this.request('POST', `/containers/${name}/update`, update);
            this.log.info(`${this.variant}: лимиты обновлены для ${spec.server.id}`);

            return { ok: true, applied: update };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    async exec(spec, command) {
        const name = this.containerName(spec.server.id);

        try {
            const created = await this.request('POST', `/containers/${name}/exec`, {
                AttachStdout: true,
                AttachStderr: true,
                Cmd: Array.isArray(command) ? command : ['sh', '-c', String(command)],
            });

            const { output } = await this.request('POST', `/exec/${created.Id}/start`, { Detach: false, Tty: false });

            return { ok: true, output };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    // ── Логи и вспомогательное ────────────────────────────────────────

    async logsTail(serverId, lines = 200, timestamps = false) {
        const name = this.containerName(serverId);

        try {
            const result = await this.request(
                'GET',
                `/containers/${name}/logs?stdout=1&stderr=1&tail=${lines}&timestamps=${timestamps ? 1 : 0}`,
            );

            if (Buffer.isBuffer(result)) {
                return demuxDockerLogs(result);
            }

            return String(result || '');
        } catch {
            return '';
        }
    }

    async containerExists(name) {
        try {
            await this.request('GET', `/containers/${name}/json`);
            return true;
        } catch {
            return false;
        }
    }

    async removeContainer(name) {
        try {
            await this.request('DELETE', `/containers/${name}?force=1&v=1`);
        } catch { /* не было — не страшно */ }
    }

    async list() {
        try {
            const result = await this.request('GET', '/containers/json?all=true&filters='
                + encodeURIComponent(JSON.stringify({ label: ['gamedock.managed=true'] })));

            return Array.isArray(result) ? result : [];
        } catch {
            return [];
        }
    }

    async info() {
        try {
            return await this.request('GET', '/info');
        } catch {
            return null;
        }
    }

    async prune() {
        try {
            return await this.request('POST', '/containers/prune');
        } catch {
            return null;
        }
    }
}

/**
 * Docker отдаёт логи с 8-байтовым заголовком (поток, тип, размер).
 * Нужно его убрать, иначе в консоли будут мусорные символы.
 */
function demuxDockerLogs(buffer) {
    const lines = [];

    let offset = 0;
    while (offset < buffer.length) {
        if (offset + 8 > buffer.length) {
            lines.push(buffer.slice(offset).toString('utf8'));
            break;
        }

        const type = buffer[offset];
        const size = buffer.readUInt32BE(offset + 4);
        offset += 8;

        if (offset + size > buffer.length) {
            lines.push(buffer.slice(offset).toString('utf8'));
            break;
        }

        const text = buffer.slice(offset, offset + size).toString('utf8');
        lines.push((type === 1 ? '[stderr] ' : '') + text.replace(/\n$/, ''));

        offset += size;
    }

    return lines.join('\n');
}
