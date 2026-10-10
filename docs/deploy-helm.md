# Deploying Snowprint with Helm

This guide installs Snowprint on Kubernetes with the chart in [`deploy/helm`](../deploy/helm).
Snowprint does not install a database: it connects to **your PostgreSQL 15+**, wherever it
runs (managed cloud database, an operator such as CloudNativePG, or a server you already have).

Every step below is exercised by [`tests/helm-e2e.sh`](../tests/helm-e2e.sh) on a throwaway
kind cluster.

## What gets deployed

| Mode | Deployments | Use it for |
|---|---|---|
| `single` (default) | `snowprint-all`: web, ingest and worker roles in one pod | most sites; scale with `single.replicas` |
| `split` | `snowprint-web`, `snowprint-ingest`, `snowprint-worker` | high traffic: scale ingest on its own, optional CPU autoscaler |

Also created:

- **A migration Job** (Helm pre-install/pre-upgrade hook): applies database migrations once,
  before new pods start, so replicas never race each other.
- **Services** for the web and ingest roles, and an **Ingress** when enabled. The Ingress
  sends `/api/event` and `/snow.js` to ingest, and `/api` and `/ui` to web; in single mode
  they all go to the same pods.
- Pods run as a non-root user with a read-only root filesystem. Liveness uses
  `/api/status` (no database), readiness uses `/api/system/health` (includes PostgreSQL),
  so a database outage takes pods out of the load balancer without restarting them.

## 1. Prerequisites

