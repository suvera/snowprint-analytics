#!/usr/bin/env bash
# Deploy Snowprint to Kubernetes with Helm (docs/deploy-helm.md).
#
#   deploy/deploy.sh
#   IMAGE=registry.example.com/snowprint:0.1.0 deploy/deploy.sh
#
# Requires deploy/values.local.yaml (git-ignored): copy values.example.yaml and edit it.
# Env: RELEASE (default snowprint), NAMESPACE (default: "namespace:" in the values file,
# else snowprint), IMAGE (repo:tag
# override), SNOWPRINT_VALUES (another values file instead of values.local.yaml).
# Prereqs: kubectl with access to the cluster, helm 3.8+.
set -euo pipefail
cd "$(dirname "$0")"

VALUES="${SNOWPRINT_VALUES:-values.local.yaml}"
RELEASE="${RELEASE:-snowprint}"
# Namespace: NAMESPACE env, else top-level "namespace:" in the values file, else snowprint.
values_namespace() {
    [ -f "$1" ] && awk '/^namespace:/{gsub(/["'"'"']/, "", $2); print $2; exit}' "$1"
}
NAMESPACE="${NAMESPACE:-$(values_namespace "$VALUES" || true)}"
NAMESPACE="${NAMESPACE:-snowprint}"

if [ ! -f "$VALUES" ]; then
    echo "ERROR: $(pwd)/$VALUES not found." >&2
    echo "Create it from the example and edit it for your cluster:" >&2
    echo "  cp deploy/values.example.yaml deploy/values.local.yaml" >&2
    exit 1
fi
for tool in kubectl helm; do
    command -v "$tool" >/dev/null || { echo "ERROR: $tool is not installed" >&2; exit 1; }
done

SET_IMAGE=()
if [ -n "${IMAGE:-}" ]; then
    case "$IMAGE" in
        *:*) SET_IMAGE=(--set "image.repository=${IMAGE%:*}" --set "image.tag=${IMAGE##*:}") ;;
        *) echo "ERROR: IMAGE must be repository:tag" >&2; exit 1 ;;
    esac
fi

# Restart pods on every deploy: with a fixed tag and pullPolicy Always they then
# pull the image that was just pushed (Helm alone sees "no change").
STAMP=(--set-string "podAnnotations.snowprint/deployed-at=$(date -u +%Y%m%dT%H%M%SZ)")

echo "Deploying release $RELEASE to namespace $NAMESPACE (context: $(kubectl config current-context)) with $VALUES ..."
helm upgrade --install "$RELEASE" ./helm \
    --namespace "$NAMESPACE" --create-namespace \
    -f "$VALUES" "${SET_IMAGE[@]}" "${STAMP[@]}" \
    --wait --timeout "${HELM_TIMEOUT:-5m}"

kubectl -n "$NAMESPACE" get pods -l "app.kubernetes.io/instance=$RELEASE"
