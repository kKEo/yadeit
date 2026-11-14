#!/bin/sh
# Prepares the container and then runs its command. The same image runs the
# web server, the queue worker and the scheduler; only the web server migrates
# the database, so the others wait for it (see compose.prod.yaml).
set -e
cd /app

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: docker run --rm --entrypoint php yadeit artisan key:generate --show" >&2
    exit 1
fi

for dir in storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    mkdir -p "$dir"
done

if [ "$1" = "frankenphp" ]; then
    php artisan migrate --force

    # Signing keys for apps that connect over OAuth, unless they are given in the environment.
    if [ -z "$PASSPORT_PRIVATE_KEY" ] && [ ! -f storage/oauth-private.key ]; then
        php artisan passport:keys
    fi
fi

php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

exec "$@"
