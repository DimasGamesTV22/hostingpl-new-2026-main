# Установка и развёртывание

Три способа установки — выберите свой.

| Способ | Когда использовать | Сложность |
|---|---|---|
| **A. Автоустановщик (рекомендуется)** | Панель на выделенном сервере Debian 11–13 или Ubuntu 22.04/24.04 | ★☆☆ |
| **B. Docker Compose** | Тест, локальная разработка, всё в одном, любая ОС | ★☆☆ |
| **C. Вручную** | Нестандартная конфигурация, свой веб-сервер | ★★★ |

---

## Способ A. Автоустановщик

### Требования

| Параметр | Минимум | Рекомендуется |
|---|---|---|
| ОС | Debian 11 (bullseye) | Debian 13 (trixie) или Ubuntu 24.04 (noble) |
| vCPU | 1 | 2 |
| RAM | 2 ГБ | 4 ГБ |
| Диск | 20 ГБ SSD | 60 ГБ SSD |
| Домен | с A-записью на этот сервер | + Cloudflare |

Установщик проверяет свободное место на разделе, который примет каталог
`/opt/gamedock`, и просит минимум 5 ГБ (`GD_MIN_DISK_GB`), предупреждает при
меньше 20 ГБ (`GD_RECOMMENDED_DISK_GB`). Проверяется именно существующий раздел:
сам каталог на чистой системе появляется только в процессе установки.

### Поддерживаемые системы

Установщик проверяет ОС и отказывается ставить на неподдерживаемую, печатая список.
Матрица живёт в одной переменной `SUPPORTED_SYSTEMS` в начале `deploy/install.sh`
и продублирована в `deploy/agent.sh`; расхождение между ними и с этой таблицей
ловит `node panel/tools/check-distros.cjs`.

| Система | Кодовое имя | PHP в дистрибутиве | PHP ставится | Что делает установщик |
|---|---|---|---|---|
| Debian 11 | bullseye | 7.4 | 8.3 | подключает `packages.sury.org` |
| Debian 12 | bookworm | 8.2 | 8.2 | берёт PHP из дистрибутива |
| Debian 13 | trixie | 8.4 | 8.4 | берёт PHP из дистрибутива |
| Ubuntu 22.04 | jammy | 8.1 | 8.3 | подключает `packages.sury.org` |
| Ubuntu 24.04 | noble | 8.3 | 8.3 | берёт PHP из дистрибутива |

Laravel 11 требует PHP ≥ 8.2, поэтому там, где в дистрибутиве версия ниже,
подключается `packages.sury.org` — официальный репозиторий PHP, который ведёт
и Debian, и Ubuntu.

Дополнительно установщик всегда подключает **NodeSource** (Node.js 20 — нужен
агенту) и **docker.com** (если выбран рантайм `docker`), потому что в самих
дистрибутивах подходящих версий нет. На Ubuntu 24.04 sources-файлы в формате
deb822 — установщик умеет править оба формата (`deb …` и `Components: …`).

Версию PHP можно задать принудительно:

```bash
sudo ./install.sh --php 8.3 …
```

Отдельная тонкость: **Debian 11 по умолчанию использует cgroup v1**, а рантайм
`native` без cgroup v2 не работает. Установщик это обнаружит и предложит включить
v2 через параметр ядра (потребуется перезагрузка и повторный запуск). Рантаймы
`docker` и `podman` от этого не зависят.

Проверить матрицу и логику без установки:

```bash
node panel/tools/check-distros.cjs      # матрица согласована с документацией
bash panel/tools/test-install-logic.sh  # detect_distro, apt, PHP-пакеты
bash panel/tools/test-menu.sh           # меню, подменю, гейт, падение установщика
bash panel/tools/check-mode.sh          # выбор режима запуска
bash panel/tools/test-system-checks.sh  # порядок шагов, ключи, systemd, сеть
```

Полные требования — в [requirements.md](requirements.md).

### Шаг 1. Подготовка

