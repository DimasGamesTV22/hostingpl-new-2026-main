/**
 * Обработчики команд и апдейтов Telegram.
 */

import { telegram } from './telegram.js';
import { panel, isConfigured, PanelError } from './panel.js';
import { config } from './config.js';

const BTN = (label, data) => ({ text: label, callback_data: data });

/* ── Экраны ────────────────────────────────────────────────────────────── */

const notLinked = () => [
    'Свяжите бота с аккаунтом панели.',
    '',
    `Добавьте бота в панель: <b>Профиль → Контакты → Telegram</b>, затем выполните`,
    '<code>/link &lt;код&gt;</code>',
].join('\n');

const noToken = () => [
    '<b>Бот не настроен.</b>',
    '',
    'Не задан <code>GD_PANEL_TOKEN</code> — API-токен панели.',
    'Создайте его: <b>Профиль → API-токены</b> (право <code>servers:control</code>)',
    'и пропишите в <code>bot/.env</code>.',
].join('\n');

/* ── Команды ───────────────────────────────────────────────────────────── */

const COMMANDS = {
    async start(ctx) {
        await ctx.reply([
            `<b>GameDock</b> — бот панели игрового хостинга.`,
            '',
            'Что умею:',
            '• показывать ваши серверы и их статус',
            '• запускать, останавливать и перезапускать сервер',
            '• показывать консоль и отправлять в неё команды',
            '• присылать уведомления о падениях и нехватке ресурсов',
            '',
            'Команды: /servers, /status, /console, /cmd, /balance, /help',
        ].join('\n'));
    },

    async help(ctx) {
        await COMMANDS.start(ctx);
    },

    async servers(ctx) {
        if (!isConfigured()) {
            return ctx.reply(noToken());
        }

        const { data } = await panel.servers();

        if (!data?.length) {
            return ctx.reply('У вас пока нет серверов. Создайте сервер в панели: /panel');
        }

        const lines = data.slice(0, 20).map((s, i) => [
            `${i + 1}. <b>${esc(s.name)}</b> &mdash; ${statusEmoji(s.status)} ${statusLabel(s)}`,
            `    ${esc(s.game?.name ?? '')} · ${s.players}/${s.slots} · ${s.address ?? '—'}`,
        ].join('\n'));

        await ctx.reply(
            [`<b>Ваши серверы (${data.length}):</b>`, '', ...lines].join('\n'),
            { reply_markup: { inline_keyboard: data.slice(0, 10).map((s) => [BTN(`▶️ ${s.name}`, `srv:${s.id}:start`)]) } },
        );
    },

    async status(ctx) {
        if (!isConfigured()) {
            return ctx.reply(noToken());
        }

        const { data: servers } = await panel.servers();
        const me = await panel.me().catch(() => null);
        const online = servers.filter((s) => s.status === 'running').length;
        const players = servers.reduce((sum, s) => sum + (s.players || 0), 0);

        await ctx.reply([
            '<b>Сводка</b>',
            '',
            `Серверов: <b>${servers.length}</b> (работают ${online})`,
            `Игроки онлайн: <b>${players}</b>`,
            me ? `Баланс: <b>${money(me.data?.balance)}</b>` : '',
            '',
            'Админ: /adminstats',
        ].filter(Boolean).join('\n'));
    },

    async console(ctx, args) {
        if (!isConfigured()) {
            return ctx.reply(noToken());
        }

        const id = Number(args[0]);

        if (!Number.isInteger(id) || id <= 0) {
            return ctx.reply('Укажите ID сервера: <code>/console 42</code>');
        }

        const { data: lines, stats } = await panel.console(id, 15);

        const body = (lines ?? [])
            .map((l) => esc(l.text ?? ''))
            .slice(-15)
            .join('\n');

        await ctx.reply([
            `<b>Консоль сервера #${id}</b>`,
            stats?.players !== undefined ? `Игроки: ${stats.players}/${stats.slots}` : '',
            '',
            `<pre>${body || '—'}</pre>`,
            '',
            `Отправить команду: <code>/cmd ${id} say Всем привет</code>`,
        ].filter(Boolean).join('\n'));
    },

    async cmd(ctx, args) {
        if (!isConfigured()) {
            return ctx.reply(noToken());
        }

        const id = Number(args[0]);
        const command = args.slice(1).join(' ');

        if (!Number.isInteger(id) || !command) {
            return ctx.reply('Укажите сервер и команду: <code>/cmd 42 say Привет</code>');
        }

        if (!config.features.serverControl) {
            return ctx.reply('Управление серверами отключено в настройках бота.');
        }

        const result = await panel.send(id, command);

        await ctx.reply(
            result?.queued
                ? `Команда поставлена в очередь (нода офлайн): <code>${esc(command)}</code>`
                : `Отправлено: <code>${esc(command)}</code>`,
        );
    },

    async balance(ctx) {
        if (!isConfigured()) {
            return ctx.reply(noToken());
        }

        const me = await panel.me();

        await ctx.reply([
            '<b>Кошелёк</b>',
            '',
            `Баланс: <b>${money(me.data?.balance)}</b>`,
            `Пользователь: ${esc(me.data?.name ?? '')} (${esc(me.data?.email ?? '')})`,
        ].join('\n'));
    },

    async adminstats(ctx) {
        const stats = await panel.stats();

        await ctx.reply([
            '<b>Статистика хостинга</b>',
            '',
            `Серверов онлайн: <b>${stats?.data?.servers ?? '—'}</b>`,
            `Игроки: <b>${stats?.data?.players ?? '—'}</b>`,
            `Аптайм: <b>${stats?.data?.uptime ?? '—'}%</b>`,
        ].join('\n'));
    },

    async link(ctx, args) {
        await ctx.reply(
            'Привязка аккаунта: откройте панель, <b>Профиль → Контакты → Telegram</b>,',
            'скопируйте код и отправьте его сюда: <code>/link КОД</code>',
        );
        void args;
    },
};

