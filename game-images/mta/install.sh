#!/usr/bin/env bash
#
# Установка MTA:SA
#
# MTA-сервер состоит из бинарников mta_server/mta_server64 и стандартных
# ресурсов. Скачиваем актуальную версию с официального сайта или берём
# загруженный панелью архив.
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
SLOTS="${GD_SLOTS:-64}"
GAME_PORT="${GD_GAME_PORT:-22003}"
QUERY_PORT="${GD_QUERY_PORT:-22126}"
RCON_PORT="${GD_RCON_PORT:-22125}"
SERVER_NAME="${GD_SERVER_NAME:-MTA сервер}"

log()  { echo -e "\033[36m[mta]\033[0m $*"; }
ok()   { echo -e "\033[32m[mta]\033[0m ✓ $*"; }
fail() { echo -e "\033[31m[mta]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

# ── Зависимости ──────────────────────────────────────────────────────

log "Ставлю зависимости MTA…"

if ! dpkg -s libncurses5 libssl3 >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        libncurses5 libncursesw5 libssl3 ca-certificates unzip
fi

# ── Получение сервера ────────────────────────────────────────────────

TEMPLATE_ROOT="${GD_TEMPLATES_ROOT:-/opt/gamedock/game-images}"
CANDIDATE="$TEMPLATE_ROOT/mta/mta-server.tar.gz"

if [[ -f $CANDIDATE ]]; then
    log "Использую пакет из репозитория: $CANDIDATE"
    tar -xzf "$CANDIDATE" -C "$DIR"
    ok "Распаковано"
elif [[ -x "$DIR/mta_server64" || -x "$DIR/mta_server" ]]; then
    log "Сервер уже загружен через панель"
else
    fail "Сервер MTA не найден.

Что делать:
  1. Скачайте MTA:SA Server с https://www.multitheftautoparking.com/
  2. Загрузите архив через панель (Файлы → загрузить) в корень сервера
  3. Запустите переустановку"
fi

# ── Проверка бинарника ────────────────────────────────────────────────

if [[ -x ./mta_server64 ]]; then
    BINARY="mta_server64"
elif [[ -x ./mta_server ]]; then
    BINARY="mta_server"
else
    fail "Не найден mta_server64"
fi

ok "Бинарник: $BINARY"

# ── server.cfg ───────────────────────────────────────────────────────

if [[ ! -f server.cfg ]]; then
    log "Создаю server.cfg…"

    cat >server.cfg <<CFG
# Создано GameDock
<server name="${SERVER_NAME}" />
<maxplayers maxplayers="${SLOTS}" />

<bind address="0.0.0.0" port="${GAME_PORT}" />
<query port="${QUERY_PORT}" />

<rcon port="${RCON_PORT}" />

<serverport playerver="3" />
<maxpacket size="1248" />

# Античит
<anticheat on="on" />

# Логи
<logtimestamp format="%m/%d/%Y %H:%M:%S" />
<fpslimit limit="40" fpslimit="40" />
<sync on="on" />

# Время
<timezoneGMTOffset dst="-60" autostart="on" />

# Скорости
<onfootrate player="1.0" vehicle="0.8" weapon="1" />
<incarate player="1.0" vehicle="0.8" weapon="1" />

# Защита от читов
<anticheat settings="ac.chat,ac.calls,ac.cmds,ac.deadcmd,ac.speedhack" />
CFG

    ok "Создан server.cfg"
else
    # Обновляем порты
    sed -i "s/port=\"${GAME_PORT}\"/port=\"${GAME_PORT}\"/" server.cfg
    ok "Обновлены порты"
fi

# ── Структура ────────────────────────────────────────────────────────

mkdir -p mods/deathmatch mods/standardlogs res logs

# ── Скрипт запуска ───────────────────────────────────────────────────

cat >run.sh <<RUNEOF
#!/usr/bin/env bash
set -e
cd "\$(dirname "\$0")"
export LD_LIBRARY_PATH=".:/usr/lib/x86_64-linux-gnu"
exec ./mta_server64 -n server
RUNEOF
chmod +x run.sh

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "MTA сервер готов"
