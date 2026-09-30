#!/usr/bin/env bash
#
# Постустановка CS2: конфиги, SourceMod/Metamod, автоexec.cfg
# Вызывается игрой cs2 со сборкой build=cs2 (installer.type = steamcmd)
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
SLOTS="${GD_SLOTS:-24}"
GAME_PORT="${GD_GAME_PORT:-27015}"
QUERY_PORT="${GD_QUERY_PORT:-27016}"
RCON_PORT="${GD_RCON_PORT:-27017}"
MAP="${GD_MAP:-de_dust2}"
MODE="${GD_GAME_MODE:-competitive}"
SERVER_NAME="${GD_SERVER_NAME:-CS2 сервер}"

log()  { echo -e "\033[36m[cs2]\033[0m $*"; }
ok()   { echo -e "\033[32m[cs2]\033[0m ✓ $*"; }
warn() { echo -e "\033[33m[cs2]\033[0m ! $*"; }
fail() { echo -e "\033[31m[cs2]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

# ── Проверка содержимого ─────────────────────────────────────────────

if [[ ! -d game ]]; then
    fail "Каталог game не найден — SteamCMD не отработал.
  Запустите переустановку сервера."
fi

log "Содержимое: $(du -sh . 2>/dev/null | cut -f1)"

# ── Права ────────────────────────────────────────────────────────────

chmod +x game/bin/linuxsteamrt64/cs2 2>/dev/null || true
chmod +x srcds_run 2>/dev/null || true

# ── Автозапуск ───────────────────────────────────────────────────────

cat >run.sh <<'RUNEOF'
#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
export LD_LIBRARY_PATH="$LD_LIBRARY_PATH:./linux64"
exec ./game/bin/linuxsteamrt64/cs2 \
  -dedicated \
  +ip 0.0.0.0 \
  +port ${GD_GAME_PORT} \
  +queryport ${GD_QUERY_PORT} \
  +rcon_port ${GD_RCON_PORT} \
  +maxplayers ${GD_SLOTS} \
  +map de_dust2
RUNEOF

chmod +x run.sh

# ── autoexec.cfg ─────────────────────────────────────────────────────

CFG_DIR="game/csgo/cfg"
mkdir -p "$CFG_DIR" "addons/sourcemod" "addons/metamod" "cfg/serveronly" "logs"

if [[ ! -f "$CFG_DIR/autoexec.cfg" ]]; then
    log "Создаю autoexec.cfg…"

    cat >"$CFG_DIR/autoexec.cfg" <<'CFG'
// ═══════════════════════════════════════════════
// Создано GameDock — управляйте из панели
// Раздел «Настройки сервера»
// ═══════════════════════════════════════════════

// Основное
hostname "CS2 сервер"
sv_password ""

// Ротация карт
mp_autoteambalance 1
mp_limitteams 0
mp_autokick 0
mp_forcecamera 0
mp_friendlyfire 0
mp_timelimit 30
mp_roundtime 2
mp_buytime 0.25
mp_freezetime 15
mp_respawn_immunitytime 0
mp_matchmaxrestarts 3

// Боты
bot_quota 0
bot_quota_mode "fill"
bot_join_after_player 0
bot_chatter off
bot_zombie 1

// Сеть
sv_region 255
sv_maxrate 100000
sv_minrate 25000
sv_maxupdaterate 101
sv_minupdaterate 20
sv_lan 0
sv_steamid_exposure 0

// Античит
sv_cheats 0
sv_alltalk 1
sv_talk_enemy_dead 1
sv_talk_enemy_living 1

// Логи
log on
sv_log_onefile 1
sv_logfile 1
CFG

    ok "Создан autoexec.cfg"
fi

# ── SourceMod / Metamod (Meta с public-репозитория) ─────────────────

if [[ ! -f addons/sourcemod/sourcemod.vdf ]]; then
    log "Подключаю SourceMod…"

    # SourceMod можно поставить через installer-скрипт, но если его нет —
    # предупреждаем: плагины ставятся через каталог в панели
    warn "SourceMod не найден. Установите его через панель:
  Файлы → plugins → установить SourceMod
  Или положите распакованный SourceMod в addons/sourcemod/"
fi

# ── server.cfg (обратная совместимость) ──────────────────────────────

if [[ ! -f game/csgo/cfg/server.cfg ]]; then
    cat >game/csgo/cfg/server.cfg <<'CFG'
// Глобальные настройки CS2
hostname "CS2 сервер"
sv_password ""
sv_region 255
CFG
fi

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "CS2 настроен. Карта: $MAP, слотов: $SLOTS, порт: $GAME_PORT"
