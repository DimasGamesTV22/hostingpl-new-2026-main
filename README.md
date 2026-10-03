# GameDock

Панель управления игровым хостингом: веб-панель, агент ноды, Telegram-бот и
автоустановщик. Полный аналог панелей игровых серверов (HOSTINPL 5.6 и подобных),
написанный с нуля на Laravel 11 и Node.js.

```
gamedock/
├── panel/       Laravel 11 — веб-панель, API, биллинг, админка
├── agent/       Node.js — агент ноды (консоль, процессы, файлы, метрики)
├── bot/         Telegram-бот (уведомления, приём оплаты Криптобот)
├── game-images/ Шаблоны игровых серверов
├── deploy/      Автоустановщик и скрипт агента
├── docs/        Документация
├── docker-compose.yml
└── .env.docker.example
```

---

## Возможности

| Раздел | Что внутри |
| --- | --- |
| Игры | Minecraft Java и Bedrock, CS2 и CS:GO, GTA (SAMP, CRMP, RAGEMP, ALTV), MTA:SA, Rust, Unturned, ARK, а также любая своя игра через редактор в админке |
| Управление | Старт, стоп, рестарт, kill, веб-консоль в реальном времени, файловый менеджер, редактор кода, плагины в один клик, автоустановка, автообновление, watchdog, cron-планировщик |
| Ресурсы | Лимиты CPU, RAM, swap, диска, сети и числа процессов через cgroups, Docker, LXC или systemd |
| Ноды | Несколько нод и три режима: авто-распределение, ручной выбор, одна нода |
| Рантаймы | Docker, Podman, Proxmox LXC, нативный systemd с cgroups — переключается в конфиге и в админке |
| Биллинг | Тарифы по слотам и по ресурсам, кошелёк, ЮKassa, Тинькофф, Криптобот, автосъём при нулевом балансе |
| Маркетинг | Промокоды (скидка, срок, бонус), реферальная программа, секретные коды для игроков |
| Мониторинг | Графики CPU, RAM, сети и диска, онлайн, публичный статус, алерты в Telegram |
| Прочее | Бэкапы по расписанию, ротация логов, тикеты, роли, аудит действий, 2FA, языки RU и EN |

## Требования

| Параметр | Значение |
| --- | --- |
| ОС | Debian 11, 12, 13 или Ubuntu 22.04, 24.04 |
| PHP | 8.2 или новее (для Debian 11 и Ubuntu 22.04 ставится с packages.sury.org) |
| Node.js | 20 или новее (нужен для агента и сборки фронтенда) |
| Диск | минимум 5 ГБ, рекомендуется 20 ГБ |
| ОЗУ | 2 ГБ для панели без игровых серверов |
| Доступ | root или sudo, домен и открытые порты 80 и 443 |

---

## Установка

Способов три. Первый рекомендуется: он проверяет систему, ставит всё и
настраивает службы. Второй — если нужен контроль над каждым шагом. Третий —
для теста и для машин без systemd.

### 1. Автоустановщик (рекомендуется)

Одна команда, дальше появится меню:

```bash
curl -fsSL https://raw.githubusercontent.com/DimasGamesTV22/hostingpl-new-2026-main/main/deploy/install.sh | sudo bash
```

Меню содержит подпункты — можно ставить не всё сразу, а по частям:

```
 - 1 -  Веб-сервер и база (nginx, PHP-FPM, MariaDB, Redis)
 - 2 -  Панель GameDock (код, база, nginx, сертификат)
 - 3 -  Игровое окружение (Java, SteamCMD, сборочные пакеты)
 - 4 -  Агент ноды (управление игровыми серверами)
 - 5 -  Telegram-бот (уведомления, оплата, управление)

 - 6 -  phpMyAdmin (веб-доступ к базам)
 - 7 -  Службы и бэкапы (CRON, бэкапы, логи)
 - 8 -  Диагностика и статус
 - 9 -  Обновить панель (код, зависимости, миграции)

 - A -  Установить ВСЁ в один клик (панель + агент + бот)
 - 0 -  Выход
```

Меню — это тот же файл, что и линейный сценарий. Запустить его явно:

```bash
sudo bash install.sh --menu
```

#### Без диалога (CI, Docker, автоматизация)

```bash
curl -fsSL https://raw.githubusercontent.com/DimasGamesTV22/hostingpl-new-2026-main/main/deploy/install.sh \
  | sudo bash -s -- \
      --domain panel.example.com \
      --email admin@example.com \
      --name GameDock \
      --node-mode auto \
      --runtime docker \
      --with-agent \
      --yes
```

