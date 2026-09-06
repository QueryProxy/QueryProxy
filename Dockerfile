# ---- Frontend assets ----
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json vite.config.js ./
RUN npm ci --ignore-scripts

COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- PHP application ----
FROM php:8.4-cli-alpine AS app

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        libpq-dev \
        sqlite-dev \
        oniguruma-dev \
        bash \
    && docker-php-ext-install \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        intl \
        zip \
        bcmath \
        pcntl \
        opcache
# SQL Server targets need the Microsoft ODBC driver + sqlsrv PECL extension;
# see README ("SQL Server support") if you proxy MSSQL databases.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh \
    && mkdir -p storage/app/private database \
    && chown -R www-data:www-data storage bootstrap/cache database

ENV PHP_CLI_SERVER_WORKERS=8

EXPOSE 8000

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
