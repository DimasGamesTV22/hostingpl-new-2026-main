# Протокол панель ↔ агент

Транспорт — WebSocket (RFC 6455). Реализация без внешних библиотек:
`panel/app/Services/Agent/Ws/WebSocketServer.php`.

## Команды CLI агента

Агент запускается как сервис `gamedock-agent@N.service`, но у него есть
диагностический CLI — `agent/bin/gamedock-agent.js` (Node 20+, одна зависимость `ws`):

```bash
node bin/gamedock-agent.js doctor     # окружение, рантаймы, cgroups и связь с панелью
node bin/gamedock-agent.js health     # быстрая локальная проверка, БЕЗ сети
node bin/gamedock-agent.js runtimes   # какие рантаймы доступны на этой ноде
node bin/gamedock-agent.js test       # только WSS-соединение с панелью
node bin/gamedock-agent.js config     # эффективная конфигурация (секреты замаскированы)
node bin/gamedock-agent.js version
```

`doctor` и `test` ходят на панель — для разовой диагностики это нормально,
но **не ставьте их в `HEALTHCHECK`**: при кратковременной недоступности панели
контейнер будет помечен unhealthy и перезапущен. Для этого есть `health` —
она проверяет только локальное состояние (конфиг, каталоги, сокет рантайма)
и используется в `agent/docker/Dockerfile`.

## Модель соединения

```
Агент (нода)                          Панель
     │                                      │
     │  WSS connect                          │
     │  X-GameDock-Token: <токен ноды>      │
     ├─────────────────────────────────────►│  проверка токена (sha256 в nodes.token_hash)
     │                                      │
     │  event: hello                         │  ← регистрация ноды, проверка рантаймов
     ├─────────────────────────────────────►│
     │                                      │
     │  event: heartbeat   (каждые 5 с)     │  ← статус ноды, метрики системы
     ├─────────────────────────────────────►│
     │                                      │
     │◄─────────────────────────────────────┤  {rid, type, payload}   ← команда
     │                                      │
     │  {rid, ok, result, error}             │  ← ответ
     ├─────────────────────────────────────►│
     │                                      │
     │  event: metrics.push                 │  ← батчи точек
     ├─────────────────────────────────────►│
     │                                      │
     │  event: console.output               │  ← живая консоль
     ├─────────────────────────────────────►│
```

**Почему WSS, а не HTTP-запросы с панели к агенту:** у нод часто нет белого IP
(за NAT), а постоянное соединение даёт ещё и мгновенный канал для консоли
и команд. Панель при этом не держит процессов — соединения живут только
в одном `gamedock-wss`.

**Альтернатива** — HTTP-вебхуки: `POST /api/agent/heartbeat`, `/status`,
`/metrics`, `/console`, `/chat`. Работают с тем же токеном в заголовке
`X-Node-Token`. Используются, если WSS по какой-то причине недоступен.

## Аутентификация

При подключении агент передаёт токен ноды:

```
GET /agent/ws HTTP/1.1
Upgrade: websocket
X-GameDock-Token: 6f3a9c...
X-Node-Id: 1
X-Agent-Version: 1.0.0
```

Панель:
1. Считает `sha256(токен)`.
2. Ищет в `nodes.token_hash`. Не найден — закрывает соединение кодом `4003`.
3. Проверяет `nodes.is_active`. Выключена — `4003`.
4. Рвёт предыдущее соединение этой ноды (одна нода — одно соединение).
5. Пишет `nodes.last_heartbeat_at` и `nodes.inbound_ip`.

Коды закрытия:

| Код | Значение |
|---|---|
| `4001` | не передан токен |
| `4003` | токен не найден или нода выключена |
| `1000` | штатное закрытие |
| `1001` | панель перезапускается |
| `1006` | обрыв соединения |

Ротация токена: панель перестаёт пускать старый токен сразу при генерации нового.

## Подпись сообщений

Каждое сообщение подписывается HMAC-SHA256:

```js
// Агент и панель считают одинаково
const { sig, ...rest } = message;
const payload = Object.keys(rest)
    .sort()
    .map((key) => `${key}=${JSON.stringify(rest[key])}`)
    .join('&');

const signature = crypto
    .createHmac('sha256', token)
    .update(payload)
    .digest('hex');
```