#### Ключи

| Ключ | Значение |
| --- | --- |
| `--domain <домен>` | Домен панели, например `panel.example.com` |
| `--email <email>` | Email администратора — он же логин в панель |
| `--name <имя>` | Название панели, по умолчанию `GameDock` |
| `--node-mode <режим>` | `single`, `manual` или `auto` |
| `--runtime <рантайм>` | `docker`, `podman`, `lxc` или `native` |
| `--with-agent` / `--no-agent` | Ставить агента на этой же машине |
| `--no-nginx` | Не ставить и не настраивать nginx |
| `--no-ssl` | Не получать сертификат Let's Encrypt |
| `--with-tinkoff` | Включить приём оплаты через Тинькофф |
| `--db <mysql\|external>` | Локальная MariaDB или уже существующая |
| `--php <версия>` | Версия PHP, по умолчанию лучшая доступная в системе |
| `--node-major <N>` | Старшая версия Node.js для агента, по умолчанию 20 |
| `--queue-workers <N>` | Число процессов очереди, по умолчанию 2 |
| `--dir <путь>` | Каталог установки, по умолчанию `/opt/gamedock` |
| `--no-phpmyadmin` | Не ставить phpMyAdmin |
| `--pma-path <путь>` | Адрес phpMyAdmin, по умолчанию `/phpmyadmin` |
| `--pma-user <имя>` | Имя учётки phpMyAdmin, по умолчанию `gamedock` |
| `--pma-allow "IP,IP"` | Пускать phpMyAdmin только с этих адресов |
| `-y`, `--yes` | Без вопросов, все ответы по умолчанию |
| `-h`, `--help` | Справка по ключам |

В конце установки выводятся пароль администратора панели и пароль
phpMyAdmin. Их также можно посмотреть позже:

```bash
sudo cat /root/.gamedock-phpmyadmin.txt   # пароль phpMyAdmin
sudo cat /root/.gamedock-db-root.txt      # пароль root MariaDB
```

### 2. Ручная установка

Шаги ниже повторяют то, что делает автоустановщик. Подходит, когда нужно
держать под контролем каждую команду или когда автоустановщик не подходит
из-за особенностей системы.

Подставляйте свои значения: `DOMAIN`, `EMAIL`, `PHPVER`.

```bash
DOMEN=panel.example.com
EMAIL=admin@example.com
PHPVER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
```

#### Шаг 1. Базовые пакеты

```bash
sudo apt update
sudo apt install -y git curl unzip mariadb-server redis-server nginx \
                    software-properties-common ca-certificates lsb-release
```

#### Шаг 2. PHP и расширения

```bash
sudo apt install -y "php${PHPVER}-fpm" "php${PHPVER}-cli" \
    "php${PHPVER}-mysql" "php${PHPVER}-mbstring" "php${PHPVER}-xml" \
    "php${PHPVER}-curl" "php${PHPVER}-zip" "php${PHPVER}-bcmath" \
    "php${PHPVER}-gd" "php${PHPVER}-intl"
```

Если нужной версии нет в репозитории (Debian 11, Ubuntu 22.04) — подключите
packages.sury.org по официальной инструкции и поставьте PHP оттуда.

Настройки PHP для панели:

```bash
sudo tee "/etc/php/${PHPVER}/fpm/conf.d/99-gamedock.ini" >/dev/null <<'INI'
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
max_input_time = 300
opcache.enable = 1
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
expose_php = Off
display_errors = Off
INI
```

Отдельный FPM-пул под панель — чтобы игровые запросы не мешали панели:

```bash
sudo mkdir -p /run/php /var/log/php
sudo chown www-data:www-data /run/php
sudo useradd --system --home /home/gamedock --shell /bin/bash gamedock || true

sudo tee "/etc/php/${PHPVER}/fpm/pool.d/gamedock.conf" >/dev/null <<'POOL'
[gamedock]
user = gamedock
group = gamedock
listen = /run/php/gamedock-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500

php_admin_value[error_log] = /var/log/php/gamedock-error.log
php_admin_flag[log_errors] = on
POOL

sudo systemctl restart "php${PHPVER}-fpm"
```

#### Шаг 3. Composer и Node.js

