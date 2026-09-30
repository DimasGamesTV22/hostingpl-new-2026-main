#!/usr/bin/env bash
#
# Установка Minecraft Bedrock (PocketMine-MP)
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
GAME_PORT="${GD_GAME_PORT:-19132}"
SLOTS="${GD_SLOTS:-20}"
VERSION="${GD_VERSION:-4.13}"

log()  { echo -e "\033[36m[bedrock]\033[0m $*"; }
ok()   { echo -e "\033[32m[bedrock]\033[0m ✓ $*"; }
fail() { echo -e "\033[31m[bedrock]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

# ── PHP ──────────────────────────────────────────────────────────────

log "Проверяю PHP…"

if ! command -v php >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-sqlite3
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
log "PHP $PHP_VER"

# PocketMine-MP требует расширения
if ! php -m | grep -q "pocketmine"; then
    log "Ставлю PocketMine-MP…"
    curl -fsSL https://get.pmmp.io | bash - 2>&1 | tail -5 || fail "Установка PocketMine-MP не удалась"
fi

# ── Структура ────────────────────────────────────────────────────────

mkdir -p plugins worlds playerdata server.properties

# ── server.properties ────────────────────────────────────────────────

if [[ ! -f server.properties ]]; then
    cat >server.properties <<PROPS
# Создано GameDock
motd=${GD_SERVER_NAME:-Bedrock сервер}
gamemode=survival
difficulty=normal
level-name=world
max-players=${SLOTS}
online-mode=false
white-list=false
pvp=true
server-port=${GAME_PORT}
enable-ipv6=false
view-distance=8
tick-distance=4
player-movement-score-threshold=20
allow-cheats=false
xbox-auth=off
enable-encryption=false
PROPS
    ok "Создан server.properties"
fi

# ── Скрипт запуска ───────────────────────────────────────────────────

if [[ ! -f run.sh ]]; then
    cat >run.sh <<'RUNEOF'
#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
exec php src/pocketmine/PocketMine.php --enable-ansi --no-wizard
RUNEOF
    chmod +x run.sh
fi

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "PocketMine-MP сервер готов"
