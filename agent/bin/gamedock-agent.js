#!/usr/bin/env node
/**
 * CLI агента GameDock.
 *
 *   gamedock-agent start                 запустить демон (по умолчанию)
 *   gamedock-agent config                показать эффективный конфиг
 *   gamedock-agent doctor                проверить окружение и связь с панелью
 *   gamedock-agent runtimes              какие рантаймы доступны
 *   gamedock-agent test                  проверить WSS-соединение с панелью
 *   gamedock-agent version
 *
 * Любой параметр конфигурации переопределяется флагом или переменной окружения:
 *   gamedock-agent start --panel=https://panel.example.com --nodeId=1 --token=…
 *   GD_PANEL=… GD_TOKEN=… GD_NODE_ID=… gamedock-agent start
 *
 * Справка: gamedock-agent --help
 */

import process from 'node:process';
import { existsSync } from 'node:fs';
import { execFile, spawn } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const run = promisify(execFile);

/* ── Цвета ─────────────────────────────────────────────────────────────── */

const useColor = process.stdout.isTTY && !process.env.NO_COLOR;

const c = new Proxy({}, {
    get: (_, code) => (useColor ? `\x1b[${code}m` : ''),
});

const out = (s = '') => process.stdout.write(`${s}\n`);

const log = {
    ok: (m) => out(`${c[32]}✓${c[0]} ${m}`),
    warn: (m) => out(`${c[33]}!${c[0]} ${m}`),
    err: (m) => process.stderr.write(`${c[31]}✗${c[0]} ${m}\n`),
    info: (m) => out(`${c[36]}›${c[0]} ${m}`),
    dim: (m) => out(`${c[2]}${m}${c[0]}`),
    title: (t) => out(`\n${c[1]}${t}${c[0]}`),
};

/* ── Аргументы ─────────────────────────────────────────────────────────── */

function parseArgs(argv) {
    const positional = [];
    const flags = {};
    const passthrough = [];

    for (const arg of argv) {
        if (arg.startsWith('--')) {
            const [key, ...rest] = arg.slice(2).split('=');
            flags[key] = rest.length ? rest.join('=') : true;
            passthrough.push(arg);
        } else {
            positional.push(arg);
        }
    }

    return { positional, flags, passthrough };
}

/** loadConfig() бросает исключение, если нет токена — для служебных команд это не помеха. */
function loadConfigSafe(configModule, passthrough) {
    const { loadConfig } = configModule;

    try {
        return { config: loadConfig(passthrough), error: null };
    } catch (e) {
        // Повторяем с пустым токеном, чтобы всё же показать остальные параметры
        try {
            return { config: loadConfig([...passthrough, '--token=__unset__']), error: e.message };
        } catch {
            return { config: null, error: e.message };
        }
    }
}

/* ── Команды ───────────────────────────────────────────────────────────── */

async function cmdConfig(flags, configModule) {
    const { config, error } = loadConfigSafe(configModule, flags.raw);

    if (!config) {
        log.err(error);
        return 1;
    }

    const hide = new Set(['token', 'password', 'secret']);

    const walk = (obj, prefix = '') => {
        for (const [key, value] of Object.entries(obj)) {
            const keyPath = prefix ? `${prefix}.${key}` : key;

            if (key === 'configFile') continue;

            if (value && typeof value === 'object' && !Array.isArray(value)) {
                walk(value, keyPath);
            } else if (hide.has(key.toLowerCase()) && value) {
                out(`  ${keyPath.padEnd(36)}${c[2]}${'•'.repeat(String(value).length)}${c[0]}`);
            } else {
                out(`  ${keyPath.padEnd(36)}${value}`);
            }
        }
    };

    log.title('GameDock agent — эффективная конфигурация');
    if (error) log.warn(error.split('\n')[0]);
    walk(config);
    out('');
    return 0;
}

