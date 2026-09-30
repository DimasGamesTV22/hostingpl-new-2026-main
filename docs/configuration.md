# Конфигурация

Все настройки панели живут в **одном файле** — `panel/config/hosting.php`.
Любой параметр оттуда можно переопределить из админки без правки кода.

## Приоритет значений

```
таблица settings (админка)   ← высший приоритет, меняется в браузере
        ↓ если ключа нет
config/hosting.php           ← «файл выбора», правится вручную и через установщик
        ↓ если ключа нет
config/*.php (Laravel)       ← стандартные настройки фреймворка
        ↓
значение по умолчанию в setting(...)
```

Правило чтения: `setting('hosting.runtime.default', 'docker')`.

Ключи в БД совпадают с путями в файле:
`hosting.runtime.default` → `config/hosting.php → 'runtime' → 'default'`.

Кэш значений — 5 минут. После правки файла:

```bash
cd /opt/gamedock/panel
sudo -u gamedock php artisan config:clear
```

После правки в админке кэш сбрасывается автоматически.

---

## Выбор режима работы с нодами

`config/hosting.php → node_mode`

| Режим | Как работает | Когда ставить |
|---|---|---|
| `single` | Панель не выбирает ноду, берёт единственную | Тест, всё на одном VPS |
| `manual` | Ноду выбирает администратор при создании сервера | Разные игры на разных нодах, ручной контроль |
| `auto` | Панель сама подбирает ноду по свободным ресурсам | Продакшен с несколькими нодами |

Логика выбора в `App\Services\Nodes\NodeScheduler`:

1. Отсекаем ноды: выключенные, офлайн, на обслуживании, с исчерпанным лимитом серверов.
2. Отсекаем ноды без нужного рантайма (`runtime_options` агента).
3. Отсекаем ноды, где не хватает свободной RAM или диска под запрос.
4. Фильтруем по региону (`region`), если сервер создаётся с указанием региона.
5. Сортируем по баллу и берём лучший:

```
score = (свободная_память × 0.45 + свободный_диск × 0.20 + свободные_слоты × 0.20 + 0.15)
        × weight/100 + region_priority/100
```

`weight` (1–1000) — при равных условиях эта нода получит больше серверов.
`region_priority` (0–100) — ручной бонус ноде.

Если ни одна нода не подошла, пользователь видит конкретную причину:
«Ни на одной ноде нет 6 ГБ свободной памяти», «Все ноды офлайн» и т. д.

---

## Выбор рантайма

`config/hosting.php → runtime`

| Рантайм | Как работает | Требования к ноде | Когда ставить |
|---|---|---|---|
| `docker` | Игровой процесс в контейнере | Docker Engine 26+, cgroup v2 | По умолчанию, лучший выбор |
| `podman` | То же, но без демона | Podman 5+ | Нет возможности ставить Docker |
| `lxc` | Контейнер на Proxmox VE | Proxmox 8+, SSH к PVE | Ноды уже под Proxmox |
| `native` | Процесс через systemd | systemd 252+, cgroup v2 | Минимальный VPS без Docker |

Рантайм выбирается **на ноду** (поле «Рантайм» в карточке ноды) и **на сервер**
(панель берёт рантайм ноды). По умолчанию — `runtime.default` из конфига.

Агент при подключении сообщает панели, какие рантаймы у него реально есть
(проверяет сокет, бинарники, cgroup v2). Панель показывает это в админке
и не даст выбрать нерабочий вариант.

### Docker: как применяются лимиты

Агент создаёт контейнер с параметрами:

```
--memory 2g --memory-swap 2g       # жёсткий лимит RAM
--cpus 0.5                          # 50% одного ядра
--pids-limit 512                    # кол-во процессов
--ulimit nofile=65535
--cap-drop ALL --security-opt no-new-privileges
--network gamedock-net              # общая сеть для всех контейнеров
```

Изменить лимиты на лету (без перезапуска) — команда `docker update`, агент
применяет её сразу после «Сохранить» в настройках сервера.

### Native: как применяются лимиты

Агент запускает процесс через `systemd-run` — systemd применяет лимиты сам:

```
MemoryMax=2048M
CPUQuota=50%
TasksMax=512
IOWeight=…
```

Проблема: `systemd-run --property` работает только при создании юнита.
Поэтому изменение RAM/CPU на работающем native-сервере требует перезапуска —
панель честно предупреждает об этом («Лимиты применятся после перезапуска»).

Нативно ограничить скорость сети через systemd нельзя. Если это нужно —
используйте Docker или пропишите `tc` в ExecStartPre.