```bash
# Обновляем систему
apt update && apt upgrade -y

# Ставим базовые пакеты
apt install -y curl ca-certificates git

# Проверяем, что домен смотрит на этот сервер
dig +short panel.example.com
# Должен вывести IP вашего сервера
```

> **Ошибка `Репозиторий «cdrom:///…» не содержит файла Release`** — машина
> поставлена с установочного ISO, и в `/etc/apt/sources.list` осталась строка
> `deb cdrom:/[Debian GNU/Linux …] trixie main`. Сам образ к серверу не
> подключён, поэтому `apt update` на ней всегда будет падать. Установщик
> GameDock такие строки сам комментирует (копия остаётся рядом в
> `<файл>.gamedock.bak`). Вручную — одной командой:
>
> ```bash
> sed -i -E 's|^[[:space:]]*(deb(-src)?[[:space:]]+(cdrom|file):)|# \1|' \
>     /etc/apt/sources.list /etc/apt/sources.list.d/*.list 2>/dev/null
> apt update
> ```

### Шаг 2. Запуск

**Вариант 1 — меню** (рекомендуется). Одно окно со всеми операциями:

```bash
curl -fsSL https://raw.githubusercontent.com/DimasGamesTV22/hostingpl-new-2026-main/main/deploy/install.sh | sudo bash
```

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

Пункты 5–7 открывают подменю, из любого подменю возвращаемся по `0`.
Строки `Панель: … Веб: … Агент: …` показывают текущее состояние машины, а
уже установленные пункты помечены `[уже стоит]`.

> **Пункт 2 требует веб-сервер.** Если nginx/Apache не найден, установщик
> предложит его поставить или вернёт вас в меню к пункту 1. Ставьте
> веб-сервер раньше панели — либо сразу используйте пункт 8 («всё в один клик»).

**Вариант 2 — без меню**, одной командой и без вопросов:

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

Либо скачайте и запустите с параметрами:

```bash
wget https://raw.githubusercontent.com/DimasGamesTV22/hostingpl-new-2026-main/main/deploy/install.sh
chmod +x install.sh

sudo ./install.sh \
  --domain panel.example.com \
  --email admin@example.com \
  --name "Мой хостинг" \
  --node-mode auto \
  --runtime docker \
  --with-agent
```

#### phpMyAdmin

Устанавливается по умолчанию и публикуется на домене панели по адресу
`https://<домен>/phpmyadmin/`. Вход закрыт паролем, который установщик
создаёт и печатает в конце установки, а также кладёт в
`/root/.gamedock-phpmyadmin.txt` (права `600`).

Это **отдельная учётка nginx**, а не запись в базе: перебором по самой базе
до интерфейса не добраться. Пароль при повторном запуске установщика не
меняется.

```bash
# Только с вашего IP — самый надёжный способ закрыть интерфейс
sudo ./install.sh --domain panel.example.com --email admin@example.com \
  --pma-allow "203.0.113.10,198.51.100.7"

# Другой адрес и имя учётки
sudo ./install.sh --pma-path /db --pma-user admin

# Не ставить вовсе
sudo ./install.sh --no-phpmyadmin
```

| Что | Где |
|---|---|
| Конфиг nginx | `/etc/nginx/snippets/gamedock-phpmyadmin.conf` |
| Пароли для входа | `/etc/nginx/.htpasswd-gamedock` (640) |
| Логин и пароль открытым текстом | `/root/.gamedock-phpmyadmin.txt` (600) |
| Логи доступа | `/var/log/nginx/phpmyadmin-access.log` |

phpMyAdmin ставится пакетом `phpmyadmin` с ключом `--no-install-recommends`:
без него `apt` тянет Apache (в `Recommends` пакета стоит
`libapache2-mod-php | lighttpd | nginx | …`) и второй PHP из Debian рядом с
тем, что установщик ставит с `packages.sury.org`. На Debian 13 оба лишних
пакета — Apache и PHP 8.5 — при `--no-install-recommends` не ставятся.

Доступ к файлам панели закрыт через `open_basedir`: phpMyAdmin видит свои
каталоги и `/usr/share/php` (там Debian держит общие библиотеки), но не
видит `.env` панели с паролем от базы.

