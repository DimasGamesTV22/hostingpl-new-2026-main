#!/usr/bin/env bash
#
# Постустановка CS:GO (legacy Source)
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
SLOTS="${GD_SLOTS:-24}"
GAME_PORT="${GD_GAME_PORT:-27015}"
QUERY_PORT="${GD_QUERY_PORT:-27016}"

log()  { echo -e "\033[36m[csgo]\033[0m $*"; }
ok()   { echo -e "\033[32m[csgo]\033[0m ✓ $*"; }

cd "$DIR"

chmod +x srcds_run 2>/dev/null || true

mkdir -p csgo/cfg addons/sourcemod addons/metamod cfg/serveronly logs

if [[ ! -f csgo/cfg/autoexec.cfg ]]; then
    cat >csgo/cfg/autoexec.cfg <<'CFG'
// Создано GameDock
hostname "CS:GO сервер"
sv_password ""
mp_autoteambalance 1
mp_timelimit 30
mp_friendlyfire 0
bot_quota 0
sv_lan 0
CFG
fi

cat >run.sh <<RUNEOF
#!/usr/bin/env bash
set -e
cd "\$(dirname "\$0")"
export LD_LIBRARY_PATH="\$LD_LIBRARY_PATH:./linux64"
exec ./srcds_run -game cstrike -dedicated 1 -port ${GAME_PORT} -queryport ${QUERY_PORT} -maxplayers ${SLOTS} +map de_dust2
RUNEOF
chmod +x run.sh

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "CS:GO настроен"