Ключ — токен ноды. Защищает от подмены команд, даже если кто-то узнает
структуру протокола.

```json
{
  "event": "heartbeat",
  "data": { "node_id": 1, "system": { "memory_total_mb": 8192 } },
  "ts": 1767225600,
  "nonce": "3f9a1c2b8d7e4f10",
  "sig": "9b2c...64 hex"
}
```

Панель проверяет подпись **каждого** входящего сообщения. Без верной подписи
соединение закрывается.

## Команды (`type`)

Панель → агент. Поле `rid` — идентификатор запроса, агент возвращает его
в ответе.

### Система

| `type` | payload | ответ |
|---|---|---|
| `ping` | — | `{pong, uptime}` |
| `node.info` | — | версия, рантаймы, система, процессы |

### Жизненный цикл сервера

| `type` | payload | ответ |
|---|---|---|
| `server.create` | `{server: {...}, spec: {node: {...}}}` | `{server_id, external_id, path}` |
| `server.delete` | `{server_id, purge}` | `{ok}` |
| `server.install` | `{server_id, build, installer, bootstrap_files}` | `{steps, duration_ms}` |
| `server.update` | `{server_id, build}` | `{ok, build}` |
| `server.start` | `{server_id, via_rcon}` | `{started_at}` |
| `server.stop` | `{server_id, timeout}` | `{ok}` |
| `server.restart` | `{server_id}` | `{ok}` |
| `server.kill` | `{server_id}` | `{ok}` |
| `server.console.write` | `{server_id, command}` | `{intercepted}` |
| `server.console.read` | `{server_id, lines}` | `{lines: [...]}` |
| `server.query` | `{server_id}` | результат игрового протокола |
| `server.set_limits` | `{server_id, resources}` | `{ok, requires_restart}` |
| `console.subscribe` | `{server_id, buffer}` | `{buffer}` |
| `console.unsubscribe` | `{server_id}` | `{ok}` |

### Файлы

| `type` | payload |
|---|---|
| `files.list` | `{server_id, path}` |
| `files.read` | `{server_id, path, max_bytes}` |
| `files.write` | `{server_id, path, content}` (content в base64) |
| `files.delete` | `{server_id, path, recursive}` |
| `files.mkdir` | `{server_id, path}` |
| `files.rename` | `{server_id, from, to}` |
| `files.search` | `{server_id, query, path}` |
| `files.upload` | `{server_id, path, content}` |
| `files.download` | `{server_id, path}` |

### Логи и бэкапы

| `type` | payload |
|---|---|
| `logs.read` | `{server_id, lines}` / `{server_id, file}` / `{server_id, query}` |
| `backup.create` | `{server_id, name, exclude, upload, s3, snapshot_id}` |
| `backup.restore` | `{server_id, path, stop}` |
| `backup.delete` | `{server_id, path}` |
| `backup.list` | `{server_id}` |

### Плагины

| `type` | payload |
|---|---|
| `template.install` | `{server_id, template: {name, source_type, source_url, target_path, ...}}` |
| `template.remove` | `{server_id, template: {name, target_path}}` |

## События (`event`)

Агент → панель.

| `event` | Когда | payload |
|---|---|---|
| `hello` | сразу после подключения | `{version, node_id, runtimes, started_at}` |
| `heartbeat` | каждые 5 с | `{node_id, running_servers, total_servers, system}` |
| `sync.request` | после подключения | агент просит список серверов |
| `metrics.push` | каждые 5 с | `{node_id, servers: [{server_id, points: [...]}]}` |
| `server.status` | смена статуса | `{server_id, status, reason}` |
| `server.crashed` | падение процесса | `{server_id, reason, uptime}` |
| `console.output` | строка в консоли | `{server_id, stream, text, type, ts}` |
| `console.stats` | статистика | `{server_id, stats}` |
| `install.progress` | ход установки | `{server_id, progress, log}` |
| `install.step` | шаг установки | `{server_id, step, name, status, duration_ms}` |
| `install.completed` | установка окончена | `{server_id, external_id, files, duration_ms}` |
| `install.failed` | ошибка установки | `{server_id, error, step}` |
| `backup.progress` | ход бэкапа | `{server_id, name, progress}` |
| `backup.completed` | бэкап готов | `{server_id, snapshot_id, name, path, size, checksum, s3_key, uploaded}` |
| `backup.failed` | ошибка бэкапа | `{server_id, name, error}` |
| `update.completed` | игра обновлена | `{server_id, build}` |
| `chat.message` | строка чата с префиксом кода | `{server_id, text, player_id, player_name}` |
| `node.alert` | предупреждение ноды | `{type, data}` |
| `agent.stopping` | остановка агента | `{reason}` |

