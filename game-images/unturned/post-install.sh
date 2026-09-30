#!/usr/bin/env bash
#
# Постустановка Unturned
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
SLOTS="${GD_SLOTS:-12}"
GAME_PORT="${GD_GAME_PORT:-27015}"
QUERY_PORT="${GD_QUERY_PORT:-27016}"
RCON_PORT="${GD_RCON_PORT:-27020}"
RCON_PASS="${GD_RCON_PASSWORD:-ChangeMe123}"
MAP="${GD_MAP:-PEI}"

log()  { echo -e "\033[36m[unturned]\033[0m $*"; }
ok()   { echo -e "\033[32m[unturned]\033[0m ✓ $*"; }

cd "$DIR"

chmod +x ServerHelper.sh 2>/dev/null || true

mkdir -p Server Sandbox Saves logs

# ── Commands.dat ─────────────────────────────────────────────────────

if [[ ! -f Server/Commands.dat ]]; then
    log "Создаю Commands.dat…"

    cat >Server/Commands.dat <<DAT
[
  {"Key":"Name","Value":"Unturned сервер"},
  {"Key":"MaxPlayers","Value":${SLOTS}},
  {"Key":"Port","Value":${GAME_PORT}},
  {"Key":"QueryPort","Value":${QUERY_PORT}},
  {"Key":"Password","Value":""},
  {"Key":"WelcomeText","Value":"Добро пожаловать на сервер GameDock!"},
  {"Key":"Map","Value":"${MAP}"},
  {"Key":"MapTime","Value":60},
  {"Key":"PvE","Value":"False"},
  {"Key":"BattlEye","Value":"False"},
  {"Key":"Password","Value":""},
  {"Key":"MaxPing","Value":400},
  {"Key":"Timeout","Value":30},
  {"Key":"VAC_Secure","Value":0},
  {"Key":"AdminAccess","Value":""},
  {"Key":"OwnerID","Value":76561198000000000}
]
DAT

    ok "Создан Commands.dat"
fi

# ── Sandbox ──────────────────────────────────────────────────────────

cat >Sandbox/Config.json <<SANDBOX
{
  "browser": {
    "AllowPlayersWhileInGame": true,
    "HideAdmins": false,
    "MaxPlayers": ${SLOTS},
    "Region": "RU",
    "Timeout": 30
  },
  "Server": {
    "VAC_Secure": false,
    "BattlEye": false,
    "Rocket": false,
    "Experimental": true,
    "Persistent": true,
    "SaveInterval": 300
  },
  "Objects": {
    "Spawn": "PEI",
    "Map": "${MAP}",
    "UseGlobalChat": true,
    "MaxPing": 400
  }
}
SANDBOX

# ── RCON (Commands.dat для админки) ──────────────────────────────────

if [[ ! -f RCON/config.json ]]; then
    mkdir -p RCON
    cat >RCON/config.json <<RCONEOF
{
  "Password": "${RCON_PASS}",
  "Port": ${RCON_PORT}
}
RCONEOF
fi

cat >run.sh <<RUNEOF
#!/usr/bin/env bash
set -e
cd "\$(dirname "\$0")"
exec ./ServerHelper.sh +InternetServer/MaxPlayers/${SLOTS} +GamePort/${GAME_PORT} +QueryPort/${QUERY_PORT} +secureserver/${RCON_PASS}
RUNEOF
chmod +x run.sh

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "Unturned настроен: карта ${MAP}, ${SLOTS} слотов"