async function cmdRuntimes(flags, configModule) {
    const { config } = loadConfigSafe(configModule, flags.raw);
    if (!config) return 1;

    const { createRuntimes, probeRuntimes } = await import('../src/runtimes/index.js');
    const { createLogger } = await import('../src/logger.js');

    const logger = createLogger({ level: 'error' });
    const runtimes = await createRuntimes(config, logger);
    const probes = await probeRuntimes(runtimes, logger);

    log.title('Доступные рантаймы');

    const names = Object.keys(probes);
    if (names.length === 0) {
        log.err('Ни один рантайм не найден. Установите docker или podman либо включите cgroup v2.');
        return 1;
    }

    for (const name of names) {
        const p = probes[name];
        if (p.ok) {
            log.ok(`${name.padEnd(8)} ${p.version ?? ''} ${c[2]}${p.path ?? ''}${c[0]}`);
        } else {
            log.warn(`${name.padEnd(8)} недоступен — ${p.reason}`);
        }
    }

    out('');
    return names.includes(config.runtime) ? 0 : 1;
}

async function cmdDoctor(flags, configModule) {
    const { config, error } = loadConfigSafe(configModule, flags.raw);

    if (!config) {
        log.err(error);
        return 1;
    }

    let problems = 0;

    log.title('Панель');
    if (error) {
        log.warn(error.split('\n')[0]);
        problems += 1;
    }
    log.info(`WSS: ${maskToken(config.wsUrl || '')}`);
    log.dim(`конфиг: ${config.configFile ?? '(встроенные значения)'}`);

    const probe = await testConnection(config);
    if (probe.ok) {
        log.ok(`соединение установлено ${c[2]}(агент v${probe.version ?? '?'})${c[0]}`);
    } else {
        log.err(`не удалось подключиться: ${probe.error}`);
        problems += 1;
    }

    log.title('Пути');
    for (const [name, dir] of [
        ['servers_root', config.serversRoot],
        ['backups_root', config.backupsRoot],
        ['templates_root', config.templatesRoot],
    ]) {
        if (!existsSync(dir)) {
            log.warn(`${name.padEnd(15)} ${dir} ${c[31]}не существует${c[0]}`);
            problems += 1;
        } else {
            log.ok(`${name.padEnd(15)} ${dir}`);
        }
    }

    log.title('Ресурсы');
    const { collectSystem } = await import('../src/metrics/system.js');
    const metrics = await collectSystem().catch(() => null);

    if (metrics) {
        log.ok(`CPU: ${metrics.cpu?.cores ?? '?'} ядер, load1=${Number(metrics.cpu?.load1 ?? 0).toFixed(2)}`);
        log.ok(`RAM: ${gb(metrics.memory?.total)} всего, ${gb(metrics.memory?.available)} свободно`);
        log.ok(`Диск: ${gb(metrics.disk?.free)} свободно из ${gb(metrics.disk?.total)}`);
    } else {
        log.warn('не удалось собрать метрики системы');
    }

    log.title('Рантаймы');
    const { createRuntimes, probeRuntimes } = await import('../src/runtimes/index.js');
    const { createLogger } = await import('../src/logger.js');
    const probes = await probeRuntimes(await createRuntimes(config, createLogger({ level: 'error' })), createLogger({ level: 'error' }));

    const usable = Object.entries(probes).filter(([, p]) => p.ok).map(([n]) => n);

    for (const [name, p] of Object.entries(probes)) {
        if (p.ok) log.ok(`${name} ${c[2]}${p.version ?? ''}${c[0]}`);
        else log.warn(`${name} недоступен — ${p.reason}`);
    }

    if (usable.length === 0) {
        log.err(`рантайм "${config.runtime}" недоступен — серверы запустить не удастся`);
        problems += 1;
    } else if (!usable.includes(config.runtime)) {
        log.err(`рантайм по умолчанию "${config.runtime}" недоступен (есть: ${usable.join(', ')})`);
        problems += 1;
    }

    log.title('cgroups');
    const { stdout } = await run('sh', ['-c', 'stat -fc %T /sys/fs/cgroup/']).catch(() => ({ stdout: '' }));

    if (stdout.trim() === 'cgroup2fs') {
        log.ok('cgroup v2 (unified)');
    } else {
        log.warn(`ожидался cgroup2fs, получено "${stdout.trim() || '?'}" — native/lxc работать не будут`);
        problems += 1;
    }

    out('');
    if (problems === 0) {
        log.ok('Всё в порядке.');
        return 0;
    }

    log.warn(`проблем найдено: ${problems}`);
    return 1;
}

