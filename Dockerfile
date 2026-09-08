# ---- Frontend assets ----
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json vite.config.js ./
RUN npm ci --ignore-scripts

COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- PHP application ----
FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        libpq-dev \
        sqlite-dev \
        oniguruma-dev \
        bash \
        curl \
        nginx \
        supervisor \
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

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-queryproxy.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/queryproxy.ini

RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh \
    && mkdir -p storage/app/private database \
    && ln -sfn ../storage/app/public public/storage \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && chown -R www-data:www-data storage bootstrap/cache database /var/lib/nginx /var/log/nginx

LABEL org.opencontainers.image.title="QueryProxy" \
      org.opencontainers.image.description="Approval workflow, masking and audit trail in front of production databases." \
      org.opencontainers.image.url="https://queryproxy.com" \
      org.opencontainers.image.source="https://github.com/QueryProxy/QueryProxy" \
      org.opencontainers.image.licenses="AGPL-3.0-only"

# Safe-by-default even for a bare `docker run` without compose overrides.
# The single container runs the whole stack: web tier, queue worker (approved
# queries execute there) and scheduler (result retention pruning). Set the
# RUN_ flags to false when those run as separate containers instead.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_LEVEL=info \
    QUERYPROXY_RUN_WORKER=true \
    QUERYPROXY_RUN_SCHEDULER=true \
    QUERYPROXY_WORKER_PROCESSES=1

# Database, generated APP_KEY and result files all live here, so persistence is
# a single volume: -v queryproxy-data:/var/www/html/storage/app
VOLUME ["/var/www/html/storage/app"]

# Everything (supervisord, nginx on an unprivileged port, php-fpm, artisan
# workers) runs as www-data; no process in the container is root.
USER www-data

EXPOSE 7432

HEALTHCHECK --interval=30s --timeout=3s --start-period=60s --retries=5 \
    CMD curl -fsS http://localhost:7432/up || exit 1

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