### Шаг 3. Что спрашивает установщик

```
▸ Настройка панели
  Домен панели (без https://) [panel.example.com]:
  Email администратора [admin@example.com]:
  Название панели [GameDock]:

  Режим работы с нодами:
    single — одна нода, ничего не выбирается (для теста/одного VPS)
    manual — ноду выбирает администратор
    auto   — панель сама распределяет по свободным ресурсам
  Режим нод (single/manual/auto) [auto]:

  Рантайм игровых процессов:
    docker — контейнеры (рекомендуется)
    podman — альтернатива Docker
    lxc    — контейнеры Proxmox VE
    native — процессы через systemd + cgroup v2 (без Docker)
  Рантайм (docker/podman/lxc/native) [docker]:

▸ Маркетинговые механики
  Промокоды со скидкой? [y/n] (y)
  Промокоды на срок аренды? [y/n] (y)
  Промокоды с бонусом? [y/n] (y)
  Реферальная программа? [y/n] (y)
  Секретные коды для игроков? [y/n] (y)
  Тестовый период при регистрации? [y/n] (y)
  Сколько дней теста [3]:

▸ Приём оплаты
  ЮKassa (карты, СБП)? [y/n] (y)
  Shop ID ЮKassa:
  Секретный ключ ЮKassa:
  Т-Банк Интернет-магазин? [y/n] (n)
  Криптобот (крипта в Telegram)? [y/n] (y)
  Токен @CryptoBot:
  Ручной приём оплаты? [y/n] (y)

▸ Telegram-уведомления (необязательно)
  Настроить Telegram-бота для алертов? [y/n] (n)
```

Ответы записываются в `config/hosting.php` — это тот самый «файл выбора».

### Шаг 4. Что установилось

```
/opt/gamedock/
├── panel/                ← панель (Laravel 11)
│   ├── app/
│   ├── config/hosting.php  ← все настройки в одном файле
│   └── .env                ← секреты (БД, Redis, платежи)
├── agent/                ← агент ноды (Node.js)
/opt/gamedock/game-images/ ← скрипты установки игр
/etc/gamedock/            ← конфигурации агентов
/var/lib/gamedock/        ← параметры установки (install-params.sh)

systemd:
  gamedock-wss.service       ← WSS-сервер (порт 9222) — держит связь с агентами
  gamedock-queue@1..N        ← очереди задач
  gamedock-scheduler.service ← биллинг, алерты, бэкапы, cron-задачи игр
  gamedock-agent@N.service   ← агент на этой ноде
```

### Шаг 5. Проверка

```bash
# Службы
systemctl status gamedock-wss
systemctl status gamedock-scheduler
systemctl status gamedock-queue@1

# Сайт
curl -I https://panel.example.com

# phpMyAdmin: без пароля должен быть 401, с паролем — 200
curl -o /dev/null -w '%{http_code}\n' https://panel.example.com/phpmyadmin/
sudo cat /root/.gamedock-phpmyadmin.txt   # логин и пароль для входа

# Диагностика
cd /opt/gamedock/panel
sudo -u gamedock php artisan gamedock doctor
```

### Шаг 6. Первая нода

Панель и нода — разные сущности. Панель управляет, нода запускает игры.

```
1. Панель → Админка → Ноды → «Добавить ноду»
     Название:     node-1
     Рантайм:      docker
     IP ноды:      123.45.67.89      ← сюда игроки будут подключаться
     Регион:       europe
     Статус:       Активна

2. Скопируйте токен ноды (показывается один раз)

3. На САМОЙ ноде:
     wget https://raw.githubusercontent.com/DimasGamesTV22/hostingpl-new-2026-main/main/deploy/agent.sh
     chmod +x agent.sh
     sudo ./agent.sh --panel https://panel.example.com --token <ТОКЕН> --runtime docker

4. Через минуту в панели нода станет «Онлайн»
```

Автоустановщик панели может поставить агента на эту же машину (`--with-agent`) — тогда пропустите шаг 3 и просто вставьте токен в `/etc/gamedock/agent-1.env`.

