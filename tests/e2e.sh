#!/bin/sh
# End-to-end test of the documented install (README "Install"), step by step:
# PostgreSQL in a throwaway container stands in for the user's own server,
# then the Snowprint image is built and run exactly as the README says.
#   tests/e2e.sh
# E2E_PG_IMAGE picks the PostgreSQL version (default postgres:18-alpine).
# E2E_PORT is the host port for the app (default 17669); E2E_PG_PORT the
# host port PostgreSQL listens on (default 15432). E2E_KEEP=1 leaves the
# containers running after a pass, for inspection (removed on the next run).
set -eu
cd "$(dirname "$0")/.."

PG=snowprint-e2e-pg
APP=snowprint-e2e-app
IMAGE=snowprint-analytics:e2e
PG_IMAGE="${E2E_PG_IMAGE:-postgres:18-alpine}"
PORT="${E2E_PORT:-17669}"
PG_PORT="${E2E_PG_PORT:-15432}"
ENV_FILE="$(mktemp)"
GEO_DIR="$(mktemp -d)"
GEO_TEST_DB=https://github.com/maxmind/MaxMind-DB/raw/main/test-data/GeoIP2-City-Test.mmdb
GEO_PORT="${E2E_GEO_PORT:-17690}"
GEO_PID=""

cleanup() {
    docker rm -f "$APP" "$PG" >/dev/null 2>&1 || true
    rm -f "$ENV_FILE"
    rm -rf "$GEO_DIR"
    [ -n "$GEO_PID" ] && kill "$GEO_PID" 2>/dev/null || true
}
fail() {
    echo "FAIL: $1" >&2
    docker logs "$APP" 2>&1 | tail -30 >&2 || true
    exit 1
}
cleanup
if [ "${E2E_KEEP:-0}" = 1 ]; then
    trap 'rm -f "$ENV_FILE"; rm -rf "$GEO_DIR"; [ -n "$GEO_PID" ] && kill "$GEO_PID" 2>/dev/null || true' EXIT
else
    trap cleanup EXIT
fi

# PostgreSQL "on the same machine": published on a host port, reached by the
# app through host.docker.internal, as README step 2 describes.
echo "==> PostgreSQL ($PG_IMAGE) on host port $PG_PORT"
docker run -d --name "$PG" -p "$PG_PORT:5432" -e POSTGRES_PASSWORD=e2e-admin "$PG_IMAGE" >/dev/null
i=0
until docker exec "$PG" pg_isready -q -h 127.0.0.1 -U postgres; do
    i=$((i + 1)); [ "$i" -le 60 ] || fail "PostgreSQL did not start"; sleep 1
done

echo "==> README step 1: create the role and database"
docker exec -i "$PG" psql -q -v ON_ERROR_STOP=1 -U postgres <<'SQL'
CREATE ROLE snowprint LOGIN PASSWORD 'e2e-secret';
CREATE DATABASE snowprint OWNER snowprint;
SQL

echo "==> README step 2: configure (.env)"
cat > "$ENV_FILE" <<ENV
SNOWPRINT_DB_URL=pgsql:host=host.docker.internal;port=$PG_PORT;dbname=snowprint
SNOWPRINT_DB_USER=snowprint
SNOWPRINT_DB_PASSWORD=e2e-secret
SNOWPRINT_TRUST_PROXY=true
SNOWPRINT_GEOIP_DOWNLOAD=dbip-city-lite
SNOWPRINT_GEOIP_DOWNLOAD_BASE_URL=http://host.docker.internal:$GEO_PORT
SNOWPRINT_PUBLIC_URL=https://stats.e2e.test/
ENV
# README "GeoIP" (automatic download): a local mirror serves MaxMind's public test
# database as last month's DB-IP file only, so the "this month is not published
# yet" fallback is exercised too.
mkdir -p "$GEO_DIR"
curl -fsSL "$GEO_TEST_DB" | gzip > "$GEO_DIR/dbip-city-lite-$(date -u -d "$(date -u +%Y-%m-01) -1 month" +%Y-%m).mmdb.gz" \
    || fail "could not download the GeoIP test database"
python3 -m http.server "$GEO_PORT" --bind 0.0.0.0 --directory "$GEO_DIR" >/dev/null 2>&1 &
GEO_PID=$!

