#!/bin/sh
# Ingest benchmark (SP-008): PostgreSQL + the Snowprint image + k6, all in
# Docker on this machine. The app container is limited to APP_CPUS (default 2,
# the PRD target) with its default 4 Swoole workers; PostgreSQL to PG_CPUS.
#   bench/run.sh                 # 10,000 events/s for 30 s
#   RATE=20000 DURATION=60s bench/run.sh
# Writes bench/results/<timestamp>.txt (git-ignored).
set -eu
cd "$(dirname "$0")/.."

RATE="${RATE:-10000}"
DURATION="${DURATION:-30s}"
APP_CPUS="${APP_CPUS:-2}"
PG_CPUS="${PG_CPUS:-2}"
PG=snowprint-bench-pg
APP=snowprint-bench-app
IMAGE=snowprint-analytics:bench
PORT=17670
PG_PORT=15433
K6_IMAGE=grafana/k6:1.0.0
OUT="bench/results/$(date -u +%Y%m%dT%H%M%SZ).txt"
ENV_FILE="$(mktemp)"

cleanup() { docker rm -f "$APP" "$PG" >/dev/null 2>&1 || true; rm -f "$ENV_FILE"; }
trap cleanup EXIT
cleanup
mkdir -p bench/results

docker run -d --name "$PG" --cpus "$PG_CPUS" -p "$PG_PORT:5432" \
    -e POSTGRES_PASSWORD=bench postgres:18-alpine >/dev/null
until docker exec "$PG" pg_isready -q -h 127.0.0.1 -U postgres; do sleep 1; done
docker exec -i "$PG" psql -q -v ON_ERROR_STOP=1 -U postgres <<'SQL'
CREATE ROLE snowprint LOGIN PASSWORD 'bench';
CREATE DATABASE snowprint OWNER snowprint;
SQL
cat > "$ENV_FILE" <<ENV
SNOWPRINT_DB_URL=pgsql:host=host.docker.internal;port=$PG_PORT;dbname=snowprint
SNOWPRINT_DB_USER=snowprint
SNOWPRINT_DB_PASSWORD=bench
ENV
docker build -q -f docker/Dockerfile --build-context winter-boot="${WINTER_BOOT_DIR:-../winter-boot}" \
    -t "$IMAGE" . >/dev/null
docker run -d --name "$APP" --cpus "$APP_CPUS" -p "$PORT:7669" --env-file "$ENV_FILE" \
    --add-host=host.docker.internal:host-gateway "$IMAGE" >/dev/null
curl -fs --retry 30 --retry-connrefused --retry-all-errors --retry-delay 1 "http://localhost:$PORT/api/system/health" >/dev/null
docker exec "$PG" psql -q -U snowprint -d snowprint -c "INSERT INTO sites (domain) VALUES ('bench.example.com')"
sleep 31   # the site directory refreshes every 30 s

{
    echo "Snowprint ingest benchmark — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "commit:   $(git rev-parse --short HEAD)"
    echo "host:     $(lscpu | sed -n 's/^Model name: *//p'), $(nproc) threads, $(free -g | awk '/Mem:/{print $2}') GB RAM"
    echo "app:      $APP_CPUS CPUs, 4 Swoole workers | PostgreSQL 18: $PG_CPUS CPUs, same host"
    echo "load:     $RATE events/s for $DURATION (k6 constant arrival rate), unique user agents: ${UNIQUE_UA:-0}"
    echo
} > "$OUT"

docker run --rm --network host -e BASE_URL="http://localhost:$PORT" -e RATE="$RATE" -e DURATION="$DURATION" \
    -e UNIQUE_UA="${UNIQUE_UA:-0}" \
    -v "$PWD/bench:/bench:ro" "$K6_IMAGE" run --quiet /bench/ingest.js 2>&1 | tee -a "$OUT" || true

sleep 3   # last buffer flush
stored=$(docker exec "$PG" psql -tA -U snowprint -d snowprint -c "select count(*) from events")
echo "stored in PostgreSQL: $stored events" | tee -a "$OUT"
echo "results: $OUT"
