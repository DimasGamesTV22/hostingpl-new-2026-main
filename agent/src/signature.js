/**
 * Подпись сообщений между панелью и агентом.
 *
 * Форма канонизации определена панелью — AgentConnection::signaturePayload():
 *
 *   unset($message['sig']);
 *   ksort($message);
 *   json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
 *
 * Агент обязан считать подпись ровно так же. Раньше он строил другую
 * каноническую форму — «ключ=значение», склеенная через «&», — и подпись
 * не совпадала ни в одну сторону: агент отбрасывал каждое сообщение панели,
 * а панель разрывала соединение на каждом сообщении агента.
 *
 * Тонкости PHP, которые обязаны повторяться здесь:
 *
 * 1. Сортируется ТОЛЬКО верхний уровень. Вложенные объекты сериализуются в
 *    порядке ключей, который задал отправитель (ksort($message) — один вызов,
 *    рекурсии нет). Поэтому рекурсивная сортировка сломала бы подпись.
 *
 * 2. json_decode($json, true) не отличает пустой объект от пустого массива:
 *    «{}» и «[]» оба дают пустой массив PHP и оба сериализуются в «[]».
 *    Значит «{}» в канонической форме агента обязан превратиться в «[]» —
 *    иначе, например, sync.request с пустым data никогда не пройдёт проверку.
 *
 * 3. JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES: неэкранированный UTF-8
 *    и неэкранированные «/». JSON.stringify в Node по умолчанию делает ровно
 *    то же, поэтому экранировать вручную ничего не нужно.
 */

import crypto from 'node:crypto';

/**
 * Приводит значение к тому виду, в котором его сериализует PHP.
 *
 * @param {*} value
 * @returns {*} значение, готовое к JSON.stringify
 */
export function canonicalValue(value) {
    if (Array.isArray(value)) {
        return value.map(canonicalValue);
    }

    if (value !== null && typeof value === 'object') {
        const keys = Object.keys(value);

        // Пустой объект PHP не отличит от пустого массива.
        if (keys.length === 0) {
            return [];
        }

        // Порядок вложенных ключей сохраняем: PHP их не сортирует.
        const out = {};
        for (const key of keys) {
            out[key] = canonicalValue(value[key]);
        }
        return out;
    }

    return value;
}

/**
 * Сравнение ключей так же, как ksort() в PHP.
 *
 * ksort без флагов использует SORT_REGULAR: две числовые строки PHP
 * сравнивает как числа («10» идёт раньше «9»), остальные — как строки,
 * побайтово. Идентификаторы вида «event», «payload», «ts» числовыми не
 * бывают, но правило описано целиком, чтобы совпадение не зависело от
 * того, какие ключи встретятся завтра.
 *
 * @param {string} a
 * @param {string} b
 * @returns {number}
 */
function compareKeys(a, b) {
    const aNum = /^-?\d+(\.\d+)?$/.test(a);
    const bNum = /^-?\d+(\.\d+)?$/.test(b);

    if (aNum && bNum) {
        return Number(a) - Number(b);
    }

    if (aNum) return -1;
    if (bNum) return 1;

    // Побайтовое сравнение, как strcmp().
    return a < b ? -1 : a > b ? 1 : 0;
}

/**
 * Каноническая строка, которую панель подаёт на HMAC.
 *
 * @param {object} message сообщение; поле sig исключается
 * @returns {string}
 */
export function canonicalPayload(message) {
    const rest = { ...message };
    delete rest.sig;

    const out = {};
    for (const key of Object.keys(rest).sort(compareKeys)) {
        out[key] = canonicalValue(rest[key]);
    }

    return JSON.stringify(out);
}

/**
 * Подпись сообщения.
 *
 * @param {object} message
 * @param {string} secret токен ноды
 * @returns {string} hex-подпись
 */
export function sign(message, secret) {
    return crypto
        .createHmac('sha256', secret)
        .update(canonicalPayload(message))
        .digest('hex');
}

/**
 * Проверка подписи входящего сообщения.
 *
 * Порядок проверок важен: длину сравниваем до timingSafeEqual, иначе
 * подпись произвольной длины бросит RangeError мимо try/catch вызывающего.
 *
 * @param {object} message
 * @param {string} secret токен ноды
 * @returns {boolean}
 */
export function verify(message, secret) {
    if (!message || typeof message !== 'object') return false;
    if (typeof message.sig !== 'string' || message.sig.length === 0) return false;

    const expected = sign(message, secret);
    const provided = message.sig;

    // Сравниваем и как строки, и как буферы: одинаковая длина исключает
    // и RangeError, и утечку по времени на длине подписи.
    if (provided.length !== expected.length) return false;

    return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(provided));
}