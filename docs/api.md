# API

Три группы маршрутов:

| Префикс | Авторизация | Для кого |
|---|---|---|
| `/api/*` | Sanctum-токен | интеграции, CI/CD, бот |
| `/api/agent/*` | токен ноды | агент (альтернатива WSS) |
| `/api/v1/*` | не нужна | публичные витрины |

## Получение токена

**Панель → Профиль → API-токены → Создать**. Токен показывается один раз:

```
gd_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Права (`abilities`) выбираются при создании:
`*`, `servers:read`, `servers:write`, `servers:control`, `billing:read`, `profile:read`.
Дополнительно можно задать белый список IP и срок жизни.

Запросы:

```http
GET /api/servers
Authorization: Bearer gd_xxxx
Accept: application/json
```

Ответ всегда JSON. Ошибки — стандартные:

```json
{ "message": "Недостаточно средств на балансе", "errors": { "amount": ["…"] } }
```

Коды: `401` нет/невалидный токен, `403` нет прав, `404` не найдено,
`422` валидация, `429` rate limit, `503` нода недоступна.

---

## Пользователь

### `GET /api/me`

```json
{
  "data": {
    "id": 5, "name": "Иван", "email": "ivan@example.com", "role": "user",
    "balance": 500.00, "currency": "RUB",
    "trial_ends_at": null, "two_factor_enabled": true,
    "created_at": "2026-01-05T10:12:00+00:00"
  }
}
```

### `PUT /api/me`

Принимает `name`, `username`, `email`, `contact_telegram`, `locale`, `timezone`, `about`.

### `GET /api/me/summary`

Сводка для дашборда: баланс, количество серверов, сумма за период, последние операции.

---

## Серверы

### `GET /api/servers?status=running&limit=50`

Поля каждого сервера: `id`, `uuid`, `name`, `status`, `status_reason`, `game`, `address`,
`game_port`, `query_port`, `rcon_port`, `slots`, `players`, `resources`, `usage`,
`expires_at`, `is_frozen`, `created_at`.

### `POST /api/servers`

```json
{
  "name": "My CS2",
  "game_id": 3,
  "tariff_id": 4,
  "memory_mb": 4096,
  "disk_mb": 51200,
  "slots": 24,
  "cpu_percent": 100,
  "build": null
}
```

`201 Created` + объект сервера. Установка идёт в фоне — следите за
`status` (`installing` → `installed`) и `install_progress`.

Значения по умолчанию берутся из игры; квоты тарифа не дают превысить лимиты.
Если подходящей ноды нет — `422` с текстом «Нет ноды со свободными ресурсами».

### `GET /api/servers/{id}`

Дополнительно: `build_version`, `startup_command`, `node`, `tariff`, `install_progress`.

### `PUT /api/servers/{id}`

Меняет `name`, `memory_mb`, `slots`, `disk_mb`, `cpu_percent`, `watchdog_enabled`.
Требуется право `servers:write` (для `servers:control` — только питание).

### `DELETE /api/servers/{id}`

`204 No Content`. Удаляет сервер и его файлы.

### Питание

```
POST /api/servers/{id}/start
POST /api/servers/{id}/stop
POST /api/servers/{id}/restart
POST /api/servers/{id}/kill
```

Ответ: `{ "ok": true, "status": "starting" }`. Операции асинхронные — итоговый статус
смотрите через `GET /api/servers/{id}`.

### `GET /api/servers/{id}/console?lines=200`

```json
{
  "data": [
    { "stream": "stdout", "text": "[12:00:01] [Server thread/INFO]: Done", "ts": 1767213601 }
  ],
  "stats": { "cpu": 12.5, "memory": 812345678, "players": 7, "slots": 24 }
}
```

### `POST /api/servers/{id}/console`

```json
{ "command": "say Hello" }
```

Возвращает `{ "ok": true, "queued": false }`. `queued: true` означает, что нода офлайн и
команда встала в очередь — выполнится после reconnect.

### `GET /api/servers/{id}/metrics?range=1h`

`range`: `15m`, `1h`, `6h`, `24h`, `7d`, `30d`.

```json
{
  "data": [ { "t": 1767210000, "cpu": 8.2, "memory": 812, "players": 6, "disk": 10240 } ],
  "range": "1h",
  "current": { "cpu": 8.2, "memory_mb": 812, "players": 6, "slots": 24, "uptime": 7200 }
}
```

### `GET /api/servers/{id}/files?path=plugins`

```json
{
  "path": "plugins",
  "entries": [
    { "name": "EssentialsX.jar", "path": "plugins/EssentialsX.jar", "type": "file",
      "size": 2456789, "modified": 1767210000 }
  ]
}
```

`type`: `file` или `dir`. Скрытые файлы и защищённые пути не отдаются.

### `GET /api/servers/{id}/events?limit=50`

Журнал событий сервера: `type`, `level`, `title`, `context`, `created_at`.

### Бэкапы

```
GET  /api/servers/{id}/backups     → список снапшотов
POST /api/servers/{id}/backups     → { "name": "перед-обновой" }
```

---

## Каталог

```
GET /api/games         → активные публичные игры
GET /api/games/{slug}  → игра + тарифы + сборки
GET /api/tariffs       → тарифы
GET /api/tariffs/{slug}→ тариф + цены докупок
```

---

## Секретные коды

```
GET  /api/secret-codes        → коды, доступные текущему пользователю
POST /api/secret-codes/redeem → { "code": "GOLD2026" }
```

Ответ:

```json
{ "ok": true, "applied": { "rub": 500, "days_added": 0, "slots": 0 } }
```

---

## Токены

```
GET    /api/tokens
POST   /api/tokens   { "name": "CI", "abilities": ["servers:read"], "expires_in": 90 }
DELETE /api/tokens/{id}
```

Секретный токен приходит один раз в поле `token` ответа `POST`.

---

## Публичное API (`/api/v1`) — без токена

### `GET /api/v1/games`

```json
{ "data": [ { "id": 1, "slug": "minecraft-java", "name": "Minecraft Java",
              "family": "minecraft", "slots_range": "1–2000",
              "price_per_slot": "5 ₽", "icon": "…" } ] }