### Повторная установка

Установщик идемпотентен: можно запустить повторно, если что-то сломалось.

```bash
# Полное переуказание (сбросит настройки из диалога)
sudo ./install.sh --domain panel.example.com --email admin@example.com --yes

# Только обновить код, не трогая настройки
cd /opt/gamedock
git pull
cd panel && sudo -u gamedock composer install --no-dev --optimize-autoloader
cd panel && sudo -u gamedock php artisan migrate --force
cd panel && sudo -u gamedock npm run build
sudo systemctl restart gamedock-wss
```

---

## Способ B. Docker Compose

Подходит для теста и разработки. В продакшене на выделенном сервере вариант A надёжнее.

```bash
git clone https://github.com/DimasGamesTV22/hostingpl-new-2026-main.git
cd gamedock

cp .env.docker.example .env
nano .env          # заполните пароли и GD_AGENT_TOKEN

docker compose up -d
docker compose logs -f panel

# Заходим на http://localhost:8080
```

Генерация паролей:

```bash
echo "DB_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=')"
echo "DB_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=')"
echo "REDIS_PASSWORD=$(openssl rand -hex 32)"
```

Сертификат:

```bash
# Положить в ./certbot/conf/live/<домен>/fullchain.pem и privkey.pem
# Или получить:
docker run --rm \
  -v $(pwd)/certbot/conf:/etc/letsencrypt \
  -v $(pwd)/certbot/www:/var/www/certbot \
  certbot/certbot certonly --webroot -w /var/www/certbot -d panel.example.com
```

Остановить/удалить:

```bash
docker compose stop                  # остановить
docker compose down                  # остановить и убрать контейнеры
docker compose down -v               # плюс удалить данные
```

---

## Способ C. Ручная установка

Если у вас уже есть nginx/Apache и свой MySQL.

### 1. Зависимости

```bash
apt update

# PHP: подставьте версию, доступную в вашей системе.
#   Debian 12 → 8.2, Debian 13 → 8.4, Ubuntu 24.04 → 8.3
#   Debian 11 → 8.2 и Ubuntu 22.04 → 8.1 требуют packages.sury.org
PHP=8.3
apt install -y php${PHP}-fpm php${PHP}-cli php${PHP}-mysql php${PHP}-mbstring \
               php${PHP}-xml php${PHP}-curl php${PHP}-zip php${PHP}-gd php${PHP}-bcmath \
               php${PHP}-intl php${PHP}-opcache php${PHP}-redis

# MariaDB, Redis, Node.js
apt install -y mariadb-server redis-server
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
apt install -y nodejs

# nginx
apt install -y nginx
```

### 2. Пользователь и каталоги

```bash
adduser --system --group --home /home/gamedock --shell /usr/sbin/nologin gamedock

mkdir -p /opt/gamedock /opt/gamedock/game-images
mkdir -p /home/gamedock/{servers,backups,plugins}
chown -R gamedock:gamedock /opt/gamedock /home/gamedock
chmod 750 /home/gamedock/servers /home/gamedock/backups
```

### 3. Код

```bash
git clone https://github.com/DimasGamesTV22/hostingpl-new-2026-main.git /opt/gamedock
cp -a /opt/gamedock/panel/. /opt/gamedock/panel/

cd /opt/gamedock/panel

sudo -u gamedock composer install --no-dev --optimize-autoloader
sudo -u gamedock npm ci
sudo -u gamedock npm run build

sudo -u gamedock cp .env.example .env
sudo -u gamedock php artisan key:generate
```

### 4. База и Redis

```bash
mysql -e "CREATE DATABASE gamedock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'gamedock'@'localhost' IDENTIFIED BY 'ПАРОЛЬ';"
mysql -e "GRANT ALL ON gamedock.* TO 'gamedock'@'localhost';"

# Redis: requirepass в /etc/redis/redis.conf
systemctl restart redis-server
```

Заполните `.env`:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=gamedock
DB_USERNAME=gamedock
DB_PASSWORD=ПАРОЛЬ

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=ПАРОЛЬ

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

