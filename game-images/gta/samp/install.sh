#!/usr/bin/env bash
#
# Установка GTA SAMP (SA-MP 0.3.7 / 0.3.DL)
#
# SAMP-сервер — это набор готовых бинарников под Linux (samp03svr, samp03svrDL).
# Панель загружает собранный пользователем пакет (build), а скрипт:
#   1. распаковывает его в каталог сервера;
#   2. создаёт server.cfg с портами, выданными панелью;
#   3. готовит filterscripts/ и скрипты.
#
# Переменные окружения:
#   GD_SERVER_DIR, GD_BUILD (samp|dl), GD_SLOTS,
#   GD_GAME_PORT, GD_QUERY_PORT, GD_RCON_PORT, GD_RCON_PASSWORD, GD_SERVER_NAME
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
BUILD="${GD_BUILD:-samp}"
SLOTS="${GD_SLOTS:-50}"
GAME_PORT="${GD_GAME_PORT:-7777}"
QUERY_PORT="${GD_QUERY_PORT:-7778}"
RCON_PORT="${GD_RCON_PORT:-7779}"
RCON_PASS="${GD_RCON_PASSWORD:-}"
SERVER_NAME="${GD_SERVER_NAME:-SA-MP сервер}"

log()  { echo -e "\033[36m[samp]\033[0m $*"; }
ok()   { echo -e "\033[32m[samp]\033[0m ✓ $*"; }
warn() { echo -e "\033[33m[samp]\033[0m ! $*"; }
fail() { echo -e "\033[31m[samp]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

# ── Зависимости ──────────────────────────────────────────────────────

log "Проверяю зависимости…"

if ! dpkg -s lib32stdc++6 >/dev/null 2>&1; then
    log "Ставлю 32-битные библиотеки (нужны для samp03svr)…"
    dpkg --add-architecture i386 2>/dev/null || true
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq lib32stdc++6 lib32gcc-s1
fi

# ── Поиск бинарников ─────────────────────────────────────────────────
#
# Панель складывает пакеты в /opt/gamedock/game-images/gta/samp/builds/<build>.tar.gz
# или загружает через файловый менеджер до запуска установки.

TEMPLATE_ROOT="${GD_TEMPLATES_ROOT:-/opt/gamedock/game-images}"
BUILD_ARCHIVE=""
CANDIDATE="$TEMPLATE_ROOT/gta/samp/builds/${BUILD}.tar.gz"

if [[ -f $CANDIDATE ]]; then
    BUILD_ARCHIVE="$CANDIDATE"
    log "Найден пакет сборки: $CANDIDATE"
elif [[ -f "$DIR/samp03svr" ]] || [[ -f "$DIR/samp03svrDL" ]]; then
    log "Бинарники уже загружены через панель"
else
    log "Пакет сборки не найден, пробую скачать публичную версию…"

    # Официальный SA-MP 0.3.7 для Linux
    DEFAULT_URL="https://github.com/FrisX/sample-samp/releases/download/v1/samp037svr.tar.gz"

    if curl -fSL --retry 2 -o /tmp/samp-server.tar.gz "$DEFAULT_URL" 2>/dev/null; then
        BUILD_ARCHIVE="/tmp/samp-server.tar.gz"
        ok "Скачана публичная сборка SA-MP 0.3.7"
        warn "Для полноценной работы (кодовые моды, плагины) загрузите свою сборку в панели."
    else
        fail "Не удалось получить SA-MP-сервер.

Что делать:
  1. Скачайте бинарники samp03svr для Linux.
  2. Загрузите их через файловый менеджер панели в корень сервера.
  3. Или положите архив сюда на ноде:
     ${CANDIDATE}
  4. Запустите переустановку сервера."
    fi
fi

# ── Распаковка ───────────────────────────────────────────────────────

if [[ -n $BUILD_ARCHIVE ]]; then
    log "Распаковываю…"

    case "$BUILD_ARCHIVE" in
        *.tar.gz|*.tgz) tar -xzf "$BUILD_ARCHIVE" -C "$DIR" ;;
        *.zip)          unzip -o "$BUILD_ARCHIVE" -d "$DIR" ;;
        *.tar)          tar -xf "$BUILD_ARCHIVE" -C "$DIR" ;;
        *)              cp -a "$BUILD_ARCHIVE"/. "$DIR"/ ;;
    esac
fi

# ── Проверка бинарника ────────────────────────────────────────────────

BINARY=""
for candidate in samp03svrDL samp03svr samp03svr_dl; do
    if [[ -f "$DIR/$candidate" ]]; then
        BINARY="$candidate"
        break
    fi
done

if [[ -z $BINARY ]]; then
    fail "Бинарник samp03svr не найден в каталоге сервера"
fi

chmod +x "$BINARY"
ok "Бинарник: $BINARY"

# ── server.cfg ───────────────────────────────────────────────────────

log "Создаю server.cfg…"

if [[ -f server.cfg ]]; then
    # Обновляем порты, сохраняя остальные настройки
    sed -i "s/^port .*/port ${GAME_PORT}/" server.cfg
    sed -i "s/^rcon_port .*/rcon_port ${RCON_PORT}/" server.cfg
    ok "Порт обновлён в существующем server.cfg"
else
    cat >server.cfg <<CFG
### Создано GameDock — настройки можно менять в панели
echo Executing Server Config...
lanmode 0
rcon_password ${RCON_PASS}
maxplayers ${SLOTS}
port ${GAME_PORT}
rcon_port ${RCON_PORT}
hostname ${SERVER_NAME}
gamemode0 grandlarc 1
filterscripts gl_actions gl_realtime gl_property gl_mapicon ls_mall teleport filterscripts
announce 0
chatlogging 0
weburl https://example.com
onfootrate 40
incarate 40
weburl www.example.com
maxnpc 0
language 1
query_enable 1
CFG
    ok "Создан server.cfg"
fi

# ── Структура каталогов ──────────────────────────────────────────────

mkdir -p filterscripts scriptfiles pawno/include pawno/tmp logs

if [[ ! -f filterscripts/gl_actions.amx ]]; then
    log "Создаю заглушки filterscripts…"
    cat >filterscripts/gl_actions.amx <<'AMX'
// Заглушка — замените своим кодом или загрузите через панель
main() { return 1; }
AMX
fi

# ── Скрипт запуска для LXC ───────────────────────────────────────────

cat >run.sh <<'RUNEOF'
#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
# SA-MP — 32-битный процесс
exec ./samp03svr
RUNEOF
chmod +x run.sh

# ── Права ────────────────────────────────────────────────────────────

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true
chmod 644 server.cfg 2>/dev/null || true

ok "SA-MP сервер готов"
