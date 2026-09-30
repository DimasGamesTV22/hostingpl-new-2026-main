#!/usr/bin/env bash
#
# Точка входа контейнера панели.
# Ждёт БД, при первом запуске ставит ключи и миграции, дальше — просто отдаёт FPM.
#
set -e

echo "[gamedock] Жду MySQL…"

for i in $(seq 1 60); do
    if php -r "
        try {
            new PDO(
                'mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'),
                getenv('DB_USERNAME'),
                getenv('DB_PASSWORD')
            );
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        echo "[gamedock] MySQL доступен"
        break
    fi

    if [ "$i" -eq 60 ]; then
        echo "[gamedock] MySQL недоступен после 60 попыток — выхожу"
        exit 1
    fi

    sleep 2
done

cd /var/www/html

# Каталоги для записи
mkdir -p storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         storage/app/public \
         bootstrap/cache

chown -R gamedock:gamedock storage bootstrap/cache 2>/dev/null || true

# APP_KEY
if [ -z "$APP_KEY" ]; then
    echo "[gamedock] Генерирую APP_KEY"
    php artisan key:generate --force
fi

# .env из переменных окружения, если его нет
if [ ! -f .env ]; then
    echo "[gamedock] Создаю .env из окружения"

    cat > .env <<ENVEOF
APP_NAME=${APP_NAME:-GameDock}
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL}
APP_TIMEZONE=${APP_TIMEZONE:-Europe/Moscow}
APP_LOCALE=${APP_LOCALE:-ru}
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

REDIS_CLIENT=predis
REDIS_HOST=${REDIS_HOST}
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=6379

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=10080
FILESYSTEM_DISK=${FILESYSTEM_DISK:-local}

MAIL_MAILER=${MAIL_MAILER:-log}
MAIL_FROM_ADDRESS=${MAIL_FROM_ADDRESS:-noreply@example.com}
MAIL_FROM_NAME="${APP_NAME:-GameDock}"

GD_NODE_MODE=${GD_NODE_MODE:-auto}
GD_RUNTIME=${GD_RUNTIME:-docker}
GD_AGENT_INBOUND=true
GD_TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN:-}
GD_TELEGRAM_ADMIN_CHAT_ID=${GD_TELEGRAM_ADMIN_CHAT_ID:-}
GD_YOOKASSA_SHOP_ID=${GD_YOOKASSA_SHOP_ID:-}
GD_YOOKASSA_SECRET_KEY=${GD_YOOKASSA_SECRET_KEY:-}
GD_TINKOFF_TERMINAL_KEY=${GD_TINKOFF_TERMINAL_KEY:-}
GD_TINKOFF_PASSWORD=${GD_TINKOFF_PASSWORD:-}
GD_CRYPTOBOT_TOKEN=${GD_CRYPTOBOT_TOKEN:-}
AWS_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID:-}
AWS_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY:-}
AWS_DEFAULT_REGION=${AWS_DEFAULT_REGION:-ru-central1}
AWS_BUCKET=${AWS_BUCKET:-}
AWS_ENDPOINT=${AWS_ENDPOINT:-}
ENVEOF

    chown gamedock:gamedock .env
fi

# Первый запуск: миграции и данные
if [ ! -f storage/.migrated ]; then
    echo "[gamedock] Первый запуск — миграции"

    php artisan config:clear
    php artisan migrate --force --no-interaction
    php artisan storage:link || true
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    touch storage/.migrated
    chown gamedock:gamedock storage/.migrated

    echo "[gamedock] Миграции выполнены"
else
    echo "[gamedock] Запуск без миграций"
    php artisan config:cache 2>/dev/null || true
fi

# Чистим старые логи
find storage/logs -name "*.log" -mtime +14 -delete 2>/dev/null || true

echo "[gamedock] Запускаю: $*"
exec "$@"