## Специальные значения статуса

| Статус | Значение |
|---|---|
| `pending` | создан, установка ещё не началась |
| `installing` | идёт установка/обновление |
| `installed` | установлен, не запущен |
| `starting` | запускается (ждём query-ответа) |
| `running` | работает |
| `stopping` | останавливается |
| `stopped` | остановлен |
| `crashed` | процесс завершился сам по себе |
| `error` | ошибка установки или управления |
| `suspended` | заморожен за неоплату |
| `deleting` | удаляется |

Панель переводит сервер в `running` только после того, как игровой протокол
ответил на query-запрос. Если процесс жив, но query молчит — сервер остаётся
в `starting`, и через 10 минут `ServerReaper` переведёт его в `stopped`.

## Поток консоли

```
Агент ловит stdout/stderr
    └─► event: console.output  (каждая строка)
            └─► панель: ConsoleBroadcaster::push() → Redis
                    ├─► SSE-поток в браузер (уже открытые вкладки)
                    └─► кольцевой буфер (2000 строк) — при открытии консоли
```

Поток не теряется: если SSE отвалился, при следующем подключении агент
пришлёт буфер (`console.subscribe` возвращает `buffer`).

SSE-контроллер держит соединение 300 секунд (параметр `?timeout=`),
после чего браузер переподключается автоматически. В nginx обязательно
`proxy_buffering off` для маршрута `/panel/servers/*/console/stream` —
иначе поток не дойдёт.

## Очередь команд

Команды из HTTP-запросов и воркеров не могут использовать сокет —
они в других процессах. Поэтому:

1. `AgentClient::send()` кладёт сообщение в Redis-список `gamedock:agent:out:{node_id}`.
2. Тик WSS-сервера (раз в 50 мс) забирает список через `lpop` и отправляет в сокет.
3. Ответ агента возвращается по `rid` и пишется в `server_commands` с
   финальным статусом (`done` / `failed`).

Если ноды нет в памяти WSS-сервера, команда остаётся в очереди и уйдёт,
как только нода подключится. Веб-интерфейс показывает «ожидает ответа».

## Heartbeat и определение офлайна

```
Агент шлёт heartbeat каждые 5 секунд
Панель: nodes.last_heartbeat_at = now()

Раз в минуту планировщик:
  if last_heartbeat_at < now() - 25 сек  →  nodes.status = "offline"
  если нода была онлайн → алерт в Telegram
```

`offline_after` настраивается: `config/hosting.php → agent.offline_after`.

## Секреты в командах

RCON-пароль панель шифрует (`openssl_encrypt` через `App\Support\Crypto`) и
передаёт как `RCON_PASSWORD=enc:<шифротекст>`. Агент снимает префикс `enc:`
и подставляет в переменные окружения процесса.

Путь: панель (`Crypto::encrypt`) → JSON → агент (`enc:` → значение) → env процесса.

## Защита от повторов

Каждое сообщение содержит `ts` (секунды) и `nonce` (случайные 8 байт).
Панель может отклонять сообщения с `ts` старше 60 секунд и вести
LRU-кэш `nonce` на 5 минут — это уже встроено в `WebSocketServer`
для frames, и рекомендуется включить на уровне прикладного кода,
если сеть ненадёжна.

## Версионирование

В `hello` агент сообщает `version`. Панель может вести список поддерживаемых:

```php
// config/hosting.php
'agent' => [
    'min_version' => '1.0.0',
],
```

Панель логирует версию в `nodes.agent_version` и показывает в админке.
При несовпадении минимальной версии нода помечается предупреждением —
новые поля в протоколе могут не работать на старом агенте.