echo "==> README step 3: build and run"
docker build -q -f docker/Dockerfile -t "$IMAGE" . >/dev/null
docker run -d --name "$APP" -p "$PORT:7669" --env-file "$ENV_FILE" \
    --add-host=host.docker.internal:host-gateway "$IMAGE" >/dev/null

echo "==> Checks"
curl -fs --retry 30 --retry-connrefused --retry-all-errors --retry-delay 1 \
    "http://localhost:$PORT/api/system/health" | grep -q '"UP"' || fail "/health is not UP"
curl -fsS "http://localhost:$PORT/api/status" | grep -q '"snowprint"' || fail "/api/status"
tables=$(docker exec "$PG" psql -tA -U snowprint -d snowprint \
    -c "select string_agg(tablename, ',' order by tablename) from pg_tables where schemaname = 'public'")
case "$tables" in *sites*) ;; *) fail "migrations did not create tables (got: $tables)" ;; esac
partitions=$(docker exec "$PG" psql -tA -U snowprint -d snowprint \
    -c "select count(*) from pg_inherits where inhparent = 'events'::regclass")
[ "$partitions" -ge 4 ] || fail "expected events default + 3 monthly partitions, got $partitions"

echo "==> Tracking: POST /api/event"
psql_sp() { docker exec "$PG" psql -tA -U snowprint -d snowprint -c "$1"; }
console() { docker exec "$APP" bin/console.sh "$@"; }
# README "Add a site": the operator console inside the container.
console site:add example.com | grep -Eq '"domain": ?"example.com"' || fail "console site:add"
console site:add www.example.com | grep -q 'already exists' || fail "duplicate site accepted"
[ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/api/admin/sites")" = 403 ] \
    || fail "admin API reachable from outside the container"
[ "$(docker exec "$APP" curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:7669/api/admin/sites)" = 403 ] \
    || fail "admin API reachable without the operator token"
