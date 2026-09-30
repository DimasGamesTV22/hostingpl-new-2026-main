#!/usr/bin/env bash
#
# Установка Crime City Role Play (CRMP) и его форков
#
# CRMP-моды — это готовые сборки, которые пользователь загружает
# через панель (обычно платные/закрытые). Скрипт:
#   1. распаковывает загруженный мод;
#   2. генерирует config.json с портами и лимитами;
#   3. ставит зависимости (PHP + расширения).
#
# Сборки: cowa (Cowa v1), optim (Optim), aurora (Aurora)
#
set -euo pipefail

DIR="${GD_SERVER_DIR:-$(pwd)}"
BUILD="${GD_BUILD:-cowa}"
GAME_PORT="${GD_GAME_PORT:-7777}"
RCON_PORT="${GD_RCON_PORT:-7776}"
SLOTS="${GD_SLOTS:-100}"
SERVER_NAME="${GD_SERVER_NAME:-CRMP сервер}"
STARTER_CASH="${GD_STARTER_CASH:-50000}"
MAX_DEPOSIT="${GD_MAX_DEPOSIT:-1000000}"

log()  { echo -e "\033[36m[crmp]\033[0m $*"; }
ok()   { echo -e "\033[32m[crmp]\033[0m ✓ $*"; }
warn() { echo -e "\033[33m[crmp]\033[0m ! $*"; }
fail() { echo -e "\033[31m[crmp]\033[0m ✗ $*" >&2; exit 1; }

cd "$DIR"

# ── Зависимости ──────────────────────────────────────────────────────

log "Проверяю PHP…"

if ! command -v php >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        php-cli php-mysql php-mbstring php-curl php-xml php-zip php-sockets
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

if (( ${PHP_VER%%.*} < 8 )); then
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq php8.2-cli php8.2-mysql php8.2-mbstring 2>/dev/null || true
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
fi

log "PHP $PHP_VER"

# ── Получение мода ───────────────────────────────────────────────────

TEMPLATE_ROOT="${GD_TEMPLATES_ROOT:-/opt/gamedock/game-images}"
CANDIDATE="$TEMPLATE_ROOT/gta/crmp/builds/${BUILD}.tar.gz"

if [[ -f $CANDIDATE ]]; then
    log "Распаковываю сборку ${BUILD}…"
    tar -xzf "$CANDIDATE" -C "$DIR"
    ok "Сборка ${BUILD} распакована"
elif [[ -f "$DIR/server" || -f "$DIR/crmp-server" ]]; then
    log "Бинарник мода уже загружен через панель"
else
    fail "Сборка CRMP «${BUILD}» не найдена.

Что делать:
  1. Купите/скачайте сборку мода (${BUILD})
  2. Загрузите архив через панель (Файлы → загрузить) в корень сервера
  3. Или положите архив на ноду: ${CANDIDATE}
  4. Запустите переустановку

Моды Cowa / Optim / Aurora распространяются их авторами — панель
не может скачать их автоматически."
fi

# ── Структура каталогов ──────────────────────────────────────────────

mkdir -p cfxmods log plugins storage server-data

# ── Конфиг ───────────────────────────────────────────────────────────

if [[ ! -f config.json ]]; then
    log "Создаю config.json…"

    cat >config.json <<CFG
{
    "bindaddr": "0.0.0.0",
    "rconport": ${RCON_PORT},
    "maxplayers": ${SLOTS},
    "name": "${SERVER_NAME}",
    "gamemode": "1",
    "closed": false,
    "chatlog": true,
    "maxdeposit": ${MAX_DEPOSIT},
    "startercash": ${STARTER_CASH},
    "maxvehicledamage": 1000,
    "usepcre": true,
    "announce": true,
    "language": "ru",
    "weburl": "https://example.com",
    "discord": "",
    "homenumber": 0,
    "homemoney": 0,
    "fornumber": 0,
    "perm": 0
}
CFG

    ok "Создан config.json"
else
    # Обновляем порты в существующем конфиге
    php -r "
\$c = json_decode(file_get_contents('config.json'), true);
\$c['rconport'] = ${RCON_PORT};
\$c['maxplayers'] = ${SLOTS};
\$c['name'] = '${SERVER_NAME}';
file_put_contents('config.json', json_encode(\$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
" 2>/dev/null && ok "Параметры обновлены" || warn "Не удалось обновить config.json"
fi

# ── Запуск ───────────────────────────────────────────────────────────

if [[ ! -f run.sh ]]; then
    cat >run.sh <<'RUNEOF'
#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
exec ./server
RUNEOF
    chmod +x run.sh
fi

chmod +x server crmp-server 2>/dev/null || true
chown -R gamedock:gamedock "$DIR" 2>/dev/null || true

ok "CRMP (${BUILD}) готов к запуску"
