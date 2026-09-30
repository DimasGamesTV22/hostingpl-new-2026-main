# GameDock — панель
FROM php:8.3-fpm-bookworm

# Зависимости PHP и системные пакеты
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libonig-dev libxml2-dev libpq-dev curl ca-certificates gnupg \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_mbstring mbstring bcmath intl zip opcache exif pcntl \
    && apt-get purge -y --auto-remove && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js для сборки ассетов
RUN apt-get install -y --no-install-recommends nodejs npm \
    && rm -rf /var/lib/apt/lists/*

# Пользователь
RUN groupadd -g 1000 gamedock \
    && useradd -u 1000 -g gamedock -m -s /bin/bash gamedock

WORKDIR /var/www/html

# Зависимости сначала (слой кэшируется)
COPY panel/composer.json panel/composer.lock* /var/www/html/
COPY panel/package.json panel/package-lock* /var/www/html/

RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction || true
RUN npm ci --no-audit --no-fund || true

# Код приложения
COPY panel/ /var/www/html/

RUN composer dump-autoload --optimize --no-dev \
    && npm run build \
    && chown -R gamedock:gamedock /var/www/html \
    && chmod -R ug+rwx storage bootstrap/cache

# Настройки PHP
RUN { \
        echo 'memory_limit = 512M'; \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'max_execution_time = 300'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 192'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/99-gamedock.ini

# FPM-пул
RUN { \
        echo '[gamedock]'; \
        echo 'listen = 0.0.0.0:9000'; \
        echo 'user = gamedock'; \
        echo 'group = gamedock'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 20'; \
        echo 'pm.start_servers = 4'; \
        echo 'pm.min_spare_servers = 2'; \
        echo 'pm.max_spare_servers = 6'; \
        echo 'pm.max_requests = 500'; \
    } > /usr/local/etc/php-fpm.d/zz-gamedock.conf

# Точка входа: ждём БД, мигрируем при первом запуске
COPY panel/docker/entrypoint.sh /usr/local/bin/gamedock-entrypoint
RUN chmod +x /usr/local/bin/gamedock-entrypoint

EXPOSE 9000

ENTRYPOINT ["gamedock-entrypoint"]
CMD ["php-fpm"]
