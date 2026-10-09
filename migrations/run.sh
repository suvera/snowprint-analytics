#!/bin/sh
# Applies pending Snowprint migrations with Winter Boot's SQL migrator,
# using the database settings in config/application.yml.
#   migrations/run.sh            (build the PHAR first: migrations/build-phar.sh)
# WINTER_SQL_MIGRATIONS_PHAR overrides the PHAR location.
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PHAR_BIN="${WINTER_SQL_MIGRATIONS_PHAR:-$SCRIPT_DIR/winter-migrations-app.phar}"

if [ ! -f "$PHAR_BIN" ]; then
  echo "Error: Winter migrations PHAR not found at $PHAR_BIN" >&2
  echo "Build it with migrations/build-phar.sh or set WINTER_SQL_MIGRATIONS_PHAR." >&2
  exit 1
fi

echo "Running Snowprint database migrations..."
php "$PHAR_BIN" -m sql -c "$SCRIPT_DIR/../config" --sqlPath "$SCRIPT_DIR" "$@"
