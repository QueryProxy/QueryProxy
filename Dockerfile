# ---- Frontend assets ----
FROM node:26-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json vite.config.js ./
RUN npm ci --ignore-scripts

COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- PHP application ----
FROM php:8.5-fpm-alpine AS app

# Pick up Alpine security fixes the base image has not been rebuilt with yet.
RUN apk upgrade --no-cache \
    && apk add --no-cache \
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
        pcntl
# opcache is not in that list: PHP 8.5 links it statically and enables it by
# default, so docker-php-ext-install has no module to install. The tuning in
# docker/php.ini still applies.

# SQL Server targets need the Microsoft ODBC driver and the pdo_sqlsrv PECL
# extension. They are not in the default image; the `:mssql` variant is built
# with --build-arg INSTALL_SQLSRV=1. The ODBC driver is Microsoft software under
# its own licence (https://aka.ms/odbc18eula); building the variant means
# accepting it. The driver package is pinned and checked against a SHA-256, for
# both architectures Microsoft ships for Alpine.
ARG INSTALL_SQLSRV=0
ARG MSODBCSQL_VERSION=18.7.1.1-1
ARG MSODBCSQL_URL=https://download.microsoft.com/download/ade174b7-8cea-4543-91a6-c33ae320c2f0
ARG MSODBCSQL_SHA256_AMD64=9ff10ec65dcbbe9ad57ed7fca648d8e7d09d4a25fc3c1095046f36a29f86b84a
ARG MSODBCSQL_SHA256_ARM64=d42f31124b2eb88633ed1ef5159add84541149d2ecebacdfa6e638719e26a48c
ARG PDO_SQLSRV_VERSION=5.13.3
RUN if [ "${INSTALL_SQLSRV}" = "1" ]; then \
        set -eux; \
        case "$(uname -m)" in \
            x86_64) arch=amd64; sha256="${MSODBCSQL_SHA256_AMD64}" ;; \
            aarch64) arch=arm64; sha256="${MSODBCSQL_SHA256_ARM64}" ;; \
            *) echo "No Microsoft ODBC driver for $(uname -m)" >&2; exit 1 ;; \
        esac; \
        apk add --no-cache unixodbc libstdc++; \
        apk add --no-cache --virtual .sqlsrv-build ${PHPIZE_DEPS} unixodbc-dev; \
        curl -fsSL -o /tmp/msodbcsql18.apk "${MSODBCSQL_URL}/msodbcsql18_${MSODBCSQL_VERSION}_${arch}.apk"; \
        echo "${sha256}  /tmp/msodbcsql18.apk" | sha256sum -c -; \
        apk add --no-cache --allow-untrusted /tmp/msodbcsql18.apk; \
        pecl install "pdo_sqlsrv-${PDO_SQLSRV_VERSION}"; \
        docker-php-ext-enable pdo_sqlsrv; \
        rm -rf /tmp/msodbcsql18.apk /tmp/pear; \
        apk del --no-cache .sqlsrv-build; \
    fi

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
