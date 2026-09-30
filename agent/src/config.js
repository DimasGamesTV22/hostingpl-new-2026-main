/**
 * Конфигурация агента.
 *
 * Файл читается один раз при старте. Значения можно переопределить
 * аргументами командной строки (--key=value) или переменными окружения
 * (GD_KEY). Приоритет: CLI > env > файл > значения по умолчанию.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const DEFAULTS = {
    // ── Связь с панелью ────────────────────────────────────────────────
    panel: 'http://localhost:8000',
    wsPath: '/agent/ws',
    token: '',
    nodeId: null,

    // Переподключение
    reconnect: true,
    reconnectDelay: 5000,
    reconnectDelayMax: 60000,
    heartbeatInterval: 5,
    requestTimeout: 15000,

    // ── Пути ───────────────────────────────────────────────────────────
    serversRoot: '/home/gamedock/servers',
    backupsRoot: '/home/gamedock/backups',
    templatesRoot: '/opt/gamedock/game-images',
    pluginsRoot: '/home/gamedock/plugins',
    logsRoot: '/home/gamedock/logs',

    // ── Системный пользователь ─────────────────────────────────────────
    systemUser: 'gamedock',
    systemGroup: 'gamedock',

    // ── Рантайм по умолчанию ───────────────────────────────────────────
    runtime: 'docker',

    docker: {
        socket: '/var/run/docker.sock',
        networkPrefix: 'gamedock',
        defaultImage: 'debian:12-slim',
        cgroupVersion: 2,
    },

    podman: {
        socket: 'unix:///run/podman/podman.sock',
        networkPrefix: 'gamedock',
        defaultImage: 'debian:12-slim',
        rootless: false,
    },

    lxc: {
        pveHost: '127.0.0.1',
        pveUser: 'root@pam',
        template: 'local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst',
        storage: 'local-lvm',
        bridge: 'vmbr0',
    },

    native: {
        cgroupRoot: '/sys/fs/cgroup/gamedock',
        systemdScope: 'gamedock.slice',
    },

    // ── Ограничения ────────────────────────────────────────────────────
    limits: {
        maxFileRead: 5 * 1024 * 1024,       // 5 МБ
        maxFileWrite: 2 * 1024 * 1024,      // 2 МБ
        maxUpload: 64 * 1024 * 1024,        // 64 МБ
        consoleBuffer: 2000,
        logTail: 5000,
    },

    // ── Бэкапы ─────────────────────────────────────────────────────────
    backups: {
        compress: 'tar.gz',
        exclude: ['cache', 'logs', '*.tmp', 'crashreports', '*.log'],
        s3: {
            enabled: false,
            endpoint: '',
            region: 'ru-central1',
            bucket: '',
            accessKey: '',
            secretKey: '',
            prefix: 'backups',
        },
    },

    // ── Секретные коды ─────────────────────────────────────────────────
    secretCodes: {
        prefixes: ['//', '!', '/promo'],
        // Игровые процессы, где перехват чата имеет смысл
        chatAwareGames: ['samp', 'mta', 'ragemp', 'altv', 'crmp', 'rust'],
    },

    // ── Логи ───────────────────────────────────────────────────────────
    logging: {
        level: 'info',
        maxSizeMb: 128,
        keepFiles: 5,
    },

    // ── Панель-режимы, которые поддерживает агент ──────────────────────
    log: {
        level: 'info',
        file: '/var/log/gamedock-agent.log',
        maxSize: '10M',
        maxFiles: 3,
    },
};

function readConfigFile() {
    const candidates = [
        process.env.GD_AGENT_CONFIG,
        path.resolve(__dirname, '../agent.config.json'),
        '/etc/gamedock/agent.json',
        path.join(process.env.HOME || '/root', '.gamedock/agent.json'),
    ].filter(Boolean);

    for (const file of candidates) {
        try {
            if (fs.existsSync(file)) {
                const parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
                return { file, data: parsed };
            }
        } catch (e) {
            // Кривой конфиг не должен ронять агент — логируем в stderr
            process.stderr.write(`[gamedock-agent] Не удалось прочитать ${file}: ${e.message}\n`);
        }
    }

    return { file: null, data: {} };
}

function deepMerge(target, source) {
    if (!source || typeof source !== 'object') return target;

    const out = Array.isArray(target) ? [...target] : { ...target };

    for (const [key, value] of Object.entries(source)) {
        if (value === undefined) continue;

        if (value && typeof value === 'object' && !Array.isArray(value)) {
            out[key] = deepMerge(out[key] ?? {}, value);
        } else {
            out[key] = value;
        }
    }

    return out;
}

function parseArgs(argv) {
    const out = {};

    for (const arg of argv) {
        if (!arg.startsWith('--')) continue;

        const [rawKey, ...rest] = arg.slice(2).split('=');
        const key = rawKey.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
        let value = rest.join('=');

        if (value === 'true') value = true;
        else if (value === 'false') value = false;
        else if (/^-?\d+(\.\d+)?$/.test(value)) value = Number(value);

        out[key] = value;
    }

    return out;
}

function applyEnv(config) {
    const mapping = {
        GD_PANEL: ['panel'],
        GD_TOKEN: ['token'],
        GD_NODE_ID: ['nodeId'],
        GD_RUNTIME: ['runtime'],
        GD_SERVERS_ROOT: ['serversRoot'],
        GD_BACKUPS_ROOT: ['backupsRoot'],
        GD_TEMPLATES_ROOT: ['templatesRoot'],
        GD_SYSTEM_USER: ['systemUser'],
        GD_DOCKER_SOCKET: ['docker', 'socket'],
    };

    for (const [env, keys] of Object.entries(mapping)) {
        if (process.env[env] === undefined) continue;

        let target = config;
        for (let i = 0; i < keys.length - 1; i++) {
            target[keys[i]] = target[keys[i]] ?? {};
            target = target[keys[i]];
        }
        target[keys[keys.length - 1]] = process.env[env];
    }
}

export function loadConfig(argv = process.argv.slice(2)) {
    const file = readConfigFile();
    let config = deepMerge(DEFAULTS, file.data);

    applyEnv(config);
    config = deepMerge(config, parseArgs(argv));

    // Автоопределение WSS-адреса
    if (!config.wsUrl) {
        const base = String(config.panel).replace(/\/+$/, '');
        const url = base.startsWith('https') ? base.replace('https', 'wss') : base.replace('http', 'ws');
        config.wsUrl = url + config.wsPath;
    }

    config.configFile = file.file;

    validate(config);

    return config;
}

function validate(config) {
    if (!config.token) {
        throw new Error(
            'Не задан токен ноды. Укажите --token, GD_TOKEN или добавьте "token" в agent.config.json.\n'
            + 'Токен выдаёт панель: раздел «Админка → Ноды».',
        );
    }

    if (!['docker', 'podman', 'lxc', 'native'].includes(config.runtime)) {
        throw new Error(`Неизвестный рантайм: ${config.runtime}. Допустимо: docker, podman, lxc, native.`);
    }
}

export { DEFAULTS };
