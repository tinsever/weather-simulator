#!/bin/sh
set -eu

DB_PATH_VALUE="${DB_PATH:-/app/database/clauswetter.db}"
DB_DIR="$(dirname "$DB_PATH_VALUE")"

mkdir -p "$DB_DIR"

if [ "$(id -u)" = "0" ]; then
    # Mounted volumes in orchestrators (e.g. Coolify) can override image-time ownership.
    chown -R www-data:www-data "$DB_DIR" || true

    if ! su -s /bin/sh -c "test -w '$DB_DIR'" www-data; then
        chmod 0777 "$DB_DIR" || true
    fi

    gosu www-data php scripts/bootstrap.php
    exec /usr/local/sbin/php-fpm -F
fi

php scripts/bootstrap.php
exec /usr/local/sbin/php-fpm -F
