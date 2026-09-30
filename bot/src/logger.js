/**
 * Логирование бота: консоль и, если настроено, файл.
 * Формат: 2026-01-01 12:00:00 [INFO] сообщение {данные}
 */

import fs from 'node:fs';
import path from 'node:path';

import { config } from './config.js';

const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };

const COLORS = {
    error: '\x1b[31m',
    warn: '\x1b[33m',
    info: '\x1b[36m',
    debug: '\x1b[90m',
    reset: '\x1b[0m',
};

class Logger {
    constructor() {
        this.level = LEVELS[config.log.level] ?? LEVELS.info;
        this.colors = process.stdout.isTTY && !process.env.NO_COLOR;
        this.file = config.log.file || null;

        if (this.file) {
            try {
                fs.mkdirSync(path.dirname(this.file), { recursive: true });
            } catch {
                this.file = null;
            }
        }
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
                // Потеря файлового лога не должна ронять бота
            }
        }
    }

    error(m, d) { this.write('error', m, d); }
    warn(m, d) { this.write('warn', m, d); }
    info(m, d) { this.write('info', m, d); }
    debug(m, d) { this.write('debug', m, d); }
}

function safeJson(data) {
    try {
        return JSON.stringify(data, (k, v) => (v instanceof Error ? v.message : v));
    } catch {
        return String(data);
    }
}

export const logger = new Logger();
export { Logger };
