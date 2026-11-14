# One image for the whole application: the web app, the API, the queue worker and the scheduler.
# Build from the repository root:  docker build -t yadeit .

# ── The web app ───────────────────────────────────────────────────────────────
FROM node:24-alpine AS frontend
WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
# Who runs the installation (shown on the terms and privacy pages) and the realtime
# settings are fixed when the web app is built. An empty REVERB key switches realtime off.
ARG VITE_OPERATOR_NAME=""
ARG VITE_OPERATOR_EMAIL=""
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT=""
ARG VITE_REVERB_SCHEME=""
RUN npm run build

# ── PHP dependencies ──────────────────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /src
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ── The application ───────────────────────────────────────────────────────────
FROM dunglas/frankenphp:php8.5 AS app
# The capability lets the server listen on ports 80 and 443 without running as root.
RUN install-php-extensions pdo_pgsql pdo_mysql pcntl intl zip opcache bcmath \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/prod/php.ini /usr/local/etc/php/conf.d/yadeit.ini

WORKDIR /app
COPY --from=vendor /src /app
COPY --from=frontend /src/dist /app/spa
COPY docker/prod/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/prod/entrypoint.sh /usr/local/bin/yadeit-entrypoint

RUN chmod +x /usr/local/bin/yadeit-entrypoint \
    && mkdir -p /data /config /backups /app/storage /app/bootstrap/cache \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 CMD curl -fsS http://localhost:8080/up || exit 1

ENTRYPOINT ["yadeit-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