```bash
# Composer: сперва из репозитория дистрибутива, и только потом с сайта
sudo apt install -y composer || true
command -v composer >/dev/null || {
  cd /tmp
  curl -sS https://getcomposer.org/installer -o composer-setup.php
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f composer-setup.php
}

# Node.js 20+ — из NodeSource
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

node -v && npm -v && composer --version
```

#### Шаг 4. База и Redis

```bash
sudo systemctl enable --now mariadb redis-server

sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS gamedock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'gamedock'@'127.0.0.1' IDENTIFIED BY 'СЮДА_ПАРОЛЬ';
GRANT ALL PRIVILEGES ON gamedock.* TO 'gamedock'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

Задайте Redis пароль и включите его в конфигурации:

```bash
REDIS_PASS=$(openssl rand -hex 32)

# Отдельный файл: так настройки панели не смешиваются с системными.
# Каталог /etc/redis/redis.conf.d/ Debian сам не подключает — строка
# include в его redis.conf закомментирована, поэтому подключаем явно.
sudo tee /etc/redis/redis.conf.gamedock >/dev/null <<EOF
# Дополнение GameDock к /etc/redis/redis.conf
bind 127.0.0.1 ::1
requirepass ${REDIS_PASS}
maxmemory 512mb
maxmemory-policy allkeys-lru
EOF

# Подключаем файл (идемпотентно: повторный запуск ничего не дублирует)
grep -q 'redis.conf.gamedock' /etc/redis/redis.conf \
  || echo "include /etc/redis/redis.conf.gamedock" >> /etc/redis/redis.conf

sudo systemctl restart redis-server

# Проверяем, что пароль принят
redis-cli -a "${REDIS_PASS}" ping    # ожидается PONG
echo "Пароль Redis: ${REDIS_PASS}"
```

#### Шаг 5. Код

```bash
sudo mkdir -p /opt/gamedock
sudo git clone https://github.com/DimasGamesTV22/hostingpl-new-2026-main.git /opt/gamedock
cd /opt/gamedock/panel

# Каталоги Laravel должны существовать до установки зависимостей
sudo mkdir -p bootstrap/cache storage/framework/{cache,sessions,views} \
             storage/logs storage/app/public public/build

sudo composer install --no-dev --optimize-autoloader
sudo npm ci && sudo npm run build
sudo php artisan storage:link
```

#### Шаг 6. `.env`

```bash
sudo tee /opt/gamedock/panel/.env >/dev/null <<EOF
APP_NAME="GameDock"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL="https://${DOMEN}"
APP_TIMEZONE=Europe/Moscow
APP_LOCALE=ru
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gamedock
DB_USERNAME=gamedock
DB_PASSWORD="СЮДА_ПАРОЛЬ_БАЗЫ"

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD="${REDIS_PASS}"

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@${DOMEN}"
MAIL_FROM_NAME="GameDock"

GD_BRAND_NAME="GameDock"
GD_SUPPORT_EMAIL="support@${DOMEN}"
EOF

sudo chown gamedock:gamedock /opt/gamedock/panel/.env
sudo chmod 640 /opt/gamedock/panel/.env

sudo -u gamedock php artisan key:generate --force
```

> Значения с пробелами обязательно берите в кавычки. Без кавычек Laravel
> не сможет разобрать `.env` и упадёт с
> `Failed to parse dotenv file. Encountered unexpected whitespace`.

#### Шаг 7. Миграции и права

```bash
cd /opt/gamedock/panel
sudo -u gamedock php artisan migrate --force
sudo -u gamedock php artisan config:clear
sudo chown -R gamedock:gamedock /opt/gamedock
```

#### Шаг 8. nginx

```bash
sudo tee "/etc/nginx/sites-available/gamedock" >/dev/null <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMEN};

    root /opt/gamedock/panel/public;
    index index.php;
    charset utf-8;
    client_max_body_size 128M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/gamedock-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    location ~ /\. { deny all; }
}
EOF

sudo ln -sfn /etc/nginx/sites-available/gamedock /etc/nginx/sites-enabled/gamedock
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

#### Шаг 9. Службы

Панели нужны три службы: WebSocket-сервер (связь с агентами), воркеры очереди
и планировщик (биллинг, автосъём, бэкапы).