UA='Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0 E2EAgent'
track() {   # body [extra curl args...] -> prints "status result"
    body=$1; shift
    curl -s -o /dev/null -D - -X POST -H 'Content-Type: text/plain' -A "$UA" "$@" \
        --data "$body" "http://localhost:$PORT/api/event" \
        | awk 'NR==1{s=$2} tolower($1)=="x-snowprint-result:"{r=$2} END{gsub(/\r/,"",r); print s, r}'
}
[ "$(track '{"d":"example.com","u":"https://example.com/pricing?utm_source=hn&email=x@y.z","r":"https://news.ycombinator.com/","t":"Pricing","w":1440,"h":900,"p":{"plan":"pro"}}')" = "202 accepted" ] \
    || fail "pageview not accepted"
[ "$(track '{"d":"www.example.com","n":"signup","u":"https://www.example.com/signup"}')" = "202 accepted" ] \
    || fail "custom event not accepted"
[ "$(track '{"d":"unknown.test","u":"https://unknown.test/"}')" = "202 unknown-site" ] || fail "unknown site"
[ "$(track '{"d":"example.com","u":"https://example.com/"}' -H 'DNT: 1')" = "202 dnt" ] || fail "DNT not respected"
[ "$(track 'not json')" = "400 " ] || fail "bad payload not rejected"
[ "$(track '{"d":"example.com","u":"https://example.com/from-london"}' -H 'X-Forwarded-For: 81.2.69.142, 10.0.0.1')" = "202 accepted" ] \
    || fail "proxied event not accepted"
# Cloudflare Tunnel: CF-Connecting-IP beats a conflicting X-Forwarded-For.
[ "$(track '{"d":"example.com","u":"https://example.com/via-cloudflare"}' -H 'CF-Connecting-IP: 81.2.69.142' -H 'X-Forwarded-For: 10.0.0.1')" = "202 accepted" ] \
    || fail "Cloudflare event not accepted"
[ "$(track '{"d":"example.com","u":"https://example.com/"}' -A 'curl/8.9.1')" = "202 bot" ] || fail "bot not filtered"
curl -s -D - -o /dev/null -X OPTIONS "http://localhost:$PORT/api/event" \
    | grep -qi '^access-control-allow-origin: \*' || fail "CORS preflight"

i=0
until [ "$(psql_sp "select count(*) from events")" = 4 ]; do
    i=$((i + 1)); [ "$i" -le 20 ] || fail "events not flushed to PostgreSQL"; sleep 0.5
done
row=$(psql_sp "select site_id, name, path, hostname, referrer_host, referrer_source, utm_source, screen_w, props->>'plan',
                      length(visitor_hash), browser, os, device
               from events where name = 'pageview' and path = '/pricing'")
[ "$row" = "1|pageview|/pricing|example.com|news.ycombinator.com|hn|hn|1440|pro|16|Firefox|GNU/Linux|desktop" ] \
    || fail "stored pageview: $row"
[ "$(psql_sp "select country || '|' || region || '|' || city from events where path = '/from-london'")" = "GB|England|London" ] \
    || fail "GeoIP location not stored"
[ "$(psql_sp "select country || '|' || city from events where path = '/via-cloudflare'")" = "GB|London" ] \
    || fail "CF-Connecting-IP must take priority over X-Forwarded-For"
[ "$(psql_sp "select count(distinct visitor_hash) from events where path not in ('/from-london', '/via-cloudflare')")" = 1 ] \
    || fail "same visitor hashed differently"
[ "$(track '{"d":"example.com","u":"https://example.com/"}' -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0.0.0 Safari/537.36 OtherBrowser')" = "202 accepted" ] \
    || fail "second visitor not accepted"
i=0
until [ "$(psql_sp "select count(distinct visitor_hash) from events")" = 3 ]; do
    i=$((i + 1)); [ "$i" -le 20 ] || fail "a different user agent must be a different visitor"; sleep 0.5
done
leaks=$(psql_sp "select count(*) from events where row_to_json(events)::text ~* 'E2EAgent|OtherBrowser|81\\.2\\.69|172\\.17\\.|x@y\\.z|email'")
[ "$leaks" = 0 ] || fail "user agent, IP or query data stored in events"
[ "$(psql_sp "select count(*) from salts")" = 1 ] || fail "expected one daily salt"

echo "==> MCP"
key=$(console key:create claude example.com | sed -n 's/.*"key": \{0,1\}"\(sp_[0-9a-f]*\)".*/\1/p')
[ -n "$key" ] || fail "console key:create did not print a key"
mcp() {   # key json -> prints "status body"
    curl -s -w '\n%{http_code}' -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
        -H 'Accept: application/json, text/event-stream' --data "$2" "http://localhost:$PORT/api/mcp" \
        | awk '{ lines[NR] = $0 } END { printf "%s ", lines[NR]; for (i = 1; i < NR; i++) printf "%s", lines[i]; print "" }'
}
init=$(mcp "$key" '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"e2e","version":"1"}}}')
case "$init" in "200 "*'"protocolVersion":"2025-06-18"'*'"tools"'*) ;; *) fail "MCP initialize: $init" ;; esac
[ "$(mcp "$key" '{"jsonrpc":"2.0","method":"notifications/initialized"}')" = "202 " ] || fail "MCP notification"
tools=$(mcp "$key" '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')
for t in list_sites get_overview get_timeseries get_breakdown get_realtime get_goals find_anomalies; do
    case "$tools" in *"\"name\":\"$t\""*) ;; *) fail "MCP tool $t missing" ;; esac
done
call=$(mcp "$key" '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_overview","arguments":{"site":"example.com","period":"today"}}}')
case "$call" in "200 "*'"structuredContent"'*'"current":{"visitors":'[1-9]*'"isError":false'*) ;; *) fail "MCP get_overview: $call" ;; esac
bad=$(mcp "$key" '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"get_breakdown","arguments":{"site":"example.com","dimension":"shoe_size"}}}')
case "$bad" in *'unknown dimension'*'"isError":true'*) ;; *) fail "MCP bad argument should be a tool error: $bad" ;; esac
console site:add other.test >/dev/null
other=$(console key:create other other.test | sed -n 's/.*"key": \{0,1\}"\(sp_[0-9a-f]*\)".*/\1/p')
denied=$(mcp "$other" '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"get_overview","arguments":{"site":"example.com"}}}')
case "$denied" in *'not found or not accessible'*'"isError":true'*) ;; *) fail "MCP key reached another site: $denied" ;; esac
case "$(mcp nope '{"jsonrpc":"2.0","id":6,"method":"ping"}')" in "401 "*) ;; *) fail "MCP without a valid key" ;; esac
revoked=$(console key:create revoked example.com | sed -n 's/.*"key": \{0,1\}"\(sp_[0-9a-f]*\)".*/\1/p')
console key:revoke "$(console key:list | grep -o '"id":[0-9]*,"name":"revoked"' | grep -o '[0-9]*')" >/dev/null
case "$(mcp "$revoked" '{"jsonrpc":"2.0","id":7,"method":"ping"}')" in "401 "*) ;; *) fail "MCP accepted a revoked key" ;; esac
[ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/api/mcp")" = 405 ] || fail "GET /api/mcp must be 405"

