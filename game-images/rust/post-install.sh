#!/usr/bin/env bash
#
# Постустановка Rust: конфиг сервера, карта по умолчанию
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
SLOTS="${GD_SLOTS:-80}"
WORLDSIZE="${GD_WORLDSIZE:-4250}"
SAVE_INTERVAL="${GD_SAVE_INTERVAL:-300}"
SERVER_NAME="${GD_SERVER_NAME:-Rust сервер}"

log()  { echo -e "\033[36m[rust]\033[0m $*"; }
ok()   { echo -e "\033[32m[rust]\033[0m ✓ $*"; }
fail() { echo -e "\033[31m[rust]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

[[ -f rustdedicated ]] || chmod +x rustdedicated 2>/dev/null || true

# ── serverconfig.cfg ─────────────────────────────────────────────────

if [[ ! -f serverconfig.cfg ]]; then
    log "Создаю serverconfig.cfg…"

    cat >serverconfig.cfg <<CFG
// ═══════════════════════════════════════════════
// Создано GameDock
// ═══════════════════════════════════════════════
server.title "${SERVER_NAME}"
server.maxplayers ${SLOTS}
server.worldsize ${WORLDSIZE}
server.saveinterval ${SAVE_INTERVAL}
server.identity "gamedock-server"
server.port ${GD_GAME_PORT}
server.queryport ${GD_QUERY_PORT}
server.rcon.port ${GD_RCON_PORT}
server.rcon.password ${GD_RCON_PASSWORD:-ChangeMe123}
server.randomseed 20250101
server.maxcores ${GD_CPU_PERCENT:-2}
server.saveinterval 300

// Сеть
server.encryption 0
server.maxupdaterate 30
server.minupdaterate 10

// Игровой процесс
server.pvp true
server.maxteams 8
server.friendlyfire false
server.wipeid gamedock
server.updateinterval 10

// World
server.seed 12345
server.wipe_id "${GD_WIPE_ID:-gamedock}"

// Ограничения
server.max_teams 8
server.private_voice_limiter 12
server.public_voice_limiter 60
CFG

    ok "Создан serverconfig.cfg"
fi

# ── Карта по умолчанию ───────────────────────────────────────────────

MAP_DIR="proceduralmaps"
mkdir -p "$MAP_DIR" logs

if [[ ! -f "$MAP_DIR/RustMap.png" ]]; then
    log "Создаю стартовую карту (процедурная)…"
    # Rust сгенерирует карту при первом запуске
    warn "Карта будет сгенерирована при первом запуске. Размер мира: ${WORLDSIZE} ($(( WORLDSIZE * WORLDSIZE / 1000000 )) млн клеток)"
fi

cat >run.sh <<'RUNEOF'
#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
exec ./rustdedicated -logfile console.log
RUNEOF
chmod +x run.sh

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "Rust настроен: $SLOTS слотов, мир ${WORLDSIZE}"
