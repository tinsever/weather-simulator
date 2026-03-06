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

    exec gosu www-data sh -lc 'php -d variables_order=EGPCS scripts/bootstrap.php && php -d variables_order=EGPCS -d upload_max_filesize=64M -d post_max_size=64M -d max_file_uploads=20 -d display_errors=0 -d log_errors=1 -S 0.0.0.0:${PORT:-8080} -t . index.php'
fi

exec sh -lc 'php -d variables_order=EGPCS scripts/bootstrap.php && php -d variables_order=EGPCS -d upload_max_filesize=64M -d post_max_size=64M -d max_file_uploads=20 -d display_errors=0 -d log_errors=1 -S 0.0.0.0:${PORT:-8080} -t . index.php'
