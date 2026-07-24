# ── Stage 1: PHP dependency builder ─────────────────────────────────────────
# Runs composer install in isolation so the runtime image never needs composer,
# git, or any other build-only tool.
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-scripts \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# ── Stage 2: Runtime ─────────────────────────────────────────────────────────
FROM php:8.2-fpm-alpine

# Runtime system dependencies only (no git, no curl build tools, no zip binary)
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    libzip-dev \
    icu-dev \
    mysql-client \
    autoconf \
    g++ \
    make \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make \
    && mkdir -p /run/nginx

# Nginx config (extracted from heredoc — easier to lint and diff)
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Supervisord manages both nginx and php-fpm so the container restarts either
# process automatically if one crashes, rather than leaving a 502 loop.
COPY docker/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

# Vendor from build stage (no composer or internet access needed at runtime)
COPY --from=vendor /app/vendor ./vendor

# Application source (.dockerignore excludes vendor, node_modules, tests, etc.)
COPY . .

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

# Lightweight liveness probe — check-updates never caches and touches the DB
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -sf http://localhost/api/v1/dashboard/check-updates > /dev/null || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
# Workers override CMD via docker-compose `command:` — this default is web only.
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