echo "==> Dashboard API"
JAR="$(mktemp)"
ui() {   # METHOD PATH [JSON] -> prints "status body", keeps the session cookie in $JAR
    if [ -n "${3:-}" ]; then
        curl -s -b "$JAR" -c "$JAR" -w '\n%{http_code}' -X "$1" -H 'X-Snowprint: 1' \
            -H 'Content-Type: application/json' --data "$3" "http://localhost:$PORT$2"
    else
        curl -s -b "$JAR" -c "$JAR" -w '\n%{http_code}' -X "$1" -H 'X-Snowprint: 1' "http://localhost:$PORT$2"
    fi | awk '{ lines[NR] = $0 } END { printf "%s ", lines[NR]; for (i = 1; i < NR; i++) printf "%s", lines[i]; print "" }'
}
case "$(ui GET /api/ui/session)" in "200 "*'"setup_required":true'*) ;; *) fail "fresh install must ask for setup" ;; esac
case "$(ui GET /api/ui/session)" in *'"text":"IP Geolocation by DB-IP"'*) ;; *) fail "DB-IP attribution missing" ;; esac
case "$(ui GET /api/ui/session)" in *'"public_url":"https:\/\/stats.e2e.test"'*|*'"public_url":"https://stats.e2e.test"'*) ;;
    *) fail "SNOWPRINT_PUBLIC_URL not exposed to the dashboard" ;; esac
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data '{}' "http://localhost:$PORT/api/ui/setup")" = 403 ] \
    || fail "setup without the X-Snowprint header must be refused"
case "$(ui POST /api/ui/setup '{"email":"Admin@Example.com","name":"Admin","password":"correct horse battery"}')" in
    "200 "*'"is_admin":true'*) ;; *) fail "first-run setup" ;; esac
case "$(ui POST /api/ui/setup '{"email":"x@example.com","name":"X","password":"another long one"}')" in
    "400 "*'already complete'*) ;; *) fail "setup must only run once" ;; esac
case "$(ui GET /api/ui/sites)" in "200 "*'"domain":"example.com"'*'"has_data":true'*) ;; *) fail "signed-in user cannot list sites" ;; esac
case "$(ui POST /api/ui/sites '{"domain":"tz.test","timezone":"Asia/Calcutta"}')" in
    "200 "*'"timezone":"Asia\/Kolkata"'*|"200 "*'"timezone":"Asia/Kolkata"'*) ;;
    *) fail "legacy browser timezone (Asia/Calcutta) must be accepted as Asia/Kolkata" ;; esac
case "$(ui GET '/api/ui/stats/overview?site=example.com&period=today')" in
    "200 "*'"current":{"visitors":'[1-9]*) ;; *) fail "dashboard overview" ;; esac
case "$(ui GET '/api/ui/stats/breakdown?site=example.com&period=today&dimension=page&filters=%7B%22country%22%3A%22GB%22%7D')" in
    "200 "*'from-london'*) ;; *) fail "filtered breakdown" ;; esac
ui POST /api/ui/logout >/dev/null
case "$(ui GET /api/ui/sites)" in "401 "*) ;; *) fail "logout did not end the session" ;; esac
case "$(ui POST /api/ui/login '{"email":"admin@example.com","password":"wrong password!"}')" in "401 "*) ;; *) fail "wrong password accepted" ;; esac
case "$(ui POST /api/ui/login '{"email":"admin@example.com","password":"correct horse battery"}')" in "200 "*) ;; *) fail "login" ;; esac
case "$(ui GET /api/ui/session)" in "200 "*'"email":"admin@example.com"'*) ;; *) fail "session after login" ;; esac