```

### `GET /api/v1/tariffs`

Тарифы с квотами и ценами докупок — годится для ресайна/лендинга.

### `GET /api/v1/status`

Публичный мониторинг: список серверов с онлайном и аптаймом.

```
GET /api/v1/status?game=cs2&q=arena&limit=100
```

### `GET /api/v1/status/nodes`

```json
{ "data": [ { "id": 1, "name": "node-1", "status": "online", "region": "europe",
              "servers": 42, "uptime": 99.98 } ] }
```

### `GET /api/v1/stats`

```json
{ "data": { "servers": 120, "players": 843, "users": 5100,
            "games": 12, "nodes": 4, "uptime": 99.97 } }
```

Кэшируется 60 секунд — годится для бейджа на лендинге.

---

## Webhooks пользователя

**Профиль → Вебхуки**. Поддерживаемые события:

```
server.created  server.deleted  server.started  server.stopped  server.crashed
server.expiring server.suspended server.resumed
payment.succeeded payment.failed
user.registered user.blocked
backup.completed
node.offline node.online
```

Панель шлёт `POST` с телом:

```json
{
  "event": "server.crashed",
  "created_at": "2026-01-05T12:00:00+00:00",
  "data": { "server_id": 42, "name": "My CS2", "game": "cs2", "status": "crashed" }
}
```

Заголовки подписи:

```
X-Gamedock-Event: server.crashed
X-Gamedock-Secret: <секрет вебхука>
X-Gamedock-Signature: sha256=<hmac_sha256(secret, body)>
```

Проверка подписи:

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $webhook->secret);
if (! hash_equals($expected, $request->header('X-Gamedock-Signature'))) {
    abort(401);
}
```

---

## Вебхуки агента (HTTP-режим)

Нужны нодам без постоянного WSS-соединения. Заголовок авторизации —
`X-Agent-Token: <токен ноды>` (тот же, что агент использует для WSS).

```
POST /api/agent/heartbeat   раз в 5–10 с
POST /api/agent/status      смена статуса сервера
POST /api/agent/metrics     батч точек метрик
POST /api/agent/console     строки консоли пачкой
POST /api/agent/install     прогресс установки
POST /api/agent/chat        перехват чата (секретные коды)
POST /api/agent/backup      результат бэкапа
```

Формат полей и подписи — в [agent-protocol.md](agent-protocol.md).

---

## Лимиты и рекомендации

- По умолчанию 60 запросов в минуту на токен и 60 на IP (`throttle:api`).
- Увеличить можно в `App\Providers\AppServiceProvider` → `RateLimiter::for('api')`.
- Списки (`/servers`, `/backups`, `/events`) уже ограничены `limit` — не тяните большие объёмы.
- Консоль: длинный поток удобнее получать по SSE из панели, а не опрашивать REST.

## Примеры

### cURL

```bash
curl -s https://panel.example.com/api/servers \
  -H "Authorization: Bearer gd_xxxx" | jq '.data[] | {id, name, status, players}'
```

### Node.js

```js
const headers = { Authorization: `Bearer ${process.env.GD_TOKEN}` };

const list = await fetch('https://panel.example.com/api/servers', { headers })
    .then(r => r.json());

for (const s of list.data) {
    if (s.status === 'crashed') {
        await fetch(`https://panel.example.com/api/servers/${s.id}/restart`, {
            method: 'POST', headers,
        });
    }
}
```

### Автопилот в Telegram

```js
// Раз в минуту: если онлайн меньше 10 % от слотов — уведомить
setInterval(async () => {
    const { data } = await fetch(`${PANEL}/api/servers?status=running`, { headers })
        .then(r => r.json());

    for (const s of data) {
        if (s.slots > 0 && s.players / s.slots < 0.1) {
            await sendToTelegram(`«${s.name}» почти пустой: ${s.players}/${s.slots}`);
        }
    }
}, 60_000);
```

### Скрипт деплоя (CI)

```bash
#!/usr/bin/env bash
set -euo pipefail
PANEL=https://panel.example.com
TOKEN=$GD_TOKEN

json() { curl -fsS -H "Authorization: Bearer $TOKEN" "$@"; }

id=$(json "$PANEL/api/servers" | jq -r '.data[] | select(.name=="prod") | .id')

curl -fsS -X POST -H "Authorization: Bearer $TOKEN" \
  "$PANEL/api/servers/$id/restart" >/dev/null

echo "restarted $id"
```