```bash
sudo tee /etc/systemd/system/gamedock-wss.service >/dev/null <<EOF
[Unit]
Description=GameDock WSS server (agent connections)
After=network.target redis-server.service

[Service]
Type=simple
User=gamedock
Group=gamedock
WorkingDirectory=/opt/gamedock/panel
ExecStart=/usr/bin/php /opt/gamedock/panel/artisan gamedock:ws-server --host=0.0.0.0 --port=9222
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

sudo tee /etc/systemd/system/gamedock-queue@.service >/dev/null <<EOF
[Unit]
Description=GameDock queue worker #%i
After=network.target redis-server.service

[Service]
Type=simple
User=gamedock
Group=gamedock
WorkingDirectory=/opt/gamedock/panel
ExecStart=/usr/bin/php /opt/gamedock/panel/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --name=gamedock-%i
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

sudo tee /etc/systemd/system/gamedock-scheduler.service >/dev/null <<EOF
[Unit]
Description=GameDock scheduler (cron tasks, billing, alerts)
After=network.target redis-server.service

[Service]
Type=simple
User=gamedock
Group=gamedock
WorkingDirectory=/opt/gamedock/panel
ExecStart=/usr/bin/php /opt/gamedock/panel/artisan schedule:work
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now gamedock-wss gamedock-scheduler
sudo systemctl enable    "gamedock-queue@.service"
sudo systemctl start     gamedock-queue@1 gamedock-queue@2
```

> У юнитов обязательно должна быть секция `[Install]` с
> `WantedBy=multi-user.target`. Без неё systemd считает юнит статическим,
> `systemctl enable` ничего не делает, и после перезагрузки службы не
> поднимаются. Для шаблона очереди включается сам шаблон
> `gamedock-queue@.service`, а не инстансы по одному.

#### Шаг 10. Пароль администратора

```bash
cd /opt/gamedock/panel
sudo -u gamedock php artisan gamedock:admin-password --email="${EMAIL}"
```

Команда спросит пароль и покажет его. Логин в панель — это email.

#### Шаг 11. Сертификат (по желанию)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d "${DOMEN}" --agree-tos -m "${EMAIL}" --redirect
```

#### Шаг 12. Проверка

```bash
systemctl is-active nginx mariadb redis-server gamedock-wss gamedock-scheduler
curl -I "http://${DOMEN}"
sudo -u gamedock -- cd /opt/gamedock/panel && php artisan migrate:status | tail -3
```

### 3. Docker Compose

Поднимает всю стек-систему без systemd: панель, очередь, планировщик, WSS,
бот, локальный агент, MariaDB, Redis и nginx.

```bash
git clone https://github.com/DimasGamesTV22/hostingpl-new-2026-main.git
cd hostingpl-new-2026-main
cp .env.docker.example .env
# впишите DB_PASSWORD и REDIS_PASSWORD, а также APP_URL
docker compose up -d
docker compose ps
```

Логи и перезапуск:

```bash
docker compose logs -f panel
docker compose restart panel
docker compose down
```

---

## Что после установки

### Адреса

| Что | Где |
| --- | --- |
| Панель | `https://ваш-домен` |
| phpMyAdmin | `https://ваш-домен/phpmyadmin/` (вход только по паролю) |
| WebSocket для агентов | `wss://ваш-домен/agent/ws` |

### Службы

```bash
systemctl status gamedock-wss gamedock-scheduler "gamedock-queue@1"
journalctl -u gamedock-scheduler -f      # журнал планировщика
journalctl -u "gamedock-queue@1" -f      # журнал воркера очереди
journalctl -u gamedock-wss -f           # журнал WebSocket-сервера
```

### Пути

| Путь | Что лежит |
| --- | --- |
| `/opt/gamedock` | Корень установки |
| `/opt/gamedock/panel` | Панель (Laravel) |
| `/opt/gamedock/agent` | Агент ноды |
| `/opt/gamedock/game-images` | Шаблоны игровых серверов |
| `/var/lib/gamedock` | Состояние установки, параметры установки |
| `/etc/gamedock` | Конфигурации агентов нод |
| `/var/log/gamedock-run.log` | Журнал установщика |

### Учётные записи

| Что | Логин | Где взять пароль |
| --- | --- | --- |
| Панель | email администратора | Печатает установщик; сменить: `php artisan gamedock:admin-password --email=ВАШ_EMAIL` |
| phpMyAdmin | `gamedock` | Печатает установщик, также `/root/.gamedock-phpmyadmin.txt` |
| MariaDB root | `root` через unix-сокет | `/root/.gamedock-db-root.txt` |

---

## Агент ноды

Агент ставится на каждую машину, где должны работать игровые серверы.