echo "==> Sites, users and invites"
case "$(ui PATCH /api/ui/sites/tz.test '{"retention_days":30}')" in "200 "*'"retention_days":30'*) ;; *) fail "site settings" ;; esac
case "$(ui PATCH /api/ui/sites/tz.test '{"retention_days":-1}')" in "400 "*) ;; *) fail "negative retention accepted" ;; esac
console site:set example.com retention 400 | grep -q '"retention_days": \{0,1\}400' || fail "console site:set"
invite=$(ui POST /api/ui/invites '{"email":"viewer@example.com","sites":{"example.com":"viewer"}}')
token=$(echo "$invite" | sed -n 's/.*"token":"\([0-9a-f]*\)".*/\1/p')
[ -n "$token" ] || fail "invite: $invite"
ADMIN_JAR="$JAR"; JAR="$(mktemp)"
case "$(ui POST /api/ui/invite "{\"token\":\"$token\"}")" in "200 "*'viewer@example.com'*) ;; *) fail "invite lookup" ;; esac
case "$(ui POST /api/ui/invite/accept "{\"token\":\"$token\",\"name\":\"Vic\",\"password\":\"viewer password 1\"}")" in
    "200 "*'"is_admin":false'*) ;; *) fail "accepting the invite" ;; esac
sites=$(ui GET /api/ui/sites)
case "$sites" in "200 "*'"domain":"example.com"'*'"can_manage":false'*) ;; *) fail "viewer cannot see their site: $sites" ;; esac
case "$sites" in *tz.test*) fail "viewer sees a site they were not given" ;; esac
case "$(ui PATCH /api/ui/sites/example.com '{"retention_days":1}')" in "403 "*) ;; *) fail "viewer changed site settings" ;; esac
case "$(ui GET /api/ui/users)" in "403 "*) ;; *) fail "viewer listed users" ;; esac
rm -f "$JAR"; JAR="$(mktemp)"
case "$(ui POST /api/ui/invite/accept "{\"token\":\"$token\",\"name\":\"X\",\"password\":\"viewer password 1\"}")" in
    "400 "*) ;; *) fail "an invite link worked twice" ;; esac
rm -f "$JAR"; JAR="$ADMIN_JAR"
case "$(ui GET /api/ui/users)" in "200 "*'viewer@example.com'*) ;; *) fail "admin cannot list users" ;; esac
case "$(ui DELETE /api/ui/sites/tz.test '{"confirm":"wrong"}')" in "400 "*) ;; *) fail "site deleted without confirmation" ;; esac
case "$(ui DELETE /api/ui/sites/tz.test '{"confirm":"tz.test"}')" in "200 "*) ;; *) fail "site delete" ;; esac
case "$(ui GET /api/ui/sites)" in *tz.test*) fail "deleted site still listed" ;; esac

echo "==> Share links"
shared() {   # TOKEN PATH -> status of a report fetched with a share token and the visitor's jar
    curl -s -o /dev/null -b "$VISITOR" -c "$VISITOR" -w '%{http_code}' -H 'X-Snowprint: 1' \
        -H "X-Snowprint-Share: $1" "http://localhost:$PORT$2"
}
share_token() { sed -n 's/.*"token":"\([0-9a-f]*\)".*/\1/p'; }
open_share=$(ui POST /api/ui/sites/example.com/shares '{"label":"Public"}' | share_token)
locked_share=$(ui POST /api/ui/sites/example.com/shares '{"label":"Team","password":"share password 1"}' | share_token)
[ -n "$open_share" ] && [ -n "$locked_share" ] || fail "creating share links"
case "$(ui GET /api/ui/sites/example.com/shares)" in "200 "*'"label":"Public"'*'"has_password":true'*) ;; *) fail "listing share links" ;; esac
case "$(ui GET /api/ui/sites/example.com/shares)" in *"$open_share"*) fail "share link list exposes a token" ;; esac
VISITOR="$(mktemp)"
[ "$(shared "$open_share" '/api/ui/stats/overview?period=today')" = 200 ] || fail "open share link cannot read reports"
[ "$(shared "$open_share" '/api/ui/stats/breakdown?period=today&dimension=page')" = 200 ] || fail "share link breakdown"
[ "$(shared "$open_share" '/api/ui/stats/realtime')" = 200 ] || fail "share link realtime"
[ "$(shared "$open_share" '/api/ui/sites')" = 401 ] || fail "a share link opened the site list"
[ "$(shared "$open_share" '/api/ui/sites/example.com/shares')" = 401 ] || fail "a share link listed share links"
[ "$(shared "0123456789abcdef0123456789abcdef" '/api/ui/stats/overview?period=today')" = 404 ] || fail "unknown share token accepted"
[ "$(shared "$locked_share" '/api/ui/stats/overview?period=today')" = 401 ] || fail "password-protected link read without password"
share_api() { curl -s -b "$VISITOR" -c "$VISITOR" -w '\n%{http_code}' -X POST -H 'X-Snowprint: 1' \
    -H 'Content-Type: application/json' --data "$2" "http://localhost:$PORT/api/share/$1" | tr '\n' ' '; }