- A Kubernetes cluster and `kubectl` access to it; [Helm](https://helm.sh) 3.8 or newer.
- PostgreSQL 15+ reachable from the cluster.
- An ingress controller (for example ingress-nginx) if you want a public hostname, and
  cert-manager or a TLS Secret for HTTPS.

## 2. Prepare PostgreSQL

Create a login and a database owned by it (same as the [README](../README.md#1-postgresql)):

```sql
CREATE ROLE snowprint LOGIN PASSWORD 'choose-a-strong-password';
CREATE DATABASE snowprint OWNER snowprint;
```

Allow connections from the cluster's pod network in `pg_hba.conf`, or in your managed
database's network settings.

## 3. Make the image available

Until images are published, build it and push it to a registry your cluster can pull from.
Put that registry in `deploy/values.local.yaml` (step 4) and `docker/build.sh` uses it:

```yaml
image:
  repository: registry.example.com/snowprint
  tag: "0.1.0-dev"
  pullPolicy: Always
```

```bash
docker/build.sh --push      # builds and pushes registry.example.com/snowprint:0.1.0-dev and :latest
```

`deploy/deploy.sh` restarts the pods on every run, so with `pullPolicy: Always` they pull the
image you just pushed even though the tag did not change.

On a local cluster you can skip the registry: `kind load docker-image <image>` (kind) or
use the image straight from Docker Desktop's Kubernetes.

## 4. Configure: `deploy/values.local.yaml`

```bash
cp deploy/values.example.yaml deploy/values.local.yaml
```

`deploy/values.local.yaml` is **git-ignored**: it holds your hostnames and may hold a
password. `deploy/deploy.sh` refuses to run without it. The settings that matter:

| Key | Example | Meaning |
|---|---|---|
| `image.repository` / `image.tag` | `registry.example.com/snowprint` / `0.1.0-dev` | the image from step 3 |
| `mode` | `single` | `single` or `split` |
| `database.url` | `pgsql:host=pg.db.svc.cluster.local;port=5432;dbname=snowprint` | PDO DSN of your PostgreSQL |
| `database.user` | `snowprint` | the role from step 2 |
| `database.maxConnections` | `4` | connections per process; 6 processes per single/worker pod, 4 per web/ingest pod (see "Database connections") |
| `database.existingSecret` | `snowprint-db` | Secret holding the password (recommended) |
| `database.password` | | or let the chart create the Secret from this value |
| `ingress.enabled`, `ingress.host`, `ingress.className` | `true`, `stats.example.com`, `nginx` | public hostname |
| `ingress.tls.enabled`, `ingress.tls.secretName` | `true`, `snowprint-tls` | HTTPS |
| `publicUrl` | `https://stats.example.com` | public tracking URL for snippets; a different host is added to the Ingress (tracker paths only) |
| `requestTrace`, `jsonPrettyPrint` | `false` | debugging: per-request log line, indented JSON |
| `secureCookies` | `true` | Secure dashboard cookie; set `false` only for plain-HTTP tests |
| `mail.host`, `mail.port`, `mail.username`, `mail.from`, `mail.existingSecret` | `smtp.example.com`, `587`, `stats@example.com`, , `snowprint-smtp` | optional SMTP server that emails invite links; the password comes from the Secret |
| `geoip.download` | `dbip-city-lite` | locations: download DB-IP Lite (free) when each pod starts; credited in the dashboard |
| `geoip.existingClaim`, `geoip.file` | `snowprint-geoip`, `dbip-city-lite.mmdb` | or your own GeoIP database on a PVC |

Every other option (replicas, autoscaling, resources, extra env, node selectors) is
documented in [`deploy/helm/values.yaml`](../deploy/helm/values.yaml).

Create the password Secret once if you use `existingSecret`:

```bash
kubectl create namespace snowprint
kubectl -n snowprint create secret generic snowprint-db --from-literal=password='choose-a-strong-password'
```

## 5. Deploy

```bash
deploy/deploy.sh
```

It runs `helm upgrade --install snowprint deploy/helm -f deploy/values.local.yaml --wait` in
the namespace named by `namespace:` in your values file (default `snowprint`) and lists the
pods. Options (environment variables): `NAMESPACE` (overrides the file), `RELEASE`,
`IMAGE=repo:tag` to override the image, `SNOWPRINT_VALUES` to use another values file.
The script prints the kubectl context it deploys to: check it.

## 6. Verify

```bash
kubectl -n snowprint get pods
kubectl -n snowprint port-forward svc/snowprint-all 7669:80      # snowprint-web in split mode
curl http://localhost:7669/api/system/health                     # {"status":"UP",...}
```

Open `https://stats.example.com/ui/` (or `http://localhost:7669/ui/` through the
port-forward). The first visitor creates the admin account. Add sites in the dashboard, or
with the operator console:

```bash
kubectl -n snowprint exec deploy/snowprint-all -- bin/console.sh site:add example.com
kubectl -n snowprint exec deploy/snowprint-all -- bin/console.sh key:create claude example.com
```

Tracking snippet: `<script defer src="https://stats.example.com/snow.js" data-domain="example.com"></script>`.
MCP endpoint: `https://stats.example.com/api/mcp`.

## Scaling

Switch to split mode in `deploy/values.local.yaml` and run `deploy/deploy.sh` again:

```yaml
mode: split
split:
  web: { replicas: 2 }
  ingest:
    replicas: 2
    autoscaling: { enabled: true, minReplicas: 2, maxReplicas: 10, targetCPUUtilizationPercentage: 70 }
  worker: { replicas: 1 }
```

Workers can run more than one replica: rollups and retention take a lock in PostgreSQL
(Winter Boot's `#[Lockable]`, table `winter_locks`) so one pod runs them at a time, and the
other jobs use advisory locks or are idempotent.

**Database connections.** Each process that uses the database may keep
`database.maxConnections` (default 4) connections open. Single-mode and worker pods run 6
such processes (4 HTTP workers, 2 scheduler workers), so they hold at most 24; web and
ingest pods run 4 (16). Add up all pods at their maximum replicas (the autoscaler's
`maxReplicas`), plus 1 for the migration Job and whatever else uses the server, and keep it
below PostgreSQL's `max_connections` (default 100). The example above can reach 2 web +
10 ingest + 1 worker pods, 2 × 16 + 10 × 16 + 24 = 216 connections: lower `maxConnections`
to 2 (108) and raise `max_connections`, or lower `maxReplicas`. When the server is full, new pods and the migration Job fail with
`sorry, too many clients already`.

## Upgrading

Build and push the new image (`docker/build.sh --push`), set `image.tag` if it changed, and
run `deploy/deploy.sh`. The migration Job
runs first; pods roll only after it succeeds.

## Uninstalling

```bash
deploy/uninstall.sh
```

Removes the release and the chart-created password Secret. Your PostgreSQL database is not
touched.

## Troubleshooting

| Symptom | Check |
|---|---|
| `deploy.sh` stops at "values.local.yaml not found" | step 4 |
| Migration Job fails | `kubectl -n snowprint logs job/snowprint-migrate`: database URL, password, `pg_hba.conf`, role owns the database |
| Pods not Ready | `kubectl -n snowprint describe pod ...`: readiness is `/api/system/health`, which is DOWN while PostgreSQL is unreachable |
| Visitor IPs all the same | the ingress (or cloudflared) must pass the client IP: `CF-Connecting-IP`, `X-Forwarded-For` or `X-Real-IP`; `trustProxy` is `true` by default. `snowprint_client_ip_source_total` on `/api/system/prometheus` shows which source is used and whether it is private (= not the real visitor) |
| Dashboard sign-in does not stick on plain HTTP | set `secureCookies: false` (or serve HTTPS) |
| Locations panel empty | no GeoIP database: set `geoip.download: dbip-city-lite`; pod logs show `[geoip] using ...` or why the download failed |