### LXC: особенности

- Контейнер создаётся командой `pct create` на PVE-хосте по SSH.
- Порты пробрасываются внутрь тем же номером (`--mp0 7777/tcp,mp=7777`).
- JRE ставится внутри контейнера автоматически, если игра на Java.
- Лимиты меняются через `pct set` — работают на лету, но применятся после
  перезапуска контейнера.
- Требуется ресурс `pve-container` на PVE-хосте.

---

## Проброс портов

Игровые порты выдаёт панель, агент публикует их на ноде.

```
game   25000-25999   основной игровой порт
query  26000-26999   query / SLP
rcon   27000-27199   удалённое управление
```

Панель выдаёт порт из таблицы `resource_allocations` в транзакции —
две одновременные заявки не получат один порт.

**Покупка «красивого» порта:**

```php
'ports' => [
    'allow_purchase' => true,
    'allow_purchase_prices' => [
        'low'  => ['from' => 3000, 'to' => 3049, 'price' => 150],
        'mid'  => ['from' => 3050, 'to' => 3099, 'price' => 350],
        'high' => ['from' => 25565, 'to' => 25575, 'price' => 500],
    ],
],
```

Цена берётся из диапазона, в который попал порт. Для native-рантайма
диапазоны игровых портов нужно открыть вручную:

```bash
ufw allow 3000:3099/tcp
ufw allow 25565:25575/tcp
```

Для Docker/Podman/LXC дополнительно ничего делать не нужно —
публикация портов происходит автоматически.

---

## Ресурсы и квоты

`config/hosting.php → resources`

```php
'defaults' => [
    'memory_mb' => 1024, 'cpu_percent' => 50, 'swap_mb' => 0,
    'disk_mb' => 10240, 'network_mbps' => 25, 'pids' => 512,
],
'limits' => [
    'memory_mb' => ['min' => 512, 'max' => 65536],
    // ...
],
```

`cpu_percent` считается от одного ядра: 50 = половина ядра, 200 = два ядра.

Пользователь не может выйти за лимиты своего тарифа — панель обрезает
значения при создании сервера и при изменении ресурсов.

Резервирование под систему на ноде:

```php
'reserved_memory_mb' => 1024,   // ОС + Docker + агент
'allocatable_percent' => 85,     // какую долю физической RAM отдаём гостям
```

Формула: `доступно = RAM × allocatable_percent% − reserved_memory_mb`,
но не больше `max_memory_mb` (0 = без ограничения).

---

## Установка свой игры

Любую игру можно добавить в админке без правки кода:
**Админка → Игры → «Создать»**.

### 1. Основное

| Поле | Что это |
|---|---|
| Название, slug | Идентификатор в URL |
| Семейство | `minecraft`, `cs`, `gta`, `mta`, `survival`, `custom` — для группировки и фильтров |
| Образ | Docker-образ, например `eclipse-temurin:21-jre` |
| Слоты | Минимум / максимум / по умолчанию / шаг |
| Цена за слот | Для тарифа «По слотам» |
| Ресурсы по умолчанию | RAM, CPU, диск |

### 2. Startup (запуск)

```json
{
    "exec": "java",
    "args": [
        "-Xms{ram_mb}M", "-Xmx{ram_mb}M", "-XX:+UseG1GC",
        "-jar", "server.jar", "nogui"
    ],
    "cwd": ".",
    "env": { "TZ": "Europe/Moscow" },
    "user": "gamedock",
    "stop_signal": "SIGTERM",
    "stop_timeout": 60,
    "rcon": { "type": "minecraft", "port_from": "rcon_port" },
    "query": { "type": "minecraft", "port_from": "query_port" },
    "healthcheck": { "type": "query", "interval": 30 }
}
```

**Плейсхолдеры, которые подставляет агент:**

| Плейсхолдер | Значение |
|---|---|
| `{ram_mb}`, `{ram_gb}` | выделенная память |
| `{cpu_percent}`, `{cpu_cores}` | лимит CPU |
| `{slots}`, `{players}` | слоты и текущий онлайн |
| `{game_port}`, `{query_port}`, `{rcon_port}` | выданные порты |
| `{server_name}`, `{server_id}` | имя и ID сервера |
| `{server_ip}` | внешний адрес ноды |
| `{rcon_password}` | сгенерированный пароль RCON |
| `{map}`, `{game_mode}`, `{worldsize}`, `{build}` | настройки из конфига |

### 3. Установщик

```json
{
    "type": "steamcmd",
    "app_id": 440,
    "anonymous": true,
    "script": "steamcmd/install-app.sh",
    "post_install": "cs/post-install.sh",
    "timeout": 3600
}
```