case "$(share_api info "{\"token\":\"$open_share\"}")" in *'"domain":"example.com"'*'"password_required":false'*200*) ;; *) fail "open share info" ;; esac
case "$(share_api info "{\"token\":\"$locked_share\"}")" in *'"site":null'*'"password_required":true'*200*) ;; *) fail "locked share info" ;; esac
case "$(share_api unlock "{\"token\":\"$locked_share\",\"password\":\"nope nope nope\"}")" in *401*) ;; *) fail "wrong share password accepted" ;; esac
case "$(share_api unlock "{\"token\":\"$locked_share\",\"password\":\"share password 1\"}")" in *200*) ;; *) fail "unlocking a share link" ;; esac
[ "$(shared "$locked_share" '/api/ui/stats/overview?period=today')" = 200 ] || fail "unlocked share link cannot read reports"
console share:create example.com demo | grep -Eq '"path": ?"\\?/ui\\?/#\\?/share\\?/[0-9a-f]{32}"' || fail "console share:create"
console share:list example.com | grep -Eq '"label": ?"demo"' || fail "console share:list"
open_id=$(ui GET /api/ui/sites/example.com/shares | sed -n 's/.*"id":\([0-9]*\),"label":"Public".*/\1/p')
case "$(ui DELETE "/api/ui/sites/example.com/shares/$open_id")" in "200 "*) ;; *) fail "deleting a share link" ;; esac
[ "$(shared "$open_share" '/api/ui/stats/overview?period=today')" = 404 ] || fail "deleted share link still works"
rm -f "$VISITOR" "$JAR"

echo "==> Dashboard app"
case "$(curl -s "http://localhost:$PORT/ui/")" in *'<div id="root">'*) ;; *) fail "/ui/ does not serve the dashboard" ;; esac
asset=$(curl -s "http://localhost:$PORT/ui/" | sed -n 's#.*src="\(/ui/assets/[^"]*\.js\)".*#\1#p' | head -1)
[ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT$asset")" = 200 ] || fail "dashboard assets missing ($asset)"
case "$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "http://localhost:$PORT/ui/favicon.svg")" in
    "200 image/svg+xml"*) ;; *) fail "favicon not served as SVG" ;; esac

