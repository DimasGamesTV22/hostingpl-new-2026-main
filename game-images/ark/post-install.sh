#!/usr/bin/env bash
#
# Постустановка ARK: Survival Evolved
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
SLOTS="${GD_SLOTS:-70}"
GAME_PORT="${GD_GAME_PORT:-7777}"
QUERY_PORT="${GD_QUERY_PORT:-27014}"
RCON_PORT="${GD_RCON_PORT:-25575}"
RCON_PASS="${GD_RCON_PASSWORD:-ChangeMe123}"
MAP="${GD_MAP:-TheIsland}"
SERVER_NAME="${GD_SERVER_NAME:-ARK сервер}"

log()  { echo -e "\033[36m[ark]\033[0m $*"; }
ok()   { echo -e "\033[32m[ark]\033[0m ✓ $*"; }

cd "$DIR"

chmod +x ShooterGameServer 2>/dev/null || true

# ── Каталог конфига ──────────────────────────────────────────────────

CONFIG_DIR="ShooterGame/Saved/Config/LinuxServer"
mkdir -p "$CONFIG_DIR" logs

if [[ ! -f "$CONFIG_DIR/GameUserSettings.ini" ]]; then
    log "Создаю GameUserSettings.ini…"

    cat >"$CONFIG_DIR/GameUserSettings.ini" <<INI
[/Script/EngineSettings.GameMapsSettings]
ServerName=${SERVER_NAME}
ServerPort=${GAME_PORT}
QueryPort=${QUERY_PORT}
RCONPort=${RCON_PORT}
MaxPlayers=${SLOTS}
bIsDedicated=True
LogTimes=True
ServerAdminPassword=${RCON_PASS}
AdminPassword=${RCON_PASS}
DifficultyOverride=0.0
OfficialSession=1
AllowCascadingSpawnsFromLargePlayerDrops=False
MaxActorsAllowedInParachutes=0
bAllowCancellations=True
[/Script/Engine.Engine]
+ActiveGameNameRedirects=(GameName="/Script/Temp",Redirection="/Script/ARK")
+ActiveGameDataRedirects=(GameData="Game_Default",Redirection="/Script/ARK")
INI

    ok "Создан GameUserSettings.ini"
fi

# ── Карты ────────────────────────────────────────────────────────────

log "Карта: $MAP"

if [[ ! -d "$DIR/$MAP" ]]; then
    warn "Директория карты $MAP не найдена. Проверьте правильность карты в настройках."
fi

# ── Скрипт запуска ───────────────────────────────────────────────────

cat >run.sh <<RUNEOF
#!/usr/bin/env bash
set -e
cd "\$(dirname "\$0")"
exec ./ShooterGameServer ${MAP} -port=${GAME_PORT} -QueryPort=${QUERY_PORT} -ServerAdminPassword=${RCON_PASS} -log
RUNEOF
chmod +x run.sh

chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "ARK настроен: $MAP, ${SLOTS} слотов"
