/**
 * Telegram Bot API без сторонних библиотек.
 *
 * grammY/PowerfulBot тянут за собой десятки зависимостей, а боту нужны
 * ровно четыре метода: getUpdates, sendMessage, setWebhook и answerCallbackQuery.
 * Держим их здесь — 200 строк вместо мегабайта в node_modules.
 */

import { config } from './config.js';

const API = 'https://api.telegram.org';

export class TelegramError extends Error {
    constructor(description, errorCode) {
        super(description);
        this.name = 'TelegramError';
        this.errorCode = errorCode;
    }
}

let lastUpdateId = 0;

function assertToken() {
    if (!config.token) {
        throw new TelegramError(
            'Не задан TELEGRAM_BOT_TOKEN. Получите токен у @BotFather и положите его в bot/.env',
        );
    }
}

async function call(method, payload = {}) {
    assertToken();

    const response = await fetch(`${API}/bot${config.token}/${method}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });

    const data = await response.json().catch(() => ({}));

    if (!data.ok) {
        throw new TelegramError(data.description || 'Telegram API error', data.error_code);
    }

    return data.result;
}

export const telegram = {
    /** Разовый вызов любого метода Bot API. */
    call,

    /**
     * Long polling. Возвращает генератор апдейтов — вызывающий сам решает,
     * как их обрабатывать (и когда завершиться).
     */
    async *updates({ timeout = 30, allowedUpdates } = {}) {
        let backoff = 1000;

        while (true) {
            try {
                const result = await call('getUpdates', {
                    offset: lastUpdateId ? lastUpdateId + 1 : undefined,
                    timeout,
                    allowed_updates: allowedUpdates,
                    drop_pending_updates: lastUpdateId === 0,
                });

                // Успешный ответ сбрасывает backoff
                backoff = 1000;

                for (const update of result) {
                    lastUpdateId = update.update_id;
                    yield update;
                }

                if (result.length === 0) {
                    // Долгий poll без апдейтов — это норма, не повод ратовать в лог
                    await sleep(200);
                }
            } catch (e) {
                if (e instanceof TelegramError && e.errorCode === 409) {
                    // Другой инстанс бота уже забрал апдейты
                    throw e;
                }

                await sleep(backoff);
                backoff = Math.min(backoff * 2, 60000);

                if (config.log.level === 'debug') {
                    process.stderr.write(`[bot] getUpdates: ${e.message}\n`);
                }
            }
        }
    },

    sendMessage(chatId, text, options = {}) {
        return call('sendMessage', {
            chat_id: chatId,
            text,
            parse_mode: 'HTML',
            disable_web_page_preview: true,
            ...options,
        });
    },

    editMessageText(chatId, messageId, text, options = {}) {
        return call('editMessageText', {
            chat_id: chatId,
            message_id: messageId,
            text,
            parse_mode: 'HTML',
            disable_web_page_preview: true,
            ...options,
        });
    },

    answerCallback(callbackId, options = {}) {
        return call('answerCallbackQuery', { callback_query_id: callbackId, ...options });
    },

    getChat(chatId) {
        return call('getChat', { chat_id: chatId });
    },

    setWebhook(url, options = {}) {
        return call('setWebhook', { url, ...options });
    },

    deleteWebhook(dropPendingUpdates = false) {
        return call('deleteWebhook', { drop_pending_updates: dropPendingUpdates });
    },

    /** Узнать, кто подписан на webhook и последняя ли ошибка доставки. */
    getWebhookInfo() {
        return call('getWebhookInfo');
    },
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
