#!/bin/sh
# Builds Winter Boot's SQL migrator PHAR from the framework's own
# build/sqlmigrator/box.json.
#   migrations/build-phar.sh [output-path]
# Default output: migrations/winter-migrations-app.phar (git-ignored).
#
# Framework source, first match wins:
#   WINTER_BOOT_DIR  a local winter-boot checkout (default ../winter-boot next to
#                    this repo, the same one composer.json links during development)
#   WINTER_BOOT_REF  a winter-boot commit fetched from GitHub
# Needs php, composer and (for WINTER_BOOT_REF) git; downloads Box if not installed.
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:-$SCRIPT_DIR/winter-migrations-app.phar}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac
BOX_VERSION="${BOX_VERSION:-4.7.0}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

LOCAL="${WINTER_BOOT_DIR:-$SCRIPT_DIR/../../winter-boot}"
if [ -z "${WINTER_BOOT_REF:-}" ] && [ -f "$LOCAL/composer.json" ]; then
    # Working tree as-is (uncommitted changes included), without build output.
    mkdir "$WORK/winter-boot"
    tar -C "$LOCAL" --exclude=./vendor --exclude=./target --exclude=./.git -cf - . | tar -C "$WORK/winter-boot" -xf -
    SOURCE="$(cd "$LOCAL" && pwd)"
else
    [ -n "${WINTER_BOOT_REF:-}" ] || { echo "no winter-boot checkout at $LOCAL; set WINTER_BOOT_DIR or WINTER_BOOT_REF" >&2; exit 1; }
    git init --quiet "$WORK/winter-boot"
    git -C "$WORK/winter-boot" fetch --quiet --depth 1 https://github.com/suvera/winter-boot.git "$WINTER_BOOT_REF"
    git -C "$WORK/winter-boot" checkout --quiet FETCH_HEAD
    SOURCE="commit $WINTER_BOOT_REF"
fi
(cd "$WORK/winter-boot" && composer install --quiet --no-dev --no-interaction --prefer-dist --ignore-platform-reqs)

BOX="${BOX_EXEC:-$(command -v box || true)}"
if [ -z "$BOX" ]; then
    BOX="$WORK/box.phar"
    curl -fsSL -o "$BOX" "https://github.com/box-project/box/releases/download/$BOX_VERSION/box.phar"
fi

(cd "$WORK/winter-boot/build/sqlmigrator" && php -d phar.readonly=0 "$BOX" compile --no-interaction --quiet)
mkdir -p "$(dirname "$OUT")"
mv "$WORK/winter-boot/target/winter-migrations-app.phar" "$OUT"
echo "Built $OUT (Winter Boot from $SOURCE)"
