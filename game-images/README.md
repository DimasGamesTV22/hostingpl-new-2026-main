# game-images — репозиторий установочных скриптов GameDock

Этот каталог кладётся на каждую ноду в `GD_TEMPLATES_ROOT`
(по умолчанию `/opt/gamedock/game-images`) и используется агентом в двух местах:

1. **`installer.script`** — скрипт установки игры, который агент запускает
   при создании сервера.
2. **Шаблоны установки** (`source_type: builtin`) — готовые файлы
   (плагины, конфиги, сборки), которые скачиваются пользователем в один клик.

Админ панели ссылается на файл по пути относительно этого каталога:
`minecraft/install.sh`, `cs2/post-install.sh` и так далее.

## Как это работает

```
Админка → Игры → (игра) → Startup/Installer
                          │
                          ▼
   агент: cd $GAMEDOCK_SERVER_DIR
          env $(env | grep ^GAMEDOCK_)
          bash $GD_TEMPLATES_ROOT/<installer.script>
```

Скрипт обязан:

- завершиться с кодом `0` при успехе, ненулевым — при ошибке;
- писать понятный прогресс в stdout (панель покажет его пользователю);
- не выходить за пределы `$GAMEDOCK_SERVER_DIR`;
- быть идемпотентным — его можно перезапустить.

## Переменные окружения

| Переменная | Значение |
|---|---|
| `GAMEDOCK_SERVER_DIR` | каталог сервера (cwd) |
| `GAMEDOCK_GAME` | slug игры |
| `GAMEDOCK_BUILD` | выбранная сборка/версия |
| `GAMEDOCK_MEMORY_MB` | лимит RAM |
| `GAMEDOCK_SLOTS` | количество слотов |
| `GAMEDOCK_GAME_PORT` | игровой порт |
| `GAMEDOCK_QUERY_PORT` | порт query |
| `GAMEDOCK_RCON_PORT` | порт RCON |
| `GAMEDOCK_RCON_PASSWORD` | пароль RCON |
| `GAMEDOCK_TEMPLATES_ROOT` | корень этого репозитория |

## Структура

```
game-images/
├── README.md              ← вы здесь
├── steamcmd/
│   └── install-app.sh     # универсальная загрузка через SteamCMD
├── minecraft/
│   ├── install.sh         # PaperMC API: paper/spigot/folia/purpur/vanilla
│   ├── paper.sh
│   ├── spigot.sh
│   ├── folia.sh
│   ├── purpur.sh
│   └── vanilla.sh
├── minecraft-bedrock/
│   └── install.sh         # PocketMine-MP
├── cs2/
│   └── post-install.sh    # конфиги и motd после app_update 730
├── csgo/
│   └── post-install.sh
├── gta/
│   ├── samp/install.sh
│   ├── crmp/install.sh
│   ├── crmp/cowa.sh
│   ├── crmp/optim.sh
│   ├── crmp/aurora.sh
│   ├── ragemp/install.sh
│   └── altv/install.sh
├── mta/
│   └── install.sh
├── rust/
│   └── post-install.sh
├── unturned/
│   └── post-install.sh
├── ark/
│   └── post-install.sh
└── custom/
    └── <ваш-слаг>/install.sh
```

## Шаблон скрипта установки

```bash
#!/usr/bin/env bash
# Установка <игра>. Ожидаем, что скрипт запущен из каталога сервера.
set -euo pipefail

DIR="${GAMEDOCK_SERVER_DIR:-$PWD}"
BUILD="${GAMEDOCK_BUILD:-latest}"
MEMORY="${GAMEDOCK_MEMORY_MB:-2048}"
PORT="${GAMEDOCK_GAME_PORT:-25565}"

say() { printf '%s\n' "$*"; }

say "==> Каталог: $DIR"
say "==> Сборка: $BUILD, порт: $PORT"

# … собственно установка …

say "==> Готово"
exit 0
```

**Обязательно** начинайте с `set -euo pipefail` и заканчивайте явным `exit`:
агент читает код возврата, чтобы понять, успешна ли установка.

## Своя игра

1. Создайте каталог `custom/<slug>/`.
2. Положите туда `install.sh` (см. шаблон выше).
3. В админке: **Игры → ваша игра → Installer**:
   ```json
   { "type": "script", "script": "custom/<slug>/install.sh", "timeout": 1800 }
   ```
4. Скопируйте каталог на ноду: `rsync -a game-images/ node:/opt/gamedock/game-images/`.

Если скриптов много, удобнее держать их в git и обновлять на нодах так:

```bash
cd /opt/gamedock/game-images && git pull --ff-only
```

## Тестирование скрипта локально

```bash
export GAMEDOCK_SERVER_DIR=/tmp/gd-test
export GAMEDOCK_GAME=minecraft-java
export GAMEDOCK_BUILD=1.21.1
export GAMEDOCK_MEMORY_MB=4096
export GAMEDOCK_GAME_PORT=25599
export GAMEDOCK_TEMPLATES_ROOT=$PWD

mkdir -p "$GAMEDOCK_SERVER_DIR"
(cd "$GAMEDOCK_SERVER_DIR" && bash "$GAMEDOCK_TEMPLATES_ROOT/minecraft/install.sh")
```

Скрипт должен отработать без ошибок и создать в `$GAMEDOCK_SERVER_DIR`
всё, что нужно для запуска игры.