```bash
curl -fsSL https://raw.githubusercontent.com/DimasGamesTV22/hostingpl-new-2026-main/main/deploy/agent.sh \
  | sudo bash -s -- --panel https://ваш-домен
```

Или одной командой с панели: в админке создайте ноду, скопируйте токен и
выполните на сервере с игровыми серверами:

```bash
sudo gamedock-agent-install --panel https://ваш-домен --token ТОКЕН_ИЗ_ПАНЕЛИ
```

---

## Обновление


Панель обновляется из установщика — пункт 9 главного меню
(«Обновить панель»). Он делает резервную копию `.env` и кода, обновляет
PHP-зависимости и фронтенд, снимает кеш Laravel, выполняет миграции и
перезапускает службы. База и файлы игровых серверов не затрагиваются.

```bash
sudo bash install.sh --menu     # затем выбрать пункт 9
```

```bash
cd /opt/gamedock
sudo git pull
cd panel
sudo composer install --no-dev --optimize-autoloader
sudo npm ci && sudo npm run build
sudo -u gamedock php artisan migrate --force
sudo -u gamedock php artisan config:clear
sudo systemctl restart gamedock-wss gamedock-scheduler "gamedock-queue@1" "gamedock-queue@2"
```

Через автоустановщик: `sudo bash /root/deploy/install.sh --yes` — шаги, которые уже
сделаны, пропускаются.

## Удаление

```bash
sudo systemctl disable --now gamedock-wss gamedock-scheduler \
     "gamedock-queue@1" "gamedock-queue@2" 2>/dev/null
sudo rm -f /etc/systemd/system/gamedock-*.service
sudo systemctl daemon-reload
sudo rm -rf /opt/gamedock
sudo rm -f /etc/nginx/sites-available/gamedock \
           /etc/nginx/sites-enabled/gamedock
sudo nginx -t && sudo systemctl reload nginx
```

Базу данных и учётную запись MariaDB нужно удать отдельно — установщик их не
трогает, чтобы при переустановке данные не пропали.

---

## Два файла для раздачи

Один и тот же установщик можно раздать двумя способами. Собираются оба из
одного исходника `deploy/install.sh` командой:

```bash
bash deploy/build-dist.sh
```

| Файл | Что это | Запуск |
|---|---|---|
| `deploy/dist/gamedock-install.sh` | открытый исходник, ровно копия `install.sh` | `bash gamedock-install.sh` |
| `deploy/dist/gamedock-install` | бинарник `shc`, исходника не видно | `sudo ./gamedock-install` |
| `deploy/dist/gamedock-install.enc.sh` | запасной вариант, шифрование через `openssl` | `bash gamedock-install.enc.sh` |

Друзьям отправляют **один** файл — тот, что получился. Класть рядом
`gamedock-install.sh` не нужно: тогда исходник достанут без труда.

Бинарник `shc` собирается только при наличии компилятора C. Если его нет,
`build-dist.sh` скажет об этом и соберёт запасной вариант:

```bash
sudo apt-get install -y build-essential   # чтобы собрать бинарник
bash deploy/build-dist.sh                 # или без него — соберётся enc.sh
```

### Про то, что это не полноценное шифрование

В bash нельзя сделать по-настоящему секретный файл: всё, что выполняется на
машине, можно прочитать. Разница между вариантами — только в том, сколько
усилий придётся приложить.

- **shc** прячет текст внутрь ELF. `cat` не покажет исходник, файл выглядит
  как обычная программа. Но `strings` покажет читаемые строки, а при
  отладке исходник восстанавливается.
- **openssl** шифрует тело, ключ лежит в том же файле. Вручную раскрыть
  сложнее, но ключ физически внутри файла — тот, кто целенаправленно ищет,
  дойдёт до него.

Для человека, который не копается намеренно, оба варианта непроницаемы.
Для того, кто ищет специально, — нет. Это свойство языка, а не недостаток
упаковки.

Ещё одна оговорка: бинарник собирается под ту архитектуру, на которой собран.
Собранный на x86_64 Linux бинарник на другой архитектуре не запустится —
тогда подойдёт `gamedock-install.enc.sh`.

### Отдельная установка игровой ноды

Пункт 3 меню («Игровое окружение») ставит Java, SteamCMD и сборочные пакеты
без панели, PHP, базы и nginx. То же самое из командной строки:

```bash
sudo bash install.sh --only game -y
```

## Разработка и проверки

