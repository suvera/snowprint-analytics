#!/bin/sh
# Integration tests (tests/integration/*) against a throwaway PostgreSQL with
# all migrations applied. Needs Docker.
#   tests/integration.sh
set -eu
cd "$(dirname "$0")/.."
PG=snowprint-it-pg
PG_IMAGE="${IT_PG_IMAGE:-postgres:18-alpine}"
PORT="${IT_PG_PORT:-15434}"
cleanup() { docker rm -f "$PG" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker run -d --name "$PG" -p "$PORT:5432" -e POSTGRES_USER=snowprint -e POSTGRES_PASSWORD=it \
    -e POSTGRES_DB=snowprint "$PG_IMAGE" >/dev/null
until docker exec "$PG" pg_isready -q -h 127.0.0.1 -U snowprint; do sleep 1; done
for f in migrations/analytics/*.sql; do
    docker exec -i "$PG" psql -q -v ON_ERROR_STOP=1 -U snowprint -d snowprint < "$f" >/dev/null
done
SNOWPRINT_TEST_DB_URL="pgsql:host=127.0.0.1;port=$PORT;dbname=snowprint" \
SNOWPRINT_TEST_DB_PASSWORD=it vendor/bin/phpunit tests/integration "$@"
