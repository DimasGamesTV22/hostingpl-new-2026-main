#!/usr/bin/env node
/**
 * GameDock Telegram-бот.
 *
 * Назначение:
 *   • команды управления серверами прямо из чата;
 *   • уведомления о падениях, нехватке ресурсов и оплатах;
 *   • приём апдейтов @CryptoBot (резервный канал поверх вебхука панели).
 *
 * Запуск:
 *   node src/index.js
 *   node src/index.js --check     # проверить конфигурацию и выйти
 *
 * Настройка — bot/.env, см. .env.example.
 */

import process from 'node:process';

import { config, describeConfig } from './config.js';
import { logger } from './logger.js';
import { telegram, TelegramError } from './telegram.js';
import { panel, isConfigured } from './panel.js';
import { createHandler } from './handlers.js';
import { CryptoBot } from './cryptobot.js';

const crypto = new CryptoBot({ logger });
const handler = createHandler({ logger });

/* ── Проверка готовности ───────────────────────────────────────────────── */

async function selfCheck() {
    logger.info('GameDock bot — проверка конфигурации');
    logger.info(`конфигурация: ${JSON.stringify(describeConfig())}`);

    let problems = 0;

    if (config.token) {
        try {
            const me = await telegram.call('getMe');
            logger.info(`Telegram: @${me.username} (id ${me.id})`);
        } catch (e) {
            logger.error(`Telegram недоступен: ${e.message}`);
            problems += 1;
        }
    } else {
        logger.warn('TELEGRAM_BOT_TOKEN не задан — команды работать не будут');
        problems += 1;
    }

    if (isConfigured()) {
        try {
            const ping = await panel.ping();
            logger.info(`Панель: ${ping.ms} мс, ${JSON.stringify(ping.data?.data ?? {})}`);
        } catch (e) {
            logger.error(`Панель недоступна: ${e.message}`);
            problems += 1;
        }
    } else {
        logger.warn('GD_PANEL_TOKEN не задан — управление серверами отключено');
    }

    if (crypto.enabled) {
        logger.info('CryptoBot: включён');
    } else {
        logger.info('CryptoBot: выключен (GD_CRYPTOBOT_TOKEN не задан)');
    }

    logger.info(`команды: ${handler.commands.join(', ')}`);
    logger.info(problems ? `проблем: ${problems}` : 'всё готово');

    return problems;
}

/* ── Основной цикл ─────────────────────────────────────────────────────── */

async function start() {
    if (!config.token) {
        logger.error('Не задан TELEGRAM_BOT_TOKEN. Скопируйте bot/.env.example в bot/.env и заполните.');
        process.exit(1);
    }

    if (config.mode === 'webhook') {
        await startWebhook();
        return;
    }

    await startLongPolling();
}

async function startLongPolling() {
    // Если раньше был webhook, его надо снять — иначе Telegram будет слать
    // апдейты туда, а getUpdates будет отдавать конфликт 409.
    try {
        const info = await telegram.getWebhookInfo();

        if (info.url) {
            logger.info(`Снимаю webhook ${info.url}`);
            await telegram.deleteWebhook(true);
        }
    } catch (e) {
        logger.debug(`getWebhookInfo: ${e.message}`);
    }

    logger.info('Запущен long polling');

    for await (const update of telegram.updates()) {
        try {
            // Сначала апдейты оплаты — они важнее команд
            const handled = await crypto.handleUpdate(update);

            if (!handled) {
                await handler.handleUpdate(update);
            }
        } catch (e) {
            logger.error(`Апдейт ${update.update_id}: ${e.stack || e.message}`);
        }
    }
}

async function startWebhook() {
    if (!config.webhookUrl) {
        logger.error('Режим webhook требует TELEGRAM_WEBHOOK_URL (публичный HTTPS-адрес).');
        process.exit(1);
    }

    const url = `${config.webhookUrl.replace(/\/+$/, '')}/bot/webhook`;

    await telegram.setWebhook(url, {
        allowed_updates: ['message', 'callback_query', 'crypto_bot_paid'],
        drop_pending_updates: false,
    });

    logger.info(`Webhook установлен: ${url}`);

    const server = await import('node:http').then(({ createServer }) => createServer(async (req, res) => {
        if (req.method !== 'POST' || req.url !== '/bot/webhook') {
            res.writeHead(404).end();
            return;
        }

        try {
            const chunks = [];
            for await (const chunk of req) chunks.push(chunk);

            const update = JSON.parse(Buffer.concat(chunks).toString('utf8'));

            // Отвечаем сразу, чтобы Telegram не переповторил апдейт
            res.writeHead(200, { 'Content-Type': 'application/json' }).end('{"ok":true}');

            const handled = await crypto.handleUpdate(update);
            if (!handled) {
                await handler.handleUpdate(update);
            }
        } catch (e) {
            logger.error(`Webhook: ${e.message}`);
            if (!res.headersSent) {
                res.writeHead(500).end();
            }
        }
    }));

    const port = Number(process.env.PORT ?? 8090);

    server.listen(port, '0.0.0.0', () => {
        logger.info(`HTTP-сервер webhook слушает :${port}`);
    });

    // Панель проксирует сюда апдейты Криптобота
    registerShutdown(() => {
        server.close();
        telegram.deleteWebhook().catch(() => {});
    });
}

function registerShutdown(extra) {
    const stop = () => {
        logger.info('Останавливаюсь…');
        extra?.();
        process.exit(0);
    };

    process.on('SIGTERM', stop);
    process.on('SIGINT', stop);
}

/* ── Точка входа ───────────────────────────────────────────────────────── */

const args = process.argv.slice(2);

if (args.includes('--check') || args.includes('-c') || args.includes('check')) {
    selfCheck().then((problems) => process.exit(problems ? 1 : 0));
} else {
    process.on('uncaughtException', (e) => {
        logger.error(`Необработанное исключение: ${e.stack || e.message}`);
    });

    process.on('unhandledRejection', (e) => {
        logger.error(`Необработанный промис: ${e?.message || e}`);
    });

    start().catch((e) => {
        if (e instanceof TelegramError) {
            logger.error(`Telegram: ${e.message}`);
        } else {
            logger.error(`Не удалось запустить: ${e.stack || e.message}`);
        }
        process.exit(1);
    });
}

export { selfCheck, crypto, handler };
