#!/usr/bin/env bash
# Remove the Snowprint release. Your PostgreSQL data is not touched.
#   deploy/uninstall.sh
# Env: RELEASE (default snowprint), NAMESPACE (default: "namespace:" in
# deploy/values.local.yaml, else snowprint), SNOWPRINT_VALUES.
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
echo "Uninstalling release $RELEASE from namespace $NAMESPACE (context: $(kubectl config current-context)) ..."
helm uninstall "$RELEASE" --namespace "$NAMESPACE" --ignore-not-found
# The chart-created database Secret is a Helm hook, which uninstall leaves behind.
# Same naming rule as the chart's snowprint.fullname helper.
case "$RELEASE" in *snowprint*) FULLNAME="$RELEASE" ;; *) FULLNAME="$RELEASE-snowprint" ;; esac
kubectl -n "$NAMESPACE" delete secret "$FULLNAME-db" --ignore-not-found
