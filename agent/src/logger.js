/**
 * Логирование агента: в консоль и (если настроено) в файл.
 * Формат: 2026-01-01 12:00:00 [INFO] сообщение {данные}
 */

import fs from 'node:fs';
import path from 'node:path';

const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };

const COLORS = {
    error: '\x1b[31m',
    warn: '\x1b[33m',
    info: '\x1b[36m',
    debug: '\x1b[90m',
    reset: '\x1b[0m',
};

class Logger {
    constructor(config = {}) {
        this.level = LEVELS[config.level] ?? LEVELS.info;
        this.file = config.file || null;
        this.colors = process.stdout.isTTY && !process.env.NO_COLOR;

        if (this.file) {
            try {
                fs.mkdirSync(path.dirname(this.file), { recursive: true });
            } catch (e) {
                this.file = null;
            }
        }
    }

    setLevel(level) {
        this.level = LEVELS[level] ?? LEVELS.info;
    }

    write(level, message, data) {
        if ((LEVELS[level] ?? LEVELS.info) > this.level) return;

        const ts = new Date().toISOString().replace('T', ' ').slice(0, 19);
        const payload = data && Object.keys(data).length ? ` ${safeJson(data)}` : '';
        const line = `${ts} [${level.toUpperCase()}] ${message}${payload}`;

        const stream = level === 'error' ? process.stderr : process.stdout;
        stream.write(this.colors ? `${COLORS[level]}${line}${COLORS.reset}\n` : `${line}\n`);

        if (this.file) {
            try {
                fs.appendFileSync(this.file, `${line}\n`);
            } catch {
                // Не теряем вывод из-за ошибки записи в файл
            }
        }
    }

    error(message, data) { this.write('error', message, data); }
    warn(message, data) { this.write('warn', message, data); }
    info(message, data) { this.write('info', message, data); }
    debug(message, data) { this.write('debug', message, data); }

    /** Важные события — всегда в stdout, даже при level=error. */
    event(message, data) {
        const line = `[event] ${message}${data ? ' ' + safeJson(data) : ''}`;
        process.stdout.write(`${line}\n`);

        if (this.file) {
            try {
                fs.appendFileSync(this.file, `${new Date().toISOString()} ${line}\n`);
            } catch { /* noop */ }
        }
    }
}

function safeJson(data) {
    try {
        return JSON.stringify(data, replacer);
    } catch {
        return String(data);
    }
}

function replacer(key, value) {
    if (typeof value === 'bigint') return value.toString();
    if (Buffer.isBuffer(value)) return `<Buffer ${value.length}>`;
    if (value instanceof Error) return { message: value.message, code: value.code };
    return value;
}

export function createLogger(config) {
    return new Logger(config);
}

export { Logger };
