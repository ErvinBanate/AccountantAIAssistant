#!/bin/sh
set -eu

key_file=/var/www/ledgerly-secrets/app-key

if [ "${1:-}" = "init" ]; then
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
    mkdir -p /var/www/ledgerly-secrets
    if [ -z "${APP_KEY:-}" ] && [ ! -s "$key_file" ]; then
        umask 077
        php artisan key:generate --show --no-ansi > "$key_file"
    fi
    chown -R www-data:www-data storage /var/www/ledgerly-secrets
    chmod 750 /var/www/ledgerly-secrets
    if [ -f "$key_file" ]; then
        chmod 640 "$key_file"
    fi
fi

if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$key_file" ]; then
        echo "Application key is missing. Run the Compose init service first." >&2
        exit 1
    fi
    APP_KEY=$(cat "$key_file")
    export APP_KEY
fi

if [ "${1:-}" = "init" ]; then
    php artisan migrate --force --no-interaction
    exit 0
fi

exec docker-php-entrypoint "$@"