/* ── Кнопки ────────────────────────────────────────────────────────────── */

const CALLBACKS = {
    async 'srv:(id):(action)'(ctx) {
        if (!isConfigured()) {
            return ctx.answer('Бот не настроен', { alert: true });
        }

        const [id, action] = [Number(ctx.match[1]), ctx.match[2]];

        if (!config.features.serverControl) {
            return ctx.answer('Управление отключено', { alert: true });
        }

        const call = { start: panel.start, stop: panel.stop, restart: panel.restart, kill: panel.kill }[action];

        if (!call) {
            return ctx.answer('Неизвестное действие', { alert: true });
        }

        try {
            await call(id);
            await ctx.answer(`${statusLabel({ status: `${action}ing` })}…`);
        } catch (e) {
            await ctx.answer(e.message, { alert: true });
        }

        return undefined;
    },

    async 'noop'(ctx) {
        await ctx.answer('');
    },
};

/* ── Диспетчер ─────────────────────────────────────────────────────────── */

export function createHandler({ logger }) {
    async function handleUpdate(update) {
        const ctx = {
            update,
            message: update.message,
            callback: update.callback_query,
            chatId: update.message?.chat?.id ?? update.callback_query?.message?.chat?.id,
            text: update.message?.text ?? '',
            match: null,
        };

        try {
            if (ctx.callback) {
                const [name, pattern] = matchCallback(ctx.callback.data ?? '');

                const handler = CALLBACKS[name];

                if (!handler) {
                    await telegram.answerCallback(ctx.callback.id, { text: 'Кнопка устарела' });
                    return;
                }

                ctx.match = pattern;
                await handler(ctx);
                return;
            }

            if (!ctx.chatId || !ctx.text) {
                return;
            }

            const [command, ...args] = ctx.text.trim().split(/\s+/);
            const name = command.split('@')[0].slice(1).toLowerCase();

            if (!name) return;

            if (!config.features.commands && name !== 'start') {
                await ctx.reply('Команды отключены администратором.');
                return;
            }

            const handler = COMMANDS[name];

            if (!handler) {
                return;
            }

            await handler(ctx, args);
        } catch (e) {
            if (e instanceof PanelError) {
                logger.error(`Панель вернула ${e.status}: ${e.message}`);
                await safeReply(ctx, `Ошибка панели: ${esc(e.message)}`);
            } else {
                logger.error(`Необработанная ошибка: ${e.stack || e.message}`);
                await safeReply(ctx, 'Внутренняя ошибка. Попробуйте позже.');
            }
        }
    }

    return { handleUpdate, commands: Object.keys(COMMANDS) };
}

async function safeReply(ctx, text) {
    try {
        await telegram.sendMessage(ctx.chatId, text);
    } catch {
        // Пользователь мог заблокировать бота — молча игнорируем
    }
}

/* ── Хелперы ───────────────────────────────────────────────────────────── */

const STATUS_LABELS = {
    running: 'работает',
    starting: 'запускается',
    stopping: 'останавливается',
    stopped: 'остановлен',
    installing: 'устанавливается',
    pending: 'в очереди',
    crashed: 'упал',
    error: 'ошибка',
    suspended: 'приостановлен',
    deleted: 'удалён',
};

const STATUS_EMOJI = {
    running: '🟢',
    starting: '🟡',
    stopping: '🟡',
    stopped: '⚪',
    installing: '🟡',
    pending: '⚪',
    crashed: '🔴',
    error: '🔴',
    suspended: '⏸',
    deleted: '⚫',
};

export const statusLabel = (server) => STATUS_LABELS[server?.status] ?? server?.status ?? '—';
export const statusEmoji = (status) => STATUS_EMOJI[status] ?? '⚪';

function matchCallback(data) {
    const [name, pattern] = data.split(':');
    const handler = CALLBACKS[name];

    // Ключ с параметрами записан как 'srv:(id):(action)' — превращаем в RegExp
    if (!handler) return [name, null];

    if (name.includes('(')) {
        const re = new RegExp(`^${name.replace(/\(([^)]+)\)/g, (_, g) => `(${g})`)}$`);
        const m = data.match(re);
        return m ? [name, m.slice(1)] : [name, null];
    }

    return [name, pattern ? [pattern] : []];
}

export function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function money(amount) {
    const value = Number(amount ?? 0);
    return `${value.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} ₽`;
}
