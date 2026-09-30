#!/usr/bin/env bash
#
# Установка RAGE.MP (GTA5)
#
# RAGE.MP состоит из серверной части (Node.js + пакеты) и клиентских
# ресурсов. Скачиваем npm-пакеты, генерируем конфиг.
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
GAME_PORT="${GD_GAME_PORT:-22005}"
RCON_PORT="${GD_RCON_PORT:-22006}"
SLOTS="${GD_SLOTS:-100}"
SERVER_NAME="${GD_SERVER_NAME:-RAGE.MP сервер}"

log()  { echo -e "\033[36m[ragemp]\033[0m $*"; }
ok()   { echo -e "\033[32m[ragemp]\033[0m ✓ $*"; }
fail() { echo -e "\033[31m[ragemp]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

# ── Node.js ──────────────────────────────────────────────────────────

log "Проверяю Node.js…"

if ! command -v node >/dev/null 2>&1; then
    log "Ставлю Node.js 20…"
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nodejs
fi

NODE_MAJOR=$(node -v | sed 's/v//; s/\..*//')
log "Node.js $(node -v)"

if (( NODE_MAJOR < 18 )); then
    fail "RAGE.MP требует Node.js 18 или новее"
fi

# ── Получение исходников ─────────────────────────────────────────────

TEMPLATE_ROOT="${GD_TEMPLATES_ROOT:-/opt/gamedock/game-images}"
CANDIDATE="$TEMPLATE_ROOT/gta/ragemp/server.tar.gz"

if [[ -f $CANDIDATE ]]; then
    log "Распаковываю пакет…"
    tar -xzf "$CANDIDATE" -C "$DIR"
elif [[ -f "$DIR/package.json" ]]; then
    log "package.json найден — пропускаю копирование"
else
    fail "Исходники RAGE.MP не найдены.

Что делать:
  1. Скачайте серверную часть с https://rage.mp
  2. Загрузите архив через панель (Файлы) в корень сервера
  3. Запустите переустановку"
fi

# ── Зависимости ──────────────────────────────────────────────────────

if [[ -f package.json ]]; then
    log "Ставлю npm-зависимости…"
    npm install --omit=dev --no-audit --no-fund 2>&1 | tail -5 || fail "npm install не удался"
fi

# ── Структура ────────────────────────────────────────────────────────

mkdir -p packages client_packages bin logs

# ── Конфиг ───────────────────────────────────────────────────────────

if [[ ! -f config.json ]]; then
    log "Создаю config.json…"

    cat >config.json <<CFG
{
  "maxclients": ${SLOTS},
  "name": "${SERVER_NAME}",
  "gamemode": "freeroam",
  "port": ${GAME_PORT},
  "rcon_port": ${RCON_PORT},
  "rcon_password": "${GD_RCON_PASSWORD:-changeme}",
  "csharp": "true",
  "pawn": "true",
  "url": "https://example.com",
  "bind": "0.0.0.0",
  "timezone": "Europe/Moscow",
  "chat": {
    "global": true,
    "faction": true
  },
  "spawn": {
    "player": [ -406.994, -1120.219, 43.695, 275.0 ],
    "faction": [ -1035.712, -273.118, 62.862, 70.0 ]
  }
}
CFG

    ok "Создан config.json"
else
    # Обновляем порты
    node -e "
const fs = require('fs');
const config = JSON.parse(fs.readFileSync('config.json', 'utf8'));
config.port = ${GAME_PORT};
config.rcon_port = ${RCON_PORT};
config.maxclients = ${SLOTS};
fs.writeFileSync('config.json', JSON.stringify(config, null, 2));
" 2>/dev/null || warn "Не удалось обновить порты в config.json"

    ok "Обновлены порты"
fi

# ── Скрипт запуска ───────────────────────────────────────────────────

cat >run.sh <<'RUNEOF'
#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
exec node ./server.js
RUNEOF
chmod +x run.sh

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "RAGE.MP сервер готов"
