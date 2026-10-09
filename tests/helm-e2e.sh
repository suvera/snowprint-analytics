#!/bin/sh
# End-to-end test of docs/deploy-helm.md on a throwaway kind cluster:
# image -> kind, PostgreSQL in the cluster (stands in for yours), the guide's
# SQL, deploy/deploy.sh in single mode, then an upgrade to split mode.
# Uses its own kubeconfig: your current kubectl context is never touched.
#   tests/helm-e2e.sh            (needs docker, kind, kubectl, helm)
# E2E_KEEP=1 keeps the cluster for inspection (delete: kind delete cluster --name snowprint-e2e).
set -eu
cd "$(dirname "$0")/.."

CLUSTER=snowprint-e2e
NS=snowprint
IMAGE=snowprint-analytics:helm-e2e
WORK="$(mktemp -d)"
export KUBECONFIG="$WORK/kubeconfig"
PF_PID=""

cleanup() {
    [ -n "$PF_PID" ] && kill "$PF_PID" 2>/dev/null || true
    if [ "${E2E_KEEP:-0}" != 1 ]; then kind delete cluster --name "$CLUSTER" >/dev/null 2>&1 || true; fi
    rm -rf "$WORK"
}
fail() {
    echo "FAIL: $1" >&2
    kubectl -n "$NS" get pods >&2 || true
    kubectl -n "$NS" logs -l app.kubernetes.io/instance=snowprint --tail=20 >&2 || true
    exit 1
}
trap cleanup EXIT
kind delete cluster --name "$CLUSTER" >/dev/null 2>&1 || true

echo "==> kind cluster"
kind create cluster --name "$CLUSTER" --wait 120s >/dev/null 2>&1 || fail "kind create cluster"
docker build -q -f docker/Dockerfile --build-context winter-boot="${WINTER_BOOT_DIR:-../winter-boot}" -t "$IMAGE" . >/dev/null
kind load docker-image "$IMAGE" --name "$CLUSTER" >/dev/null

echo "==> PostgreSQL + the guide's SQL"
kubectl apply -f tests/helm/postgres.yaml >/dev/null
kubectl -n db rollout status deploy/postgres --timeout=180s >/dev/null || fail "postgres did not start"
kubectl -n db exec -i deploy/postgres -- psql -q -v ON_ERROR_STOP=1 -U postgres <<'SQL'
CREATE ROLE snowprint LOGIN PASSWORD 'helm-e2e-secret';
CREATE DATABASE snowprint OWNER snowprint;
SQL

echo "==> deploy.sh (single mode)"
cat > "$WORK/values.yaml" <<VALUES
image: { repository: ${IMAGE%:*}, tag: "${IMAGE##*:}", pullPolicy: Never }
mode: single
database:
  url: "pgsql:host=postgres.db.svc.cluster.local;port=5432;dbname=snowprint"
  user: snowprint
  password: helm-e2e-secret
secureCookies: false
resources: { requests: { cpu: 100m, memory: 128Mi }, limits: { memory: 512Mi } }
VALUES
SNOWPRINT_VALUES="$WORK/values.yaml" deploy/deploy.sh >/dev/null || fail "deploy.sh (single)"

check() {   # service -> verifies health, dashboard, tracking through a port-forward
    kubectl -n "$NS" port-forward "svc/$1" 17680:80 >/dev/null 2>&1 &
    PF_PID=$!
    curl -fs --retry 20 --retry-connrefused --retry-all-errors --retry-delay 1 http://localhost:17680/api/system/health \
        | grep -q '"UP"' || fail "$1: health"
    curl -fs http://localhost:17680/ui/ | grep -q '<div id="root">' || fail "$1: dashboard"
    kill "$PF_PID"; PF_PID=""
}
check snowprint-all
kubectl -n "$NS" exec deploy/snowprint-all -- bin/console.sh site:add helm.test | grep -q helm.test || fail "console"
kubectl -n "$NS" port-forward svc/snowprint-all 17680:80 >/dev/null 2>&1 &
PF_PID=$!
curl -fs --retry 20 --retry-connrefused --retry-delay 1 -o /dev/null http://localhost:17680/api/status
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -A 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0' \
    --data '{"d":"helm.test","u":"https://helm.test/"}' http://localhost:17680/api/event)" = 202 ] || fail "tracking"
kill "$PF_PID"; PF_PID=""
i=0
until [ "$(kubectl -n db exec deploy/postgres -- psql -tA -U snowprint -d snowprint -c 'select count(*) from events')" = 1 ]; do
    i=$((i + 1)); [ "$i" -le 20 ] || fail "event not stored"; sleep 1
done
[ "$(kubectl -n "$NS" get pod -l app.kubernetes.io/instance=snowprint -o jsonpath='{.items[0].spec.securityContext.runAsNonRoot}')" = true ] \
    || fail "pods must run as non-root"

echo "==> upgrade to split mode"
sed -i 's/^mode: single/mode: split/' "$WORK/values.yaml"
printf 'split: { web: { replicas: 1 }, ingest: { replicas: 2 }, worker: { replicas: 1 } }\n' >> "$WORK/values.yaml"
SNOWPRINT_VALUES="$WORK/values.yaml" deploy/deploy.sh >/dev/null || fail "deploy.sh (split)"
for role in web ingest worker; do
    kubectl -n "$NS" rollout status "deploy/snowprint-$role" --timeout=180s >/dev/null || fail "$role not ready"
done
[ "$(kubectl -n "$NS" get deploy snowprint-all --ignore-not-found -o name)" = "" ] || fail "single-mode Deployment left behind"
check snowprint-web
check snowprint-ingest

echo "PASS: Helm install (single), console, tracking, non-root pods, upgrade to split (web, 2x ingest, worker)"