| Тип | Что делает |
|---|---|
| `steamcmd` | `app_update` через SteamCMD |
| `script` | Запускает bash-скрипт из `game-images/` |
| `download` | Скачивает архив по URL и распаковывает |
| `none` | Игра уже на месте |

`post_install` — отдельный скрипт для конфигов, плагинов, генерации карт.
Его ошибка не ломает установку: шаг показывается отдельным.

### 4. Файлы конфигурации

```json
[
    {
        "path": "server.properties",
        "format": "properties",
        "label": "server.properties",
        "fields": [
            {"key": "motd", "type": "text", "label": "MOTD", "maxlength": 120},
            {"key": "max-players", "type": "number", "label": "Слоты", "min": 1, "max": 500},
            {"key": "pvp", "type": "bool", "label": "PvP"},
            {"key": "difficulty", "type": "select", "label": "Сложность",
             "options": ["peaceful", "easy", "normal", "hard"]},
            {"key": "port", "type": "port", "label": "Порт", "read_only": true}
        ]
    }
]
```

Типы полей: `text`, `number`, `bool`, `select`, `port`, `password`.
Поле `read_only: true` не редактируется из панели (обычно порты и RCON).

**Поддерживаемые форматы парсинга:**

| Формат | Кто использует |
|---|---|
| `properties` | Minecraft, Bedrock, Most Source |
| `json` | CRMP, RAGE.MP, ALTV |
| `yaml` | pocketmine.yml |
| `ini` | ARK (GameUserSettings.ini) |
| `cfg` | autoexec.cfg (Source) |
| `samp_cfg` | server.cfg SAMP |
| `mta_cfg` | server.cfg MTA (двоеточие) |
| `rust_cfg` | serverconfig.cfg |
| `unturned_dat` | Commands.dat |

Парсер сохраняет комментарии и неизвестные ключи — правка через панель
не ломает файл.

### 5. Сборки (builds)

```json
[
    {"id": "paper", "name": "Paper",
     "installer": {"type": "script", "script": "minecraft/install-paper.sh"}},
    {"id": "spigot", "name": "Spigot",
     "installer": {"type": "script", "script": "minecraft/install-spigot.sh"}}
]
```

Пользователь выбирает сборку при создании сервера и может переключить её позже.

### 6. Плагины и моды

**Игра → Плагины → Добавить шаблон:**

| Поле | Значение |
|---|---|
| Тип | `plugin`, `mod`, `script`, `config`, `build`, `datapack` |
| Источник | `url` (качать), `builtin` (из `game-images/`), `s3` |
| Куда распаковать | `plugins/`, `mods/`, `resources/` |

`post_commands` выполняются в консоли после установки — удобно для
`reload`, генерации миров, выдачи прав.

---

## Маркетинг

Все механики включаются здесь (и установщиком задаёт значения):

```php
'marketing' => [
    'promo_discount' => ['enabled' => true, 'max_percent' => 90],
    'promo_duration' => ['enabled' => true, 'max_days' => 365],
    'promo_bonus'    => ['enabled' => true, 'max_bonus_rub' => 1000],

    'referral' => [
        'enabled' => true,
        'reward_referrer_rub' => 100,   // пригласившему
        'reward_referred_rub' => 100,   // новичку
        'reward_after_payment' => true, // начислить после первой оплаты
        'min_payment' => 100,           // минимальная сумма оплаты
    ],

    'secret_codes' => [
        'enabled' => true,
        'allow_in_game_chat' => true,    // коды в игровом чате
        'allow_personal_codes' => true,  // личные коды пользователей
        'prefixes' => ['//', '!', '/promo'],
    ],

    'trial' => ['enabled' => true, 'days' => 3, 'tariff' => 'trial'],
],
```

### Как работают секретные коды

1. Агент перехватывает в консоли строки, начинающиеся с `//`, `!` или `/promo`.
   Обычные сообщения чата игроков он не трогает.
2. Строка уходит в паноль, панель ищет совпадение среди кодов сервера
   (коды конкретного сервера + глобальные коды этой игры + личные коды владельца).
3. Если код найден и лимиты не исчерпаны:
   - начисляется награда (деньги / слоты / дни аренды / RAM);
   - игроку в чат уходит сообщение с текстом награды;
   - пишется запись в «Историю активаций».
4. Если код не найден — ничего не происходит, игра идёт как обычно.

Код активируется один раз на игрока (`per_player_limit`).

Пример кода для начисления 500 ₽:

```
//BONUS500
```

### Реферальная программа

Награда начисляется на кошелёк, а не на сервер:
- пригласившему — `reward_referrer_rub`
- приглашённому — `reward_referred_rub`

Если `reward_after_payment = true`, награда ждёт первой оплаты
приглашённого на сумму от `min_payment`.

---

## Биллинг

```php
'billing' => [
    'currency' => 'RUB',
    'grace_period_days' => 3,       // сколько дней работать после конца оплаты
    'warn_before_expiry_days' => 3, // за сколько предупредить
    'stop_on_zero_balance' => true, // останавливать при нулевом балансе
    'delete_after_stop_days' => 14, // через сколько удалить данные
    'min_deposit' => 100,
],
```

**Жизненный цикл сервера:**

```
создан
  → оплачен (списан тариф) → expires_at = +30 дней
  → за 3 дня приходит предупреждение в панель + Telegram
  → срок кончился, баланса нет → начисление остаётся pending
  → через 3 дня (grace) сервер останавливается, is_frozen = true
  → через 14 дней данные удаляются
  → пользователь пополнил баланс → начисления списаны → сервер разморожен
```

Модели тарифов:

| Модель | Как считается | Пример |
|---|---|---|
| `package` | Фикс за период | 349 ₽/мес за пакет 4 ГБ / 30 слотов |
| `slots` | Цена × слоты | 12 ₽ × 60 слотов = 720 ₽/мес |
| `hybrid` | Пакет + докупки | 699 ₽ + 12 ₽/слот + 90 ₽/ГБ RAM |

---

## Мониторинг и алерты

```php
'monitoring' => [
    'interval' => 5,                    // секунд между точками
    'raw_ttl' => 604800,               // сырые точки в Redis, 7 дней
    'public' => [
        'enabled' => true,              // мониторинг на главной
        'show_names' => true,
        'min_uptime_for_public' => 3600,
    ],
    'alerts' => [
        'server_down' => true,
        'server_down_after_seconds' => 120,
        'high_ram' => true, 'high_ram_threshold' => 90,
        'high_cpu' => true, 'high_cpu_threshold' => 95,
        'disk_low' => true, 'disk_low_threshold' => 90,
        'node_offline' => true,
        'balance_low' => true, 'balance_low_threshold' => 50,
        'rate_limit_per_hour' => 10,    // антиспам алертов
    ],
],
```

Графики:
- до 6 часов — сырые точки из Redis (шаг 5 секунд);
- сутки и дольше — агрегаты `metric_hourly` (шаг час, хранение 30 дней).

---

## Подкаталоги внутри config/hosting.php

```
node_mode, node_modes      работа с нодами
runtime, runtime.available  рантаймы
paths                      каталоги на ноде
agent                      протокол панель↔агент
ports                      пулы и покупка портов
resources                  лимиты по умолчанию
marketing                  промокоды, рефералка, секретные коды, тест
billing                    валюта, grace-периоды, автоостановка
auth                       регистрация, 2FA, OAuth, капча, пароли
monitoring                 метрики, алерты, публичный статус
backups                    расписания, хранение, S3
logs                       размер, ротация, поиск
sub_accounts               права дополнительных пользователей
scheduler                  типы задач и их лимиты
watchdog                   авто-рестарт при падении
store                      магазин доп. услуг
account                    лимиты аккаунта
locale                     языки, часовой пояс
branding                   название, цвета, контакты
payments                   способы оплаты, автопополнение
support                    тикеты, лимиты
updates                    канал обновлений
```

---

## Частые правки

**Сменить валюту на USD:**

```php
'billing' => ['currency' => 'USD', 'currency_symbol' => '$'],
```

**Сделать тарифы по слотам основной моделью:**

```php
'tariffs' => [...]  // в админке, у каждого тарифа model = "slots"
```

**Отключить все платежи:**

```php
'payments' => ['enabled' => false],
```

**Разрешить открытую регистрацию без подтверждения email:**

```php
'auth' => [
    'registration_enabled' => true,
    'require_email_verification' => false,
],
```

**Повысить лимит слотов на пользователя:**

```php
'account' => ['max_servers_per_user' => 50],
```

**Включить только S3-бэкапы без локальных копий:**

```php
'backups' => [
    'default_schedule' => 'daily',
    'upload_to_s3' => true,
],
```

После любой правки файла:

```bash
cd /opt/gamedock/panel
sudo -u gamedock php artisan config:clear
sudo -u gamedock php artisan optimize
sudo systemctl restart gamedock-wss gamedock-scheduler
```