async function testConnection(config) {
    const { WebSocket } = await import('ws');

    const base = String(config.panel).replace(/\/+$/, '');
    const wsPath = config.wsPath?.startsWith('/') ? config.wsPath : `/${config.wsPath ?? '/agent/ws'}`;
    const url = config.wsUrl || `${base.replace(/^http/, 'ws')}${wsPath}`;

    return new Promise((resolve) => {
        const ws = new WebSocket(`${url}?node=${config.nodeId}&token=${config.token}`, {
            headers: {
                Authorization: `Bearer ${config.token}`,
                'X-Node-Id': String(config.nodeId ?? ''),
                'User-Agent': 'gamedock-agent/doctor',
            },
            handshakeTimeout: 8000,
        });

        const done = (result) => {
            clearTimeout(timer);
            try {
                ws.close();
            } catch { /* noop */ }
            resolve(result);
        };

        const timer = setTimeout(() => done({ ok: false, error: 'таймаут рукопожатия' }), 10000);

        ws.on('open', () => ws.send(JSON.stringify({ type: 'ping', id: 'doctor', ts: Date.now() })));

        ws.on('message', (raw) => {
            let msg;
            try {
                msg = JSON.parse(raw.toString());
            } catch {
                return;
            }

            if (msg.type === 'pong' || msg.type === 'hello' || msg.type === 'auth.ok') {
                done({ ok: true, version: msg.version });
            } else if (msg.type === 'error') {
                done({ ok: false, error: msg.message || 'панель отклонила подключение' });
            }
        });

        ws.on('error', (e) => done({ ok: false, error: e.message }));
        ws.on('close', (code) => done({ ok: false, error: `соединение закрыто (code ${code})` }));
    });
}

async function cmdStart(passthrough) {
    // src/index.js сам поднимает агента и вешает обработчики сигналов,
    // поэтому запускаем его отдельным процессом — так же, как это делает systemd.
    const child = spawn(process.execPath, [
        path.join(__dirname, '..', 'src', 'index.js'),
        ...passthrough,
    ], {
        stdio: 'inherit',
        env: process.env,
    });

    const forward = (signal) => () => child.kill(signal);
    process.on('SIGINT', forward('SIGINT'));
    process.on('SIGTERM', forward('SIGTERM'));

    child.on('exit', (code, signal) => {
        process.exit(signal ? 1 : (code ?? 0));
    });
}

/* ── Утилиты ───────────────────────────────────────────────────────────── */

const gb = (bytes) => (bytes === undefined || bytes === null ? '—' : `${(bytes / 1024 ** 3).toFixed(1)} ГБ`);

function maskToken(value) {
    return String(value).replace(/(token=)[^&]+/, '$1***');
}

