# Snowprint

**Self-hosted, privacy-first web analytics with a built-in MCP server, built on [Winter Boot](https://github.com/suvera/winter-boot).**
One PHP container plus Postgres. No cookies. Ask your traffic questions from Claude.

[![CI](https://github.com/suvera/snowprint-analytics/actions/workflows/ci.yml/badge.svg)](https://github.com/suvera/snowprint-analytics/actions/workflows/ci.yml)
[![Built on Winter Boot](https://img.shields.io/badge/built%20on-Winter%20Boot-1f6feb)](https://github.com/suvera/winter-boot)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777bb4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

> Footprints in fresh snow: visible today, gone tomorrow. Snowprint identifies visitors
> with a hash whose salt rotates every 24 hours and is then discarded, so you see your
> traffic without being able to follow any one person.

> [!WARNING]
> **Pre-alpha.** Snowprint tracks, reports and answers MCP queries (see the live instance
> below), but there is no release yet: expect breaking changes until 1.0. Follow the
> [roadmap](#roadmap) or watch the repo for the first release.

**Live instance:** [snowprint.suvera.xyz/ui](https://snowprint.suvera.xyz/ui/), a real Snowprint
deployment on Kubernetes tracking the [Winter Boot documentation](https://suvera.github.io/winter-boot/).

![Snowprint dashboard tour: overview with visitors, visits, pageviews, bounce rate and visit duration and a traffic chart, then the sources, locations and events reports](docs/images/dashboard.gif)

<sub>Synthetic data from `bin/console.sh demo:seed`. Still screenshot:
[docs/images/dashboard.jpg](docs/images/dashboard.jpg).</sub>

---

## Why Snowprint

- **One container + your PostgreSQL.** Point it at the PostgreSQL you already run,
  paste a ~1 KB script, done. No ClickHouse, no cron, no queue to babysit.
- **Cookie-less by design.** No cookies, no localStorage, no IP addresses or user agents
  stored. Designed to store no personal data; see [Privacy](docs/privacy.md) for exactly what
  is kept, and check your own legal obligations.
- **MCP built in.** A Model Context Protocol server ships in the box, so Claude
  (Desktop or Code) or any MCP client can query your analytics with a scoped API key.
- **Built on Winter Boot.** Long-running, Spring-Boot-style PHP services: dependency
  injection, REST controllers, scheduling, sessions, migrations, metrics and health
  checks all come from the framework. Measured: ~9,900 events/s on 2 CPUs and ~19,800 on
  4, every event stored ([benchmark](#performance)).
- **Leave Matomo without losing your data** (planned for v1.0). Matomo tracker
  compatibility, an importer, and an honest list of what does not carry over.
- **Microservices by heart.** One image, three roles (`web`, `ingest`, `worker`; an
  `importer` role comes with the Matomo migration). Run them all in one process, or scale
  each role on its own: the difference is configuration, not code.

## Built on Winter Boot

Snowprint is a real-world, production-shaped application of [Winter Boot](https://github.com/suvera/winter-boot), a Spring-Boot-style
framework for long-running PHP 8.5 microservices. It is not a PHP-FPM app with a queue
bolted on: each part of Snowprint maps to a Winter Boot feature.

| Snowprint needs | Winter Boot provides |
|---|---|
| Tracking, dashboard and MCP endpoints | `#[RestController]`, `#[GetMapping]`, `#[PostMapping]`, … |
| Batched event writes | Swoole worker start/stop hooks for a per-worker buffer, `PdbcTemplate` multi-row inserts |
| Sessions, daily rollups, retention, salt rotation | `#[Scheduled]` jobs, safe on several workers (idempotent or advisory locks) |
| Dashboard sign-in | Coroutine-safe `SessionManager` with `PdbcSessionStore` |
| Operator API, request guards | `HandlerInterceptor` in a `WebMvcConfigurer` |
| Zero-touch upgrades | Built-in SQL migrator |
| Health checks and metrics | Actuator (`#[HealthInformer]`), Prometheus registry |
| One image, three roles | One `#[WinterBootApplication]` starter per role, shared beans |

Planned: `#[Cacheable]` dashboards, OpenTelemetry traces, and Redis / Kafka / OpenSearch
tiers through Winter Boot modules switched on in `application.yml`.

If you want to see how a Winter Boot service is structured end to end, this repo is
meant to be a readable example. Reusable pieces (the ingest buffer) are candidates to
upstream into Winter Boot.

## Performance

`bench/run.sh` runs PostgreSQL 18 and the Snowprint image in Docker on one machine, as the
Install section describes, and sends realistic tracker events with [k6](https://k6.io) at a
fixed rate for 30 seconds. Afterwards it checks that every accepted event was stored.

| CPUs (app / PostgreSQL) | Offered | Accepted | Failed | Stored | Median | p95 | p99 |
|---|---|---|---|---|---|---|---|
| 2 / 2 | 10,000/s | 9,855/s | 0 | 100 % | 0.25 ms | 4.6 ms | 29.6 ms |
| 4 / 4 | 20,000/s | 19,756/s | 0 | 100 % | 0.54 ms | 8.5 ms | 13.3 ms |

Intel Core Ultra 9 285, 50 GB RAM, Docker in WSL2; containers limited with `--cpus`; 4 Swoole
workers; Snowprint `591a251`. Latency is measured by the client over loopback. The offered
rate is the target; k6 itself did not start about 1 % of the iterations on time
(`dropped_iterations`), so "Accepted" counts the requests actually sent, none of which failed.
Method, older runs and caveats (CPU quotas, unique user agents): [`bench/README.md`](bench/README.md).

## How it compares

| | Plausible | Umami | Matomo | **Snowprint** |
|---|---|---|---|---|
| Stack | Elixir + ClickHouse | Node + Postgres | PHP-FPM + MySQL | **PHP 8.5 / Winter Boot** + Postgres |
| What you run | App + Postgres + ClickHouse | App + Postgres | PHP-FPM + web server + MySQL + cron | **1 container + your Postgres** |
| Cookie-less by default | Yes | Yes | Optional | **Yes** |
| Built-in MCP server | No | No | No | **Yes** |
| Matomo migration | No | No | n/a | **Tracker compat + importer (planned, v1.0)** |

## Install

Snowprint is **one container that connects to your own PostgreSQL**. It does not ship or
manage a database for you.

### 1. PostgreSQL

You need **PostgreSQL 15 or newer** (tested on 15 and 18). Install it any way you like: your
OS package manager, an official installer, a managed cloud database (RDS, Cloud SQL, Azure,
Neon, Supabase…), Docker or Kubernetes. If you already run PostgreSQL, use that.

Create a login and a database owned by it:

```sql
CREATE ROLE snowprint LOGIN PASSWORD 'choose-a-strong-password';
CREATE DATABASE snowprint OWNER snowprint;
```

Snowprint creates and upgrades its own tables on every start, so its user must **own** the
database. No superuser rights are needed.

Make sure the Snowprint container can reach PostgreSQL:

- `listen_addresses` in `postgresql.conf` must include an address the container can reach
  (for example `'*'`, or the Docker bridge address).
- `pg_hba.conf` must allow the login. For a container on Docker's default bridge network:

  ```
  host  snowprint  snowprint  172.17.0.0/16  scram-sha-256
  ```

Reload PostgreSQL after changing either file. Managed databases handle this through their
firewall or network settings instead.

### 2. Configure

```bash
git clone https://github.com/suvera/snowprint-analytics.git
cd snowprint-analytics
cp .env.example .env
```

Edit `.env`:

| Variable | Example | What it is |
|---|---|---|
| `SNOWPRINT_DB_URL` | `pgsql:host=db.example.com;port=5432;dbname=snowprint` | Where PostgreSQL is (PDO DSN) |
| `SNOWPRINT_DB_USER` | `snowprint` | The login from step 1 |
| `SNOWPRINT_DB_PASSWORD` | `choose-a-strong-password` | Its password |
| `SNOWPRINT_TRUST_PROXY` | `true` | Optional, default `false`. Take the visitor IP from proxy headers, in order: `CF-Connecting-IP` (Cloudflare / cloudflared), `X-Forwarded-For` (leftmost), `X-Real-IP`, then the connection. Enable only behind a proxy that sets them (Cloudflare Tunnel, nginx, Caddy, Traefik, an ingress): otherwise visitors could forge them |
| `SNOWPRINT_RESPECT_DNT` | `false` | Optional, default `true`. Ignore visits from browsers that send `DNT: 1` |
| `SNOWPRINT_GEOIP_DOWNLOAD` | `dbip-city-lite` | Optional. Download DB-IP Lite at start-up for locations (see below) |
| `SNOWPRINT_GEOIP_DB` | `/geo/city.mmdb` | Optional. Your own GeoIP database inside the container (see below) |
| `SNOWPRINT_LOG_CLIENT_IP` | `true` | **Debugging only**, default `false`. Log each tracker request's resolved client IP and the proxy headers it came from. Raw IPs in logs break the privacy promise: switch it off once your proxy setup is verified |
| `SNOWPRINT_REQUEST_TRACE` | `true` | Optional, default `false`. Log one line per request: method, path, status, duration |
| `SNOWPRINT_JSON_PRETTY` | `true` | Optional, default `false`. Indent JSON API responses |
| `SNOWPRINT_PUBLIC_URL` | `https://stats.example.com` | Optional. Public base URL that websites load `/snow.js` from and send events to; used in the dashboard's tracking snippet. Default: the address the dashboard is opened on |
| `SNOWPRINT_SMTP_HOST` | `smtp.example.com` | Optional. SMTP server that emails invite links. Not set: admins copy the links themselves |
| `SNOWPRINT_SMTP_PORT` | `587` | Optional, default `587` |
| `SNOWPRINT_SMTP_ENCRYPTION` | `ssl` | Optional, default `starttls` (port 587). `ssl` for implicit TLS (port 465), `none` only for a relay on a trusted network |
| `SNOWPRINT_SMTP_USER` / `SNOWPRINT_SMTP_PASSWORD` | `stats@example.com` | Optional. SMTP login (`AUTH LOGIN`); leave unset for a relay without login |
| `SNOWPRINT_SMTP_FROM` | `Stats <stats@example.com>` | Optional. Sender address. Default: the SMTP user |

- PostgreSQL on the **same machine** as Docker: use `host=host.docker.internal` and add
  `--add-host=host.docker.internal:host-gateway` to `docker run` (step 3).
- Write values **without quotes**: `docker run --env-file` passes them literally.
- `.env` holds your password and is git-ignored. Never commit it.

**GeoIP (optional).** Countries, regions and cities need a MaxMind-format database, which
Snowprint does not ship. Two ways:

- **Automatic (easiest):** set `SNOWPRINT_GEOIP_DOWNLOAD=dbip-city-lite` (about 60 MB) or
  `dbip-country-lite` (about 8 MB, countries only). The container downloads
  [DB-IP Lite](https://db-ip.com/db/lite.php) (free, CC BY 4.0) when it starts and the
  dashboard shows the required "IP Geolocation by DB-IP" credit. Restart monthly to pick up
  DB-IP's update. `SNOWPRINT_GEOIP_DOWNLOAD_BASE_URL` points it at a mirror.
- **Your own file:** [DB-IP Lite](https://db-ip.com/db/download/ip-to-city-lite) or
  [MaxMind GeoLite2 City](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data)
  (free account and licence key). Mount it with `-v /srv/geoip:/geo:ro` in step 3 and set
  `SNOWPRINT_GEOIP_DB=/geo/<file>.mmdb`; for DB-IP also set
  `SNOWPRINT_GEOIP_ATTRIBUTION="IP Geolocation by DB-IP"` and
  `SNOWPRINT_GEOIP_ATTRIBUTION_URL=https://db-ip.com`.

Lookups happen in memory; only the country, region and city are stored, never the IP.

### 3. Run

Build the image from this repository (Winter Boot comes from Packagist):

```bash
docker build -f docker/Dockerfile -t snowprint-analytics .
docker run -d --name snowprint --restart unless-stopped \
  -p 7669:7669 --env-file .env \
  --add-host=host.docker.internal:host-gateway \
  snowprint-analytics

curl http://localhost:7669/api/system/health      # {"status": "UP"}
```

On start the container applies any pending database migrations, then serves the
dashboard, tracker and API on port **7669**. Open **http://localhost:7669/ui/**: the
first visitor creates the admin account, then adds sites and copies their tracking snippet. To publish a different port, change the
left side of `-p` (for example `-p 80:7669`).

**Upgrading:** `git pull`, rebuild the image, and replace the container. Migrations run
automatically.

To run without Docker, see [Development](#development). **Kubernetes:** see
[Deploying with Helm](docs/deploy-helm.md) (single pod or separate web / ingest / worker
deployments, migrations as a Helm hook).

### Add a site and the tracking snippet

Add sites in the dashboard (**Sites → Add a site**), or with the operator console inside
the container:

```bash
docker exec snowprint bin/console.sh site:add example.com Europe/Berlin   # timezone optional
docker exec snowprint bin/console.sh site:list
docker exec snowprint bin/console.sh site:set example.com retention 365  # raw events kept (days, 0 = forever)
docker exec snowprint bin/console.sh help
```

To look around before you have traffic, `bin/console.sh demo:seed demo.example` fills a
new, empty site with 60 days of synthetic visits (it refuses sites that have data).

A site's **Settings** page changes its timezone and raw-event retention (default 90 days), shows
the snippet, and deletes the site with its data. New sites start receiving events within 30 seconds. Then add this to every page
(Snowprint serves the tracker itself):

```html
<script defer src="https://stats.example.com/snow.js" data-domain="example.com"></script>
```

- `data-domain`: the site's domain as registered in Snowprint (defaults to the page host).
- Custom events: `snow('signup', {plan: 'pro'})`. To call `snow()` before the script loads,
  add `<script>window.snow=window.snow||function(){(snow.q=snow.q||[]).push(arguments)}</script>`.
- Single-page apps are tracked automatically (History API). `localhost` is ignored unless the
  tag has `data-local`. Browsers sending Do Not Track are not tracked.
- No cookies, no local storage; under 1.5 KB gzipped.

### Users

The first visitor creates the admin account. Admins invite others under **Users → Invite a
user**: choose admin (everything) or a role per site, **viewer** (reports) or **admin**
(reports, goals and the site's settings). With `SNOWPRINT_SMTP_HOST` set, Snowprint emails
the invite link; either way the admin sees the link once and can copy it. It works once, for
7 days.

### Share links

To show a site's reports to people without an account, a site admin creates a **share link**
on the site's **Settings** page, optionally with a password. Anyone with the link reads the
reports but cannot change anything. The link is shown once; delete it to revoke access. The
operator console creates links without a password:

```bash
docker exec snowprint bin/console.sh share:create example.com "Public stats"
```

## Ask Claude

Snowprint has a built-in [MCP](https://modelcontextprotocol.io) server at `/api/mcp`, so Claude
(or any MCP client) can query your analytics directly.

**1. Create an API key** (read-only, limited to the sites you list; shown once):

```bash
docker exec snowprint bin/console.sh key:create claude example.com      # or: all
```

**2. Connect your client.** Claude Code:

```bash
claude mcp add --transport http snowprint https://stats.example.com/api/mcp \
  --header "Authorization: Bearer sp_..."
```

Claude Desktop (`claude_desktop_config.json`), through the `mcp-remote` bridge:

```json
{
  "mcpServers": {
    "snowprint": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://stats.example.com/api/mcp",
               "--header", "Authorization: Bearer sp_..."]
    }
  }
}
```

**3. Ask.** For example:

> "Compare last week with the week before for example.com, tell me what caused the
> drop on Wednesday, and draft a note for the team."

| Tool | What it answers |
|---|---|
| `list_sites` | Which sites this key can read |
| `get_overview` | Visitors, visits, pageviews, bounce rate, visit duration, with % change vs the previous period |
| `get_timeseries` | One metric by hour, day or month |
| `get_breakdown` | Top pages, entry/exit pages, sources, UTM tags, countries, browsers, devices, events |
| `get_realtime` | Visitors in the last 5 minutes |
| `get_goals` | Goal conversions and conversion rates |
| `find_anomalies` | Days with unusual spikes or drops |

Every tool takes a period (`7d`, `30d`, `month`, `2026-10-01..2026-10-07`, …) and optional
filters (`{"country": "DE", "page": "/blog/*"}`). There are also prompts (`weekly_report`,
`explain_spike`, `seo_opportunities`, `campaign_review`) and summary resources
(`site://example.com/summary/today`). Calls are rate-limited per key and audit-logged
without their arguments. Manage keys with `key:list` and `key:revoke <id>`.

## Privacy

- Visitor hash = BLAKE2b(daily salt ‖ site id ‖ IP ‖ user agent), truncated to 16 bytes.
- The salt rotates every UTC day and is discarded after 48 hours.
- Raw IPs and user agents are never written to disk. Only derived values are stored
  (country, browser, device type).
- The site id is part of the hash, so a visitor cannot be tracked across sites.
- Raw events are kept for each site's retention period (90 days by default, `0` = forever).
  Before they are deleted, every finished day is rolled up into daily totals per page,
  source, country and so on, which are kept. Unfiltered reports keep working for any
  period; filtered reports and goals need raw events, so they cover the retention period.

[docs/privacy.md](docs/privacy.md) lists every stored field, retention, logs, and what this
means for requests from visitors.

## Development

Snowprint is a [Winter Boot](https://github.com/suvera/winter-boot) application. Requirements: PHP 8.5, see [`Installation Guide`](https://suvera.github.io/winter-boot/installation/) ; Composer; Postgres 15+. The Docker image
([`docker/Dockerfile`](docker/Dockerfile)) is built from Winter Boot's own base-image
Dockerfile, so it includes all of them.

```bash
composer install
migrations/build-phar.sh      # builds Winter Boot's SQL migrator (once)
migrations/run.sh             # applies migrations/analytics/*.sql
php bin/server.php            # http://localhost:7669
composer test
composer test:tracker         # snow.js behaviour tests (Node 18+)
composer test:ui              # dashboard unit tests (Node 18+)
tests/integration.sh          # SQL reports against a throwaway PostgreSQL (Docker)
tests/helm-e2e.sh             # the Helm guide on a throwaway kind cluster
docker/build.sh               # build suvera/snowprint:<version> + :latest (--push to publish)
```

Use the same PostgreSQL setup as [Install step 1](#1-postgresql). All settings live in
[`config/application.yml`](config/application.yml). `.env` is **not** loaded outside
Docker; export the database variables in your shell before running the commands above
(they default to `snowprint`/`snowprint` on `127.0.0.1:5432`):

```bash
# Without the APCu extension, run without Prometheus metrics:
export SNOWPRINT_METRICS_STORE='dev\winterframework\io\metrics\prometheus\NoAdapter'
export SNOWPRINT_DB_URL='pgsql:host=127.0.0.1;port=5432;dbname=snowprint'
export SNOWPRINT_DB_USER=snowprint SNOWPRINT_DB_PASSWORD='your-password'
```

`tests/e2e.sh` checks the whole Install section end to end in Docker, using a throwaway
PostgreSQL container. CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs these
same scripts on every push and pull request: unit and integration tests, the tracker and
dashboard tests, `tests/e2e.sh` on PostgreSQL 15 and 18, and `tests/helm-e2e.sh`.

Dashboard development (Node 18+): `cd ui && npm ci && npm run dev` serves the React app
with hot reload on http://localhost:5173/ui/ and proxies `/api` to a Snowprint running on
:7669. `npm run build` writes it to `public/ui/` (the Docker image does this itself).

### Project layout

```
bin/            server.php: launcher (SNOWPRINT_ROLE = all | web | ingest | worker);
                console.sh: operator console (sites, API keys)
config/         all settings: application.yml (app + migrator), logger.yml
docker/         Dockerfile (from Winter Boot's base image), build.sh, entrypoint
deploy/         Helm chart (helm/), deploy.sh, uninstall.sh, values.example.yaml
docs/           product requirements and design notes
migrations/     run.sh, build-phar.sh, and SQL per datasource (analytics/)
src/            PHP namespace dev\suvera\snowprint
  boot/         one Winter Boot starter per role (scanNamespaces per role)
  infra/        framework wiring in code: #[Configuration] beans, interceptors
  ingest/       /api/event and Matomo tracker endpoints, batching, bot filter, GeoIP
  privacy/      daily salt rotation, visitor hashing
  rollup/       scheduled jobs: sessionizer, daily rollups, retention, partitions
  query/        reporting queries shared by the dashboard and MCP
  web/          dashboard API, sign-in, users, operator API
  mcp/          MCP endpoint and tools
  site/         sites, users, roles, API keys, goals
ui/             dashboard: React + TypeScript (Vite), built into public/ui/
public/         served as-is by Swoole: snow.js (the tracker), ui/ (built dashboard)
tests/          PHPUnit tests, tracker tests (Node), e2e.sh: the documented install end to end
bench/          ingest benchmark harness
```

## Roadmap

| Milestone | Scope |
|---|---|
| M0 spike | Ingest path, batched writes, published benchmark |
| M1 MVP | Tracking script, dashboard, goals, users, rollups and retention, MCP tools, Docker image, Helm chart |
| M2 launch | Share links, CI, published image and benchmark, docs |
| v1.0 | Matomo tracker compatibility and importer, email reports, Redis tier |
| v1.1 | Basic ecommerce, Matomo Reporting API subset, Kafka/OpenSearch tiers, WordPress plugin |

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).
To report a security problem, see [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) © 2026 Suvera

Matomo is a registered trademark of InnoCraft Ltd. Plausible and Umami are trademarks of
their owners. Snowprint is an independent project, not affiliated with or endorsed by any of
them; their names are used only to describe compatibility and comparisons.
