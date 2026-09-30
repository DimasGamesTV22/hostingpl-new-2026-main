/**
 * Базовый класс рантайма.
 *
 * Контракт, который должны реализовать все драйверы (docker, podman, lxc, native):
 *
 *   name            — идентификатор рантайма
 *   isAvailable()   — есть ли всё необходимое ПО на этой ноде
 *   create(spec)    — подготовить окружение (каталог, лимиты, метаданные)
 *   start(spec)     — запустить игровой процесс
 *   stop(spec, opts)— корректно остановить
 *   kill(spec)      — убить немедленно
 *   restart(spec)   — перезапуск
 *   isRunning(spec) — проверить состояние
 *   stats(spec)     — метрики процесса {cpu, memory_mb, disk_mb, net_in, net_out}
 *   delete(spec)    — снести окружение
 *   setLimits(spec) — изменить лимиты на лету
 *   exec(spec, cmd) — выполнить команду в окружении
 */

export class BaseRuntime {
    constructor(config, logger) {
        this.config = config;
        this.log = logger;
    }

    get name() {
        throw new Error('name не реализован');
    }

    // eslint-disable-next-line class-methods-use-this
    async isAvailable() {
        return { ok: false, reason: 'not implemented' };
    }

    // eslint-disable-next-line class-methods-use-this
    async create() {
        throw new Error('create не реализован');
    }

    // eslint-disable-next-line class-methods-use-this
    async start() {
        throw new Error('start не реализован');
    }

    // eslint-disable-next-line class-methods-use-this
    async stop() {
        throw new Error('stop не реализован');
    }

    // eslint-disable-next-line class-methods-use-this
    async kill() {
        throw new Error('kill не реализован');
    }

    async restart(spec) {
        await this.stop(spec, { graceful: true });
        await delay(2000);
        return this.start(spec);
    }

    // eslint-disable-next-line class-methods-use-this
    async isRunning() {
        return false;
    }

    // eslint-disable-next-line class-methods-use-this
    async stats() {
        return { cpu: 0, memory_mb: 0, disk_mb: 0, net_in: 0, net_out: 0, players: 0, uptime: 0 };
    }

    // eslint-disable-next-line class-methods-use-this
    async delete() {
        return { ok: true };
    }

    // eslint-disable-next-line class-methods-use-this
    async setLimits() {
        return { ok: true, message: 'runtime не поддерживает изменение лимитов на лету' };
    }

    // eslint-disable-next-line class-methods-use-this
    async exec() {
        return { ok: false, error: 'exec не реализован' };
    }

    // ── Общие помощники для драйверов ─────────────────────────────────

    /** Подставляет {плейсхолдеры} в строку команды. */
    interpolate(template, values) {
        return String(template).replace(/\{(\w+)\}/g, (match, key) => {
            if (key.includes('password')) return '********';
            return values[key] !== undefined ? String(values[key]) : match;
        });
    }

    /**
     * Подставляет значения в массив аргументов.
     * Поддерживает форму вида "-Xmx{ram_mb}M" — значение вставляется внутрь строки.
     */
    interpolateArgs(args, values) {
        return (Array.isArray(args) ? args : []).map((arg) => this.interpolate(String(arg), values));
    }

    /**
     * Значения, доступные в команде запуска.
     * Панель присылает готовый startup, но агент пересчитывает динамические поля
     * (слоты, порты, имя), чтобы не доверять панели слепо.
     */
    buildValues(spec) {
        const server = spec.server || {};
        const ports = server.ports || {};
        const env = {};

        // Секреты приходят из панели зашифрованными — расшифровывает AgentClient
        for (const [key, value] of Object.entries(server.env || {})) {
            if (typeof value === 'string' && value.startsWith('enc:')) {
                env[key] = value.slice(4);
            } else {
                env[key] = value;
            }
        }

        return {
            ram_mb: server.resources?.memory_mb ?? 1024,
            ram_gb: ((server.resources?.memory_mb ?? 1024) / 1024).toFixed(2),
            cpu_percent: server.resources?.cpu_percent ?? 50,
            cpu_cores: Math.max(0.1, (server.resources?.cpu_percent ?? 50) / 100).toFixed(2),
            disk_mb: server.resources?.disk_mb ?? 10240,
            network_mbps: server.resources?.network_mbps ?? 25,
            pids: server.resources?.pids ?? 512,
            slots: server.slots ?? 10,
            players: spec.metrics?.players ?? 0,
            game_port: ports.game ?? 0,
            query_port: ports.query ?? 0,
            rcon_port: ports.rcon ?? 0,
            server_id: server.id,
            server_name: server.name,
            server_ip: spec.node?.flagship || spec.node?.host || '0.0.0.0',
            rcon_password: env.RCON_PASSWORD || env.rcon_password || '',
            build: server.build || '',
            map: env.MAP || 'de_dust2',
            game_mode: env.GAME_MODE || 'competitive',
            worldsize: env.WORLDSIZE || '4000',
            steam_query_key: env.STEAM_QUERY_KEY || '',
        };
    }
}

export function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}
