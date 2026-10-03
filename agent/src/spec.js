/**
 * Приведение спецификации сервера к виду, который ждут рантаймы.
 *
 * Панель присылает payload вида {server, spec}:
 *   spec   — общие для всех с��лужебные данные (имя, пользователь, корни, нода);
 *   server — сам сервер (игра, стартовая команда, порты, лимиты, секреты).
 *
 * Драйверы читают всё с верхнего уровня: `spec.game`, `spec.startup`,
 * `spec.ports`, `spec.resources`, `spec.env`, плюс `spec.path`. Раньше этих
 * полей там не было, и рантаймы работали с undefined: docker монтировал
 * «undefined:/home/server», native падал на mkdir(undefined), а игра
 * стартовала командой по умолчанию вместо заданной панелью.
 *
 * Каталог сервера панель не знает — он вычисляется агентом, поэтому
 * добавляется здесь.
 *
 * Вложенный `server` сохраняется: агенту он нужен для идентификации и сверки.
 * Объекты копируются, а не берутся по ссылке, — иначе изменение
 * спецификации молча портило бы исходный payload вызывающего кода.
 *
 * Модуль чистый: без файловой системы и без состояния, поэтому его покрывают
 * тесты (tests/spec.test.js).
 */

/**
 * @param {object} payload то, что прислала панель: {server, spec}
 * @param {string} dir каталог сервера, вычисленный агентом
 * @param {number|string|null} [fallbackNodeId] подставляется, если нода не пришла
 * @returns {object} спецификация для рантаймов
 */
export function buildRuntimeSpec(payload, dir, fallbackNodeId = null) {
    const source = payload && typeof payload === 'object' ? payload : {};

    // Служебные данные панели разворачиваем на верхний уровень — так их
    // и ждут драйверы и остальной код агента.
    const shared = source.spec && typeof source.spec === 'object' ? source.spec : {};

    const rawServer = source.server && typeof source.server === 'object' ? source.server : {};
    const server = { ...rawServer };

    // Игру копируем отдельно: её часто меняют на лету (сборка, версия).
    const game = rawServer.game && typeof rawServer.game === 'object' ? { ...rawServer.game } : rawServer.game;

    return {
        ...shared,

        // Каталог знает только агент.
        path: dir,

        // Дублируем с server на верхний уровень — так их читают все драйверы.
        game,
        startup: rawServer.startup,
        ports: rawServer.ports,
        resources: rawServer.resources,
        env: rawServer.env,
        slots: rawServer.slots,
        watchdog: rawServer.watchdog,

        // Нода нужна драйверам для сверки; если панель её не прислала —
        // подставляем собственную ноду агента.
        node: shared.node || (fallbackNodeId !== null ? { id: fallbackNodeId } : {}),

        server,
    };
}

/**
 * Секреты приходят с панели с префиксом «enc:». Снимаем его.
 *
 * Вызывается до buildRuntimeSpec, чтобы и вложенный server.env, и его копия
 * на верхнем уровне оказались расшифрованы.
 *
 * @param {object} env
 * @param {string} prefix
 * @returns {object}
 */
export function decodeEnv(env, prefix = 'enc:') {
    if (!env || typeof env !== 'object') return {};

    const out = {};
    for (const [key, value] of Object.entries(env)) {
        out[key] = typeof value === 'string' && value.startsWith(prefix)
            ? value.slice(prefix.length)
            : value;
    }
    return out;
}

/**
 * Снимает префикс «enc:» со всех секретов сервера прямо в payload.
 *
 * Мутирует переданный server.env — так делал handleServerCreate до
 * появления buildRuntimeSpec. Оставлено отдельной функцией, чтобы место
 * снятия шифрования было ровно одно.
 *
 * @param {object} payload
 * @returns {object} тот же payload
 */
export function decodePayloadEnv(payload) {
    if (!payload || !payload.server || typeof payload.server !== 'object') return payload;

    payload.server.env = decodeEnv(payload.server.env);
    return payload;
}