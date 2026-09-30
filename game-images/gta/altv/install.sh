#!/usr/bin/env bash
#
# Установка ALT-V (ALTV)
#
# ALT-V — мультиплеер GTA V на Node.js. Клиентские ресурсы и серверные
# плагины качаются отдельно.
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
GAME_PORT="${GD_GAME_PORT:-3000}"
RCON_PORT="${GD_RCON_PORT:-3001}"
SLOTS="${GD_SLOTS:-64}"
SERVER_NAME="${GD_SERVER_NAME:-ALT-V сервер}"

log()  { echo -e "\033[36m[altv]\033[0m $*"; }
ok()   { echo -e "\033[32m[altv]\033[0m ✓ $*"; }
fail() { echo -e "\033[31m[altv]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

# ── Node.js ──────────────────────────────────────────────────────────

if ! command -v node >/dev/null 2>&1; then
    log "Ставлю Node.js 20…"
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nodejs
fi

# ── Получение ────────────────────────────────────────────────────────

TEMPLATE_ROOT="${GD_TEMPLATES_ROOT:-/opt/gamedock/game-images}"
CANDIDATE="$TEMPLATE_ROOT/gta/altv/server.tar.gz"

if [[ -f $CANDIDATE ]]; then
    log "Распаковываю пакет…"
    tar -xzf "$CANDIDATE" -C "$DIR"
elif [[ -f "$DIR/package.json" ]]; then
    log "package.json найден"
else
    fail "ALT-V сервер не найден.

Что делать:
  1. Скачайте ALT-V Server с https://altv.io
  2. Загрузите архив через панель в корень сервера
  3. Запустите переустановку"
fi

# ── Зависимости ──────────────────────────────────────────────────────

if [[ -f package.json ]]; then
    log "Ставлю npm-зависимости…"
    npm install --omit=dev --no-audit --no-fund 2>&1 | tail -3 || fail "npm install не удался"
fi

mkdir -p resources plugins components logs

# ── altv.config.js ───────────────────────────────────────────────────

if [[ ! -f altv.config.js ]]; then
    log "Создаю altv.config.js…"

    cat >altv.config.js <<CFG
module.exports = {
    name: '${SERVER_NAME}',
    port: ${GAME_PORT},
    rconPort: ${RCON_PORT},
    rconPassword: '${GD_RCON_PASSWORD:-changeme}',
    maxClients: ${SLOTS},
    debug: false,
    log: {
        level: 'warn',
        discord: false,
    },
    language: 'ru',
    webUrl: 'https://example.com',
    cdn: {
        version: 1,
        files: [],
    },
    useCdn: false,
    encryptionKey: '',
    masterServer: [],
}
CFG

    ok "Создан altv.config.js"
else
    ok "altv.config.js уже существует"
fi

# ── server.cfg (legacy) ──────────────────────────────────────────────

if [[ ! -f server.cfg ]]; then
    cat >server.cfg <<CFG
HOSTNAME ${SERVER_NAME}
PORT ${GAME_PORT}
RCON_PORT ${RCON_PORT}
SV_MAXCLIENTS ${SLOTS}
ANNOUNCE 1
CFG
fi

# ── Скрипт запуска ───────────────────────────────────────────────────

cat >run.sh <<'RUNEOF'
#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
exec node altv-server
RUNEOF
chmod +x run.sh

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "ALT-V сервер готов"