### 5. Миграции

```bash
sudo -u gamedock php artisan migrate --force
sudo -u gamedock php artisan storage:link
sudo -u gamedock php artisan config:cache
sudo -u gamedock php artisan route:cache
```

### 6. nginx

```nginx
server {
    listen 443 ssl http2;
    server_name panel.example.com;

    ssl_certificate     /etc/letsencrypt/live/panel.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/panel.example.com/privkey.pem;

    root /opt/gamedock/panel/public;
    index index.php;

    client_max_body_size 128M;

    # SSE-поток консоли — без буферизации!
    location ~ ^/panel/servers/\d+/console/stream$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_buffering off;
        gzip off;
        fastcgi_read_timeout 3600;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_read_timeout 3600;
    }
}
```

### 7. Службы

Возьмите готовые юниты из `deploy/systemd/` (установщик их кладёт) или напишите свои:

```ini
[Unit]
Description=GameDock WSS server
After=network.target

[Service]
User=gamedock
Group=gamedock
WorkingDirectory=/opt/gamedock/panel
ExecStart=/usr/bin/php /opt/gamedock/panel/artisan gamedock:ws-server --host=0.0.0.0 --port=9222
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Не забудьте три службы: `gamedock-wss`, `gamedock-queue@1`, `gamedock-scheduler`.
Без `gamedock-wss` агенты не подключатся, без `scheduler` — не будет биллинга.

---

## Обновление

```bash
cd /opt/gamedock

# Код
sudo -u gamedock git pull

# Зависимости
cd panel
sudo -u gamedock composer install --no-dev --optimize-autoloader
sudo -u gamedock npm ci && sudo -u gamedock npm run build

# База
sudo -u gamedock php artisan migrate --force

# Кэш
sudo -u gamedock php artisan optimize

# Службы
sudo systemctl restart gamedock-wss gamedock-scheduler gamedock-queue@1
```

Агент и бот обновляются отдельно:

```bash
# Агент (единственная зависимость — ws)
cd /opt/gamedock/agent
sudo -u gamedock git pull
sudo -u gamedock npm ci --omit=dev
sudo systemctl restart gamedock-agent@1

# Бот (зависимостей нет)
cd /opt/gamedock/bot
sudo -u gamedock git pull
sudo -u gamedock node src/index.js --check   # самопроверка конфигурации
```

Проверить, что агент видит ноду и доступные рантаймы:

```bash
sudo -u gamedock node bin/gamedock-agent.js doctor
```

> Telegram-бот входит только в развёртку через `docker compose`
> (сервис `bot` в корневом `docker-compose.yml`). Автоустановщик
> `deploy/install.sh` бот не ставит — при нативной установке запустите его
> вручную: `node src/index.js` или через pm2/systemd по вашему вкусу.

---

## Резервное копирование

```bash
# База
mysqldump -u gamedock -p gamedock | gzip > backup-$(date +%F).sql.gz

# Загруженные файлы игр (восстанавливать только при потере ноды)
tar -czf servers-$(date +%F).tar.gz /home/gamedock/servers/

# Конфигурация
tar -czf gamedock-config-$(date +%F).tar.gz \
    /opt/gamedock/panel/.env \
    /opt/gamedock/panel/config/hosting.php \
    /etc/gamedock/
```

Бэкапы игровых серверов панель делает сама (раздел «Настройки → Бэкапы» или в тарифе).

---

## Чек-лист после установки

- [ ] `php artisan gamedock doctor` — все галочки
- [ ] Домен открывается по HTTPS
- [ ] Пароль администратора изменён
- [ ] SMTP настроен (Письма приходят)
- [ ] Нода добавлена и в статусе «Онлайн»
- [ ] Создан тестовый сервер и успешно установлен
- [ ] Реквизиты платёжных систем заполнены, тестовый платёж прошёл
- [ ] Добавлена страница оферты и политики
- [ ] Лендинг заполнен тарифами и играми
- [ ] Включены резервные копии `.env` и конфигурации
