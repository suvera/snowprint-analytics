#!/usr/bin/env bash
# Build the Snowprint image (docker/Dockerfile) and optionally push it to
# Docker Hub (decision D6: suvera/snowprint).
# Tags the image with the version from config/application.yml
# (winter.application.version) plus `latest`.
# Pushing requires an authenticated `docker login`.
#
# Usage: ./docker/build.sh [--push]
#   SNOWPRINT_IMAGE overrides the image name (default: image.repository from
#   deploy/values.local.yaml, else suvera/snowprint). Pushing the public
#   suvera/snowprint refuses -dev versions and dirty trees; private registries don't.
#   WINTER_BOOT_DIR is the winter-boot checkout used during development
#   (default ../winter-boot, the path composer.json links).
set -euo pipefail

cd "$(dirname "$0")/.." # repo root = docker build context

PUBLIC_IMAGE="suvera/snowprint"
# Image name: SNOWPRINT_IMAGE, else image.repository from deploy/values.local.yaml
# (so --push goes where your cluster pulls from), else the public name.
LOCAL_VALUES="deploy/values.local.yaml"
LOCAL_REPO=""
if [ -f "$LOCAL_VALUES" ]; then
    LOCAL_REPO="$(awk '/^image:/{i=1; next} i && /^[^ #]/{exit} i && /^  repository:/{print $2; exit}' "$LOCAL_VALUES" | tr -d '"')"
fi
IMAGE="${SNOWPRINT_IMAGE:-${LOCAL_REPO:-$PUBLIC_IMAGE}}"
VERSION="$(awk '/^winter:/{w=1} w && /^  application:/{a=1} a && /^    version:/{print $2; exit}' config/application.yml)"
if [ -z "$VERSION" ]; then
    echo "ERROR: winter.application.version not found in config/application.yml" >&2
    exit 1
fi

PUSH=0
for arg in "$@"; do
    if [ "$arg" = "--push" ]; then
        PUSH=1
    else
        echo "Unknown argument: $arg (only --push is supported)" >&2
        exit 1
    fi
done

# Guards for the public image only; private registries take development builds.
if [ "$PUSH" = "1" ] && [ "$IMAGE" = "$PUBLIC_IMAGE" ]; then
    case "$VERSION" in
        *-dev*) echo "ERROR: refusing to push development version $VERSION to $PUBLIC_IMAGE (set a release version first)" >&2; exit 1 ;;
    esac
    if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
        echo "ERROR: refusing to push $PUBLIC_IMAGE from a dirty working tree" >&2
        exit 1
    fi
fi

REVISION="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
echo "Building $IMAGE:$VERSION and $IMAGE:latest (commit $REVISION) ..."
docker build . -f ./docker/Dockerfile \
    --build-context winter-boot="${WINTER_BOOT_DIR:-../winter-boot}" \
    --label "org.opencontainers.image.version=$VERSION" \
    --label "org.opencontainers.image.revision=$REVISION" \
    --label "org.opencontainers.image.source=https://github.com/suvera/snowprint-analytics" \
    -t "$IMAGE:$VERSION" -t "$IMAGE:latest"

if [ "$PUSH" = "1" ]; then
    echo "Pushing $IMAGE:$VERSION ..."
    docker push "$IMAGE:$VERSION"
    echo "Pushing $IMAGE:latest ..."
    docker push "$IMAGE:latest"
fi

echo "Done: $IMAGE:$VERSION"
