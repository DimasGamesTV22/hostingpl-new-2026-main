/**
 * Приём оплаты через @CryptoBot.
 *
 * Основной поток: панель сама создаёт инвойс (PaymentManager → CryptoBotGateway)
 * и получает оплату на свой вебхук `POST /webhooks/cryptobot`. Бот в этом
 * потоке не участвует.
 *
 * Здесь две вспомогательные функции:
 *   1) confirm(invoiceId)  — сверка инвойса напрямую с @CryptoBot (ручная проверка);
 *   2) handleUpdate()      — обработка апдейта CryptoBot_Paid, если вы включили
 *      режим webhook и перенаправили апдейты боту вместо панели.
 *
 * Бот никогда не начисляет деньги сам — только подтверждает факт оплаты,
 * а зачисление делает панель (единая точка правды для баланса).
 */

import { telegram } from './telegram.js';
import { config } from './config.js';
import { esc } from './handlers.js';

const CRYPTO_API = 'https://api.telegram.org/bot';

export class CryptoBot {
    constructor({ logger }) {
        this.logger = logger;
        this.token = config.cryptoToken;
        this.seen = new Set();
    }

    get enabled() {
        return config.features.payments && Boolean(this.token);
    }

    async call(method, payload = {}) {
        if (!this.token) {
            throw new Error('Не задан GD_CRYPTOBOT_TOKEN');
        }

        const response = await fetch(`${CRYPTO_API}${this.token}/${method}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        const data = await response.json().catch(() => ({}));

        if (!data.ok) {
            throw new Error(data.description || 'CryptoBot API error');
        }

        return data.result;
    }

    /**
     * Сверить инвойс с @CryptoBot.
     *
     * @param {string|number} invoiceId
     * @returns {Promise<{ok: boolean, status?: string, asset?: string, amount?: number, error?: string}>}
     */
    async confirm(invoiceId) {
        if (!this.enabled) {
            return { ok: false, error: 'Приём оплаты выключен' };
        }

        try {
            const invoice = await this.call('getInvoices', {
                invoice_id: Number(invoiceId),
                offset: 0,
                count: 1,
            });

            const item = invoice?.items?.[0];

            if (!item) {
                return { ok: false, error: 'Инвойс не найден' };
            }

            return {
                ok: item.status === 'paid' || item.status === 'paid_expiration',
                status: item.status,
                asset: item.asset,
                amount: Number(item.amount),
                payload: item.payload,
            };
        } catch (e) {
            this.logger.error(`Не удалось подтвердить инвойс ${invoiceId}: ${e.message}`);
            return { ok: false, error: e.message };
        }
    }

    /**
     * Обработать апдейт `CryptoBot_Paid` от @CryptoBot.
     *
     * @returns {Promise<boolean>} true, если апдейт относился к оплате
     */
    async handleUpdate(update) {
        const payload = update.crypto_bot_paid ?? update.message?.crypto_bot_paid;

        if (!payload) {
            return false;
        }

        const invoicePayload = payload.invoice_payload ?? payload.payload ?? 'unknown';

        if (this.seen.has(invoicePayload)) {
            return true; // повторная доставка
        }

        this.seen.add(invoicePayload);

        const result = await this.confirm(invoicePayload);

        this.logger.info(
            `CryptoBot: инвойс ${invoicePayload} — ${result.ok ? 'оплачен' : `не оплачен (${result.status ?? result.error})`}`,
        );

        if (result.ok && config.adminChats.length) {
            for (const chatId of config.adminChats) {
                telegram.sendMessage(
                    chatId,
                    [
                        '💰 <b>Оплата подтверждена</b>',
                        '',
                        `Инвойс: <code>${esc(invoicePayload)}</code>`,
                        `Сумма: ${result.amount} ${result.asset ?? ''}`,
                        result.payload ? `Payload: <code>${esc(result.payload)}</code>` : '',
                    ].filter(Boolean).join('\n'),
                ).catch(() => {});
            }
        }

        return true;
    }
}
