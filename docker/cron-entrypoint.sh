#!/bin/sh
set -eu

DB_PATH_VALUE="${DB_PATH:-/app/database/clauswetter.db}"
DB_DIR="$(dirname "$DB_PATH_VALUE")"

mkdir -p "$DB_DIR"

php scripts/bootstrap.php
exec "$@"