const HELP = `
${c[1]}GameDock agent${c[0]} — управление игровыми серверами на ноде

${c[1]}Использование:${c[0]}
  gamedock-agent [команда] [опции]

${c[1]}Команды:${c[0]}
  start      запустить агент (команда по умолчанию)
  config     показать эффективную конфигурацию
  doctor     проверить окружение, рантаймы и связь с панелью
  health     быстрая локальная проверка (без сети) — для HEALTHCHECK
  runtimes   список доступных рантаймов
  test       проверить WSS-соединение с панелью
  version    версия агента

${c[1]}Опции:${c[0]}
  --config=<path>   путь к файлу конфигурации
  --panel=<url>     адрес панели
  --token=<token>   токен ноды (выдаёт панель: Админка → Ноды)
  --nodeId=<id>     ID ноды в панели
  --runtime=<name>  рантайм по умолчанию: docker | podman | lxc | native
  --serversRoot=<p> корневой каталог серверов
  --verbose         подробные логи
  --help, -h        эта справка

${c[1]}Файлы конфигурации (по приоритету):${c[0]}
  $GD_AGENT_CONFIG
  ./agent.config.json
  /etc/gamedock/agent.json
  ~/.gamedock/agent.json

${c[1]}Переменные окружения:${c[0]}
  GD_PANEL, GD_TOKEN, GD_NODE_ID, GD_RUNTIME,
  GD_SERVERS_ROOT, GD_BACKUPS_ROOT, GD_TEMPLATES_ROOT,
  GD_SYSTEM_USER, GD_DOCKER_SOCKET
`;

/**
 * Быстрая проверка «живости» без сети — для HEALTHCHECK в Docker.
 *
 * В отличие от `doctor` не ходит на панель: связь с ней может временно
 * пропасть, и контейнер не должен из-за этого перезапускаться.
 * Проверяем только локальное: конфиг, каталоги, доступность рантайма.
 */
async function cmdHealth(flags, configModule) {
    const { config, error } = loadConfigSafe(configModule, flags.raw);

    if (!config) {
        log.err(error);
        return 1;
    }

    const problems = [];

    for (const [name, dir] of [
        ['servers_root', config.serversRoot],
        ['backups_root', config.backupsRoot],
    ]) {
        if (!existsSync(dir)) {
            problems.push(`${name} не найден: ${dir}`);
        }
    }

    // Сокет рантайма — локальная проверка, сеть не нужна.
    // У Podman сокет задан как URL (unix:///...), у Docker — как путь.
    if (config.runtime === 'docker' || config.runtime === 'podman') {
        const raw = config[config.runtime]?.socket;
        if (raw) {
            const path = raw.replace(/^unix:\/\//, '');
            if (!existsSync(path)) {
                problems.push(`сокет ${path} недоступен`);
            }
        }
    }

    if (problems.length > 0) {
        for (const p of problems) log.err(p);
        return 1;
    }

    log.ok('ok');
    return 0;
}

/* ── Точка входа ───────────────────────────────────────────────────────── */

async function main() {
    const { positional, flags, passthrough } = parseArgs(process.argv.slice(2));

    const configModule = await import('../src/config.js');

    if (flags.help || flags.h || positional[0] === 'help') {
        out(HELP);
        return 0;
    }

    if (flags.version || positional[0] === 'version') {
        const pkg = JSON.parse(
            (await import('node:fs')).readFileSync(path.join(__dirname, '..', 'package.json'), 'utf8'),
        );
        out(`gamedock-agent ${pkg.version}`);
        return 0;
    }

    const command = positional[0] ?? 'start';
    const withRaw = { ...flags, raw: passthrough };

    switch (command) {
        case 'config':
            return cmdConfig(withRaw, configModule);
        case 'doctor':
            return cmdDoctor(withRaw, configModule);
        case 'runtimes':
            return cmdRuntimes(withRaw, configModule);
        case 'health':
            return cmdHealth(withRaw, configModule);
        case 'test':
            return (await testConnection(loadConfigSafe(configModule, passthrough).config)).ok ? 0 : 1;
        case 'start':
            await cmdStart(passthrough);
            return undefined;
        default:
            process.stderr.write(`Неизвестная команда: ${command}\n`);
            out(HELP);
            return 1;
    }
}

main()
    .then((code) => {
        if (typeof code === 'number' && code !== 0) process.exit(code);
    })
    .catch((err) => {
        log.err(err?.stack || String(err));
        process.exit(1);
    });
