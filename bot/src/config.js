/**
 * Конфигурация Telegram-бота GameDock.
 *
 * Приоритет: переменные окружения > .env (в корне bot/) > значения по умолчанию.
 * Бот работает и без TELEGRAM_TOKEN — в этом режиме доступна только приём
 * вебхуков от @CryptoBot (см. GD_CRYPTOBOT_MODE).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const DEFAULTS = {
    // ── Telegram ────────────────────────────────────────────────────────
    token: '',
    // longPolling | webhook
    mode: 'longPolling',
    // Для webhook: публичный HTTPS-адрес панели
    webhookUrl: '',
    // Админские chat-id через запятую: получают критические алерты
    adminChats: [],

    // ── Панель ──────────────────────────────────────────────────────────
    panelUrl: 'http://localhost:8000',
    // API-токен панели: Профиль → API-токены, право servers:control
    panelToken: '',
    timeout: 15000,

    // ── Приём оплаты ────────────────────────────────────────────────────
    // Как подтверждать оплату: poll — опрашивать панель, webhook — слушать апдейты
    cryptoMode: 'poll',
    // Токен @CryptoBot (тот же, что в панели) — для самостоятельной проверки счетов
    cryptoToken: '',

    // ── Что умеет бот ───────────────────────────────────────────────────
    features: {
        commands: true,        // /start /help /servers /balance
        serverControl: true,   // start/stop/restart прямо из чата
        notifications: true,   // пересылка алертов панели
        payments: true,        // подтверждение оплат
    },

    // ── Часовой пояс для форматирования дат ─────────────────────────────
    timezone: 'Europe/Moscow',

    log: {
        level: 'info',
        file: '',
    },
};

function parseDotenv(file) {
    if (!fs.existsSync(file)) return {};

    const out = {};

    for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) continue;

        const eq = trimmed.indexOf('=');
        if (eq === -1) continue;

        const key = trimmed.slice(0, eq).trim();
        let value = trimmed.slice(eq + 1).trim();

        if (
            (value.startsWith('"') && value.endsWith('"'))
            || (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }

        out[key] = value;
    }

    return out;
}

const ENV_MAP = {
    TELEGRAM_BOT_TOKEN: ['token'],
    TELEGRAM_MODE: ['mode'],
    TELEGRAM_WEBHOOK_URL: ['webhookUrl'],
    GD_ADMIN_CHATS: ['adminChats', 'csv'],
    GD_PANEL_URL: ['panelUrl'],
    GD_PANEL_TOKEN: ['panelToken'],
    GD_PANEL_TIMEOUT: ['timeout', 'int'],
    GD_CRYPTOBOT_MODE: ['cryptoMode'],
    GD_CRYPTOBOT_TOKEN: ['cryptoToken'],
    GD_TZ: ['timezone'],
};

function loadEnv() {
    const fromFile = {
        ...parseDotenv(path.resolve(__dirname, '../.env')),
        ...parseDotenv(path.resolve(process.cwd(), '.env')),
    };

    for (const [name, [key, type]] of Object.entries(ENV_MAP)) {
        const value = process.env[name] ?? fromFile[name];
        if (value === undefined || value === '') continue;

        switch (type) {
            case 'int':
                config[key] = parseInt(value, 10);
                break;
            case 'csv':
                config[key] = String(value)
                    .split(',')
                    .map((s) => s.trim())
                    .filter(Boolean)
                    // Chat-id — это число, и для групп оно отрицательное
                    .map((s) => (/^-?\d+$/.test(s) ? Number(s) : s));
                break;
            default:
                config[key] = value;
        }
    }
}

export const config = structuredClone(DEFAULTS);

loadEnv();

export function describeConfig() {
    return {
        mode: config.mode,
        adminChats: config.adminChats.length,
        panelUrl: config.panelUrl,
        panelToken: config.panelToken ? '***' : '(не задан)',
        cryptoMode: config.cryptoMode,
        features: config.features,
    };
}
