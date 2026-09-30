# Игры и добавление своей игры

## Поддерживаемые из коробки

| Slug | Игра | Установка | Query | RCON | Сборки |
|---|---|---|---|---|---|
| `minecraft-java` | Minecraft Java (Paper, Spigot, Folia, Purpur, Vanilla) | скрипт + PaperMC API | да (SRV) | да | 1.8.9 … 1.21.x |
| `minecraft-bedrock` | Minecraft Bedrock (PocketMine-MP) | скрипт | да | нет | 1.21.x |
| `cs2` | Counter-Strike 2 | SteamCMD (appid 730) | A2S | Source RCON | — |
| `csgo` | CS:GO (legacy) | SteamCMD (appid 730, branch legacy) | A2S | Source RCON | — |
| `samp` | GTA SAMP | скрипт (архив 0.3.7) | таблица игроков | RCON SA-MP | 0.3.7 / 0.3.DL |
| `crmp` | GTA CRMP (Arizona, RedM, Snow, Optim и др.) | скрипт | таблица игроков | RCON | — |
| `ragemp` | GTA V RAGEMP | SteamCMD (appid 37330) + RAGE Multiplayer | samp-совместимый | RCON | — |
| `altv` | GTA V alt:V | SteamCMD (appid 555280) | alt:V API | RCON alt:V | — |
| `mta` | MTA:SA | скрипт (архив сервера) | info-протокол | RCON | 0.7.x |
| `rust` | Rust | SteamCMD (appid 252490) | Steam Query | Source RCON | stable / experimental |
| `unturned` | Unturned | SteamCMD (appid 1110390) | Steam Query | Source RCON | — |
| `ark` | ARK: Survival Evolved | SteamCMD (appid 346110) | Steam Query | Source RCON | — |

Каталог лежит в БД (таблица `games`) и редактируется из админки: **Админка → Игры**.
Сид-миграция `2026_01_01_001200_seed_core_data.php` создаёт эти 12 игр, стартовые
шаблоны плагинов и сборок.

## Как устроена игра в панели

Игра — это одна запись `games` с четырьмя JSON-блоками:

```jsonc
{
  "startup": {          // как запускать процесс
    "exec": "java",
    "args": ["-Xms{{memory}}M", "-Xmx{{memory}}M", "-jar", "server.jar", "nogui"],
    "cwd": ".",
    "env": {"RCON_PASSWORD": "{{rcon_password}}"},
    "user": "gamedock",
    "stop_signal": "SIGTERM",
    "stop_timeout": 30,
    "rcon":    {"type": "minecraft", "port_from": "rcon_port"},
    "query":   {"type": "minecraft", "port_from": "query_port"},
    "healthcheck": {"type": "query", "interval": 30}
  },

  "installer": {        // откуда взять игру
    "type": "script",   // script | steamcmd | download | none
    "script": "minecraft/install.sh",
    "app_id": 252490,   // для steamcmd
    "branch": "stable",
    "timeout": 1800
  },

  "config_files": [     // что редактировать из панели
    {
      "path": "server.properties",
      "label": "server.properties",
      "format": "properties",
      "fields": [
        {"key": "server-port", "type": "port", "label": "Порт сервера", "read_only": true},
        {"key": "max-players", "type": "number", "label": "Слоты", "min": 1, "max": 2000, "default": 20},
        {"key": "motd",       "type": "text",   "label": "MOTD", "maxlength": 120}
      ]
    }
  ],

  "bootstrap_files": []  // файлы, создаваемые при первом запуске
}
```

Подстановки в `startup` (обрабатывает `App\Services\Games\StartupBuilder`):

| Плейсхолдер | Значение |
|---|---|
| `{{game_port}}`, `{{query_port}}`, `{{rcon_port}}` | выданные панелью порты |
| `{{server_dir}}` | абсолютный путь к каталогу сервера |
| `{{memory}}` | лимит RAM в МБ |
| `{{slots}}` | количество слотов |
| `{{rcon_password}}` | сгенерированный пароль RCON |
| `{{server_name}}` | имя сервера |
| `{{java_version}}` | версия Java из `installer.options` |

`{{ }}` заменяется в `exec`, `args`, `env`, `cwd`. Значения вида `enc:<base64>`
в `env` агент расшифровывает — так в конфиг попадает пароль RCON, не покидая панель в открытом виде.

## Типы `installer`

### `script`

Запускает shell-скрипт из репозитория `game-images/`:

```json
{ "type": "script", "script": "minecraft/install.sh", "timeout": 1800 }
```

Скрипт получает переменные окружения:

```bash
GAMEDOCK_SERVER_DIR=/home/gamedock/servers/42
GAMEDOCK_GAME=minecraft-java
GAMEDOCK_BUILD=1.21.1
GAMEDOCK_MEMORY_MB=4096
GAMEDOCK_SLOTS=60
GAMEDOCK_GAME_PORT=25012
GAMEDOCK_QUERY_PORT=26012
GAMEDOCK_RCON_PORT=27012
GAMEDOCK_RCON_PASSWORD=…
```

Скрипт обязан завершиться кодом `0`. Всё, что он печатает в stdout, попадает в
`server_install_logs` и видно пользователю в прогрессе установки.

### `steamcmd`

```json
{ "type": "steamcmd", "app_id": 252490, "branch": "stable",
  "options": { "anonymous": true }, "timeout": 3600 }
```

Агент запускает `steamcmd` внутри рантайма, дожидается завершения и выполняет
`post_install`-скрипт игры (см. `game-images/<family>/post-install.sh`), если он есть.

### `download`

```json
{ "type": "download", "url": "https://…/build.zip", "format": "zip", "strip": 0 }
```

