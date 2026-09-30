/**
 * HTTP-клиент панели.
 *
 * Бот ходит в REST API панели (`/api/*`) с API-токеном. Токен создаётся
 * в панели: Профиль → API-токены, права — servers:control (и servers:read).
 *
 * Если GD_PANEL_TOKEN не задан, бот работает в «тихом» режиме: принимает
 * команды и вебхуки, но не может управлять серверами.
 */

import { config } from './config.js';

const USER_AGENT = 'gamedock-bot/1.0';

export class PanelError extends Error {
    constructor(message, status, body) {
        super(message);
        this.name = 'PanelError';
        this.status = status;
        this.body = body;
    }
}

export function isConfigured() {
    return Boolean(config.panelToken);
}

async function request(method, path, { body, query, expect = 'json' } = {}) {
    const url = new URL(path.replace(/^\/+/, '/'), config.panelUrl.replace(/\/+$/, '') + '/');

    for (const [key, value] of Object.entries(query ?? {})) {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, String(value));
        }
    }

    const headers = {
        Accept: 'application/json',
        'User-Agent': USER_AGENT,
    };

    if (config.panelToken) {
        headers.Authorization = `Bearer ${config.panelToken}`;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), config.timeout);

    let response;

    try {
        response = await fetch(url, {
            method,
            headers,
            body: body === undefined ? undefined : JSON.stringify(body),
            signal: controller.signal,
        });
    } catch (e) {
        clearTimeout(timer);
        throw new PanelError(
            e.name === 'AbortError'
                ? `Панель не ответила за ${config.timeout} мс`
                : `Не удалось соединиться с панелью: ${e.message}`,
            0,
        );
    }

    clearTimeout(timer);

    if (response.status === 204) {
        return null;
    }

    const text = await response.text();
    let data = null;

    if (text) {
        try {
            data = JSON.parse(text);
        } catch {
            data = { raw: text };
        }
    }

    if (!response.ok) {
        const message = data?.message || data?.error || response.statusText || 'Неизвестная ошибка';
        throw new PanelError(message, response.status, data);
    }

    return expect === 'text' ? text : data;
}

/* ── Публичные методы ──────────────────────────────────────────────────── */

export const panel = {
    /** Профиль текущего пользователя токена. */
    me: () => request('GET', '/api/me'),

    /** Сводка: баланс, серверы, операции. */
    summary: () => request('GET', '/api/me/summary'),

    /** Список серверов. */
    servers: (query = {}) => request('GET', '/api/servers', { query }),

    /** Один сервер. */
    server: (id) => request('GET', `/api/servers/${id}`),

    /** Питание. */
    start: (id) => request('POST', `/api/servers/${id}/start`),
    stop: (id) => request('POST', `/api/servers/${id}/stop`),
    restart: (id) => request('POST', `/api/servers/${id}/restart`),
    kill: (id) => request('POST', `/api/servers/${id}/kill`),

    /** Последние строки консоли. */
    console: (id, lines = 20) => request('GET', `/api/servers/${id}/console`, { query: { lines } }),

    /** Отправить команду в консоль. */
    send: (id, command) => request('POST', `/api/servers/${id}/console`, { body: { command } }),

    /** Метрики. */
    metrics: (id, range = '1h') => request('GET', `/api/servers/${id}/metrics`, { query: { range } }),

    /** Публичная статистика — работает без токена. */
    stats: () => request('GET', '/api/v1/stats'),

    /** Проверка связи. */
    ping: async () => {
        const started = Date.now();
        const data = await request('GET', '/api/v1/stats');
        return { ok: true, ms: Date.now() - started, data };
    },
};