echo "==> Tracker script"
curl -fs "http://localhost:$PORT/snow.js" | cmp -s - public/snow.js || fail "/snow.js does not serve public/snow.js"
js_headers=$(curl -s -D - -o /dev/null "http://localhost:$PORT/snow.js")
echo "$js_headers" | grep -qi '^content-type: .*javascript' || fail "/snow.js content type"
modified=$(echo "$js_headers" | sed -n 's/^[Ll]ast-[Mm]odified: //p' | tr -d '\r')
[ "$(curl -s -o /dev/null -w '%{http_code}' -H "If-Modified-Since: $modified" "http://localhost:$PORT/snow.js")" = 304 ] \
    || fail "/snow.js revalidation"

echo "==> Sessions"
# Visitor A: t0, +25m, +50m, +70m (one session crossing the sessionizer's one-hour
# window), then +110m (40 min gap: new session). Visitor B: t0+5m (own session).
psql_sp "INSERT INTO events (site_id, ts, visitor_hash, name, path) VALUES
    (1, now() - interval '1 day',                          '\\xaa', 'pageview', '/a1'),
    (1, now() - interval '1 day' + interval '25 minutes',  '\\xaa', 'pageview', '/a2'),
    (1, now() - interval '1 day' + interval '50 minutes',  '\\xaa', 'pageview', '/a3'),
    (1, now() - interval '1 day' + interval '70 minutes',  '\\xaa', 'pageview', '/a4'),
    (1, now() - interval '1 day' + interval '110 minutes', '\\xaa', 'pageview', '/a5'),
    (1, now() - interval '1 day' + interval '5 minutes',   '\\xbb', 'pageview', '/b1')" >/dev/null
psql_sp "DELETE FROM job_watermarks WHERE job = 'sessionizer'" >/dev/null
i=0
until [ "$(psql_sp "select count(*) from events where path in ('/a1','/a2','/a3','/a4','/a5','/b1') and session_id is null")" = 0 ]; do
    psql_sp "select * from snowprint_sessionize()" >/dev/null
    i=$((i + 1)); [ "$i" -le 30 ] || fail "sessionizer did not catch up"
done
sessions=$(psql_sp "select string_agg(path, ',' order by ts) from events where path like '/_%' and length(path) = 3
                    group by session_id order by min(ts)" | tr '\n' ' ')
[ "$sessions" = "/a1,/a2,/a3,/a4 /b1 /a5 " ] || fail "unexpected sessions: $sessions"
[ "$(psql_sp "select count(*) from events where path = '/a1' and session_id = id")" = 1 ] \
    || fail "session id must be the first event's id"

echo "==> Rollups and retention"
# A pageview three days ago; the worker rolls up finished days, retention keeps
# 90 days. Reset the watermark: the scheduled job may have started after today's events.
psql_sp "INSERT INTO events (site_id, ts, visitor_hash, session_id, name, path)
         VALUES (1, now() - interval '3 days', '\\xcc', 1, 'pageview', '/rolled')" >/dev/null
psql_sp "DELETE FROM job_watermarks WHERE job LIKE 'rollup:%'" >/dev/null
console rollup:run | grep -q '"days_rolled_up":[1-9]' || fail "rollup:run rolled up no days"
[ "$(psql_sp "select visitors from rollup_daily where site_id = 1 and dimension = 'page' and value = '/rolled'")" = 1 ] \
    || fail "the three-day-old pageview is not rolled up"
console retention:run | grep -q '"events_deleted":0' || fail "retention:run deleted events inside the retention period"

i=0
until [ "$(docker inspect -f '{{.State.Health.Status}}' "$APP")" = healthy ]; do
    i=$((i + 1)); [ "$i" -le 60 ] || fail "container never became healthy"; sleep 1
done

echo "==> URL layout: only /ui/, /snow.js and /api/ at the root"
for old in /health /info /prometheus /mcp; do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT$old")" = 404 ] || fail "$old must not exist (use /api/...)"
done

echo "==> Metrics"
metrics=$(curl -s "http://localhost:$PORT/api/system/prometheus")
metric() { echo "$metrics" | awk -v m="$1" 'index($0, m) == 1 { print $2; exit }'; }
accepted=$(metric 'snowprint_ingest_requests_total{result="accepted"}')
written=$(metric 'snowprint_events_written_total')
[ "${accepted:-0}" -ge 4 ] || fail "accepted requests not counted across workers (got ${accepted:-none})"
[ "${written:-0}" -ge 4 ] || fail "written events not counted (got ${written:-none})"
echo "$metrics" | grep -q '^snowprint_ingest_requests_total{result="bot"}' || fail "bot requests not counted"
echo "$metrics" | grep -q '^snowprint_mcp_tool_calls_total{tool="get_overview",result="ok"}' || fail "MCP calls not counted"
echo "$metrics" | grep -q '^snowprint_client_ip_source_total{source="cf-connecting-ip",scope="public"}' \
    || fail "client IP sources not counted"

echo "==> Database outage"
docker stop "$PG" >/dev/null
health=$(curl -s --max-time 10 -w ' %{http_code}' "http://localhost:$PORT/api/system/health")
case "$health" in *'"status":"DOWN"'*' 503') ;; *) fail "/health should be DOWN (503) without PostgreSQL: $health" ;; esac
docker start "$PG" >/dev/null
until docker exec "$PG" pg_isready -q -h 127.0.0.1 -U postgres; do sleep 1; done
i=0
until curl -s "http://localhost:$PORT/api/system/health" | grep -q '"status":"UP"'; do
    i=$((i + 1)); [ "$i" -le 30 ] || fail "/health did not recover after PostgreSQL came back"; sleep 1
done

echo "PASS: migrated ($partitions events partitions), tracking stored and anonymised, sessions assigned, rollups and retention, MCP tools and auth, dashboard app, API and sign-in, site settings, invites and roles, share links, Prometheus metrics, everything under /api/, health UP, DOWN and recovered with PostgreSQL, /api/status OK, container healthy"