Тесты не требуют PHP и работают в обычном Git Bash или Linux-терминале.

```bash
# Установщик: порядок шагов, ключи, служебные файлы, systemd, сеть
bash panel/tools/test-system-checks.sh

# Логика установщика: обработка ошибок, повторы, работа с файлами
bash panel/tools/test-install-logic.sh

# Меню: отрисовка, подпункты, переходы
bash panel/tools/test-menu.sh

# Выбор режима запуска (меню против линейного сценария)
bash panel/tools/check-mode.sh

# Сборка дистрибутива: оба файла собираются, упакованный запускается,
# исходник в нём не читается
bash panel/tools/test-build-dist.sh

# Статические проверки кода (Node.js)
node panel/tools/check-integrity.cjs         # потерянные скобки и слэши
node panel/tools/check-php-refs.cjs          # несуществующие классы, видимость
node panel/tools/check-migration-order.cjs   # порядок внешних ключей
node panel/tools/check-migration-index.cjs   # дубли индексов в миграциях

# Разрешимость импортов в agent/ и bot/
# node --check ловит только синтаксис: битой путь в импорте он пропускает,
# а модуль из-за этого просто не загружается. Так был сломан
# server-manager.js — агент не стартовал ни разу.
node panel/tools/check-imports.cjs
```

PHP-тесты панели (нужен PHP и Composer):

```bash
cd panel
composer install
php artisan test
```

Тесты агента и бота:

```bash
cd agent && npm test
cd bot   && npm test
cd agent && npm test               # 47 тестов, включая подпись и спецификацию
cd bot   && npm test               # 7 тестов
```

---

## Документация

- [Установка](docs/installation.md)
- [Архитектура](docs/architecture.md)
- [API](docs/api.md)
- [Админ-панель и конфигурация](docs/configuration.md)
- [Решение проблем](docs/troubleshooting.md)

---

## Частые вопросы

**Панель отвечает `HTTP 500`, в логе `Failed to parse dotenv file`**

В `.env` есть значение с пробелом без кавычек. Такие значения обязаны быть в
кавычках: `APP_NAME="GameDock"`, `APP_URL="https://домен"`.

**После перезагрузки не работают очередь и планировщик**

У юнитов нет секции `[Install]`. Проверьте:

```bash
systemctl is-enabled gamedock-scheduler "gamedock-queue@.service"
```

Должно быть `enabled`.

**Машина перестала отвечать сразу после установки**

Проверьте параметры ядра — в частности `rp_filter`. В сетях за NAT строгий
режим (`1`) рвёт входящие пакеты, и машина не отвечает ни на ping, ни на SSH,
хотя работает:

```bash
sysctl net.ipv4.conf.all.rp_filter net.ipv4.conf.default.rp_filter
```

Должно быть `2` (мягкий режим). Исправить:

```bash
sudo sysctl -w net.ipv4.conf.all.rp_filter=2
sudo sysctl -w net.ipv4.conf.default.rp_filter=2
```

**Не могу войти по SSH, ping идёт**

Похоже на бан fail2ban: он блокирует только порт 22, поэтому ping работает, а
SSH не отвечает. Подождите истечения бана и добавьте свои адреса в исключения:

```bash
sudo fail2ban-client status sshd
sudo fail2ban-client set sshd unbanip ВАШ_IP

# и чтобы не повторялось
echo "ВАШ_IP" | sudo tee -a /root/.gamedock-ssh-allowed
sudo bash /root/deploy/install.sh --yes   # пересоберёт конфиг с исключениями
```

**Не открывается phpMyAdmin**

```bash
sudo cat /root/.gamedock-phpmyadmin.txt     # логин и пароль
curl -I "http://ваш-домен/phpmyadmin/"       # ждём 301 на адрес со слешем
```

Вход в phpMyAdmin закрыт паролем nginx, а не учёткой MySQL. Пароль MySQL для
базы панели лежит в `.env` панели в строке `DB_PASSWORD`.

**Игровые серверы не запускаются при рантайме `docker`**

Проверьте, что Docker установлен:

```bash
docker version
systemctl status docker
```

**SteamCMD недоступен на Debian 13**

Пакет `steamcmd` убран из репозиториев Debian 13. Установщик это замечает и
продолжает. Для CS2, Rust, Unturned и ARK поставьте SteamCMD вручную или
используйте контейнерный рантайм.

---

## Лицензия

См. файл `LICENSE` в репозитории.