Скачивает архив, при необходимости распаковывает, кладёт в корень сервера.

### `none`

Игра уже на месте (пользователь залил файлы сам через файловый менеджер).

## Своя игра за 5 минут

1. **Админка → Игры → Создать**
2. Заполнить название, семейство (`custom` или своё), лимиты ресурсов.
3. В блоке **Startup** указать:
   ```json
   {
     "exec": "./run.sh",
     "args": ["{{game_port}}"],
     "cwd": ".",
     "env": {},
     "user": "gamedock",
     "stop_signal": "SIGTERM",
     "stop_timeout": 30,
     "query": {"type": "none"},
     "rcon":  {"type": "none"}
   }
   ```
4. В блоке **Installer**:
   ```json
   { "type": "script", "script": "custom/mygame/install.sh", "timeout": 1800 }
   ```
   или `"type": "none"`, если файлы пользователь залит сам.
5. Сохранить, отметить **Публичная** и **Показывать на главной** (по желанию).

Код менять не нужно. Если игра не настолько ответственная, как Minecraft, `installer: none` +
`query: none` достаточно — сервер будет работать, просто без мониторинга онлайна и RCON.

### Пример своей игры с конфигом

```json
"config_files": [
  {
    "path": "config.json",
    "label": "Основной конфиг",
    "format": "json",
    "fields": [
      {"key": "server.name",     "type": "text",   "label": "Название", "default": "My server"},
      {"key": "server.maxPlayers","type": "number","label": "Слоты", "min": 1, "max": 500, "default": 32},
      {"key": "server.gravity",  "type": "bool",   "label": "Гравитация", "default": true},
      {"key": "server.motd",     "type": "text",   "label": "MOTD", "maxlength": 200}
    ]
  }
]
```

Ключи с точкой пишутся вложенно: `{"server": {"name": "…", "maxPlayers": 32}}`.
Типы полей: `text`, `textarea`, `number`, `bool`, `select`, `password`, `port`.
Для `select` добавьте `"options": ["a", "b", "c"]`, для `number` — `min`/`max`.

Значения сохраняются в `servers.config_values`, пишутся в файл на ноде и применяются
после рестарта (агент получает команду `config.write`).

## Каталог шаблонов (1-клик плагины)

**Админка → Игры → (игра) → Шаблоны**. Шаблон = файла, которая скачивается и
распаковывается на сервер по кнопке.

| Тип | Что делает |
|---|---|
| `plugin` | `.jar` в `plugins/` (Minecraft, CS2 — не применимо) |
| `mod` | мод/папка в `mods/` |
| `script` | sh/py-скрипт, запускается вручную |
| `config` | готовый конфиг (properties/json/yaml) |
| `build` | другая версия игры |
| `datapack` | `.zip` датапак в `world/datapacks/` |

Источник файла:

- `url` — прямая ссылка (GitHub Releases, Modrinth API, CurseForge…);
- `builtin` — файл из репозитория `game-images/` на ноде (`GD_TEMPLATES_ROOT`);
- `s3` — объект в S3-совместимом хранилище.

После загрузки агент:
1. скачивает во временный файл;
2. сверяет `checksum`, если задан;
3. распаковывает в `target_path` (по умолчанию `plugins/`);
4. выполняет `post_commands` (например `say Плагин установлен`).

Совместимость по версии игры: заполните `game_version` — панель покажет предупреждение,
если версия сервера не совпадает.

## Репозиторий `game-images/`

```
game-images/
├── steamcmd/install-app.sh        # универсальный установщик SteamCMD
├── minecraft/install.sh           # PaperMC API + Paper/Spigot/Folia/Purpur/Vanilla
├── minecraft/paper.sh             # обёртки под конкретные билды
├── minecraft-bedrock/install.sh   # PocketMine-MP
├── cs2/post-install.sh            # конфиги CS2 после app_update
├── csgo/post-install.sh
├── gta/samp/install.sh
├── gta/crmp/install.sh
├── gta/ragemp/install.sh
├── gta/altv/install.sh
├── mta/install.sh
├── rust/post-install.sh
├── unturned/post-install.sh
└── ark/post-install.sh
```

Каталог кладётся на ноду в `GD_TEMPLATES_ROOT` (по умолчанию `/opt/gamedock/game-images`).
Чтобы добавить скрипт для своей игры — положите файл в `game-images/custom/<slug>/install.sh`
и укажите путь в `installer.script`.

## Проверка «игра запустилась»

Тип `healthcheck` в `startup`:

| Тип | Как проверяется |
|---|---|
| `query` | панель шлёт query-пакет и ждёт корректного ответа |
| `port` | просто TCP-connect на `game_port` |
| `process` | агент смотрит, жив ли PID |
| `none` | не проверяем, `starting → running` сразу после спавна |

`interval` — как часто слать проверку. Если три проверки подряд неуспешны, аген�� помечает
сервер `crashed` и (если включён `watchdog`) перезапускает его.

## Частые ошибки

| Симптом | Причина | Что делать |
|---|---|---|
| Установка падает на первом шаге | скрипт вернул ненулевой код | посмотрите вывод шага в прогрессе установки; запустите скрипт вручную на ноде |
| Сервер `crashed` сразу после старта | неверный `exec`/`args` | откройте консоль, проверьте команду в поле «Команда запуска» |
| Порт не открывается наружу | `flagship` ноды не настроен | **Админка → Ноды → Показать → Внешний адрес** |
| `No such file or directory` в логах | каталог не создан | удалите сервер и создайте заново |
| MC не стартует: `Java not found` | в образе нет JRE | используйте образ с Java или `installer.type: download` + свой скрипт |
