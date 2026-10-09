#!/bin/sh
# Snowprint container entrypoint.
#   (no argument)  migrate, then run every role in one process
#   web|ingest|worker|all  migrate, then run that role
#   migrate        apply migrations and exit (Helm's migration Job)
# SNOWPRINT_SKIP_MIGRATIONS=true starts without migrating (pods in a Helm
# release, where the migration Job has already run).
# SNOWPRINT_GEOIP_DOWNLOAD=dbip-city-lite|dbip-country-lite downloads DB-IP's
# free GeoIP database at start-up (unless SNOWPRINT_GEOIP_DB is set).
set -e
cd /app

migrate() {
  WINTER_SQL_MIGRATIONS_PHAR=/app/winter-migrations-app.phar sh migrations/run.sh
}

# DB-IP Lite (CC BY 4.0) is published monthly as <name>-YYYY-MM.mmdb.gz. Try this
# month, then last month. A failure only means no locations; never block start-up.
geoip_download() {
  name="$SNOWPRINT_GEOIP_DOWNLOAD"
  case "$name" in
    dbip-city-lite|dbip-country-lite) ;;
    *) echo "[geoip] unknown SNOWPRINT_GEOIP_DOWNLOAD=$name (use dbip-city-lite or dbip-country-lite)" >&2; return 0 ;;
  esac
  base="${SNOWPRINT_GEOIP_DOWNLOAD_BASE_URL:-https://download.db-ip.com/free}"
  dir=/app/var/geoip
  file="$dir/$name.mmdb"
  mkdir -p "$dir"
  if [ ! -s "$file" ]; then
    year=$(date -u +%Y); month=$(date -u +%m); month=${month#0}
    prev_year=$year; prev_month=$((month - 1))
    [ "$prev_month" -eq 0 ] && prev_month=12 && prev_year=$((year - 1))
    for ym in "$(printf '%d-%02d' "$year" "$month")" "$(printf '%d-%02d' "$prev_year" "$prev_month")"; do
      echo "[geoip] downloading $name-$ym ..."
      if curl -fsSL --retry 2 --connect-timeout 10 --max-time 300 "$base/$name-$ym.mmdb.gz" | gunzip > "$file.tmp" 2>/dev/null \
          && [ -s "$file.tmp" ]; then
        mv "$file.tmp" "$file"
        break
      fi
      rm -f "$file.tmp"
    done
  fi
  if [ -s "$file" ]; then
    export SNOWPRINT_GEOIP_DB="$file"
    export SNOWPRINT_GEOIP_ATTRIBUTION="${SNOWPRINT_GEOIP_ATTRIBUTION:-IP Geolocation by DB-IP}"
    export SNOWPRINT_GEOIP_ATTRIBUTION_URL="${SNOWPRINT_GEOIP_ATTRIBUTION_URL:-https://db-ip.com}"
    echo "[geoip] using $file"
  else
    echo "[geoip] download failed; starting without locations" >&2
  fi
}

if [ "${1:-}" = migrate ]; then
  migrate
  exit 0
fi

if [ -n "${1:-}" ]; then
  export SNOWPRINT_ROLE="$1"
  shift
fi

case "${SNOWPRINT_SKIP_MIGRATIONS:-false}" in
  true|1|yes) ;;
  *) migrate ;;
esac
if [ -n "${SNOWPRINT_GEOIP_DOWNLOAD:-}" ] && [ -z "${SNOWPRINT_GEOIP_DB:-}" ]; then
  geoip_download
fi
exec php bin/server.php "$@"
