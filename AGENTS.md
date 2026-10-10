# Snowprint — Agent Guide

**Snowprint is self-hosted, privacy-first web analytics with a built-in MCP server, built on
[Winter Boot](https://github.com/suvera/winter-boot) (PHP 8.5).** One container plus Postgres,
no cookies, and AI assistants can query the data over MCP.

> **Why this project exists:** Snowprint showcases the Winter Boot framework to the
> open-source world. Winter Boot is the headline in everything public (README, docs, posts,
> package descriptions, commit messages). Mention Swoole only as a runtime requirement.

Status: pre-alpha. The product spec is `docs/PRD.md` (local, git-ignored: not in the
repository); when present, read the relevant section
before building a feature.

## Core Invariants (never violate)

1. **Privacy.** Never store raw IP addresses, raw user agents, cookies or anything that
   identifies a person. Visitor identity = BLAKE2b(daily salt ‖ site id ‖ IP ‖ UA),
   truncated to 16 bytes. The salt rotates every UTC day, is never written to the `events`
   table, and is discarded after 48 h. Store only derived values (country, browser, device).
   The single exception is the opt-in debug switch `SNOWPRINT_LOG_CLIENT_IP` (off by
   default) in `ingest/EventController`; never log IPs anywhere else.
2. **Winter Boot first.** Solve problems with Winter Boot features (DI, REST, `#[Async]`,
   `#[Scheduled]`, `#[Lockable]`, `#[Cacheable]`, `#[Transactional]`, migrator, actuator,
   modules) before adding a third-party library. Reusable pieces (the ingest buffer, the
   Matomo tracker façade) should stay generic enough to upstream into Winter Boot. MCP
   (`src/mcp`, `#[McpTool]`) deliberately stays in Snowprint.
3. **Stateless roles.** One image, four roles: `web`, `ingest`, `worker`, `importer`
   (PRD §9.0). Shared state lives in Postgres (or Redis/Kafka in scaled tiers), never in a
   worker's memory beyond a flush window. Roles talk through the database or message bus,
   never over HTTP to each other.
4. **No Matomo server code.** Matomo's server is GPL-3.0; Snowprint is MIT. Implement Matomo
   compatibility from public docs and the DB schema only. Never copy or port Matomo PHP
   (importer, `DataTable` decoding, segment parsing). The JS tracker `piwik.js` is BSD-3 and
   may be reused with its notice kept. "Matomo" is InnoCraft's trademark: say
   "Matomo-compatible" / "migrate from Matomo", never use their logo or imply endorsement.
5. **Honest gaps.** Never claim Matomo parity. Every gap goes in the PRD §8.5 register and,
   later, in the import report.

## Repo Map

- `bin/server.php` — thin launcher; `SNOWPRINT_ROLE` = `all` (default) | `web` | `ingest` |
  `worker` picks a starter from `src/boot/` (`AllRolesApplication`, `WebApplication`, …).
  Each starter scans only its role's namespaces, so new code must live in the right
  namespace (scheduled jobs in `rollup`, endpoints in `web`/`ingest`/`mcp`; shared code in
  `site`, `query`, `privacy`, `infra`). The Docker entrypoint takes the role as its first
  argument.
  Keep a `.gitkeep` in empty `src/` subfolders (Winter Boot skips missing ones with a warning).
- `bin/console.sh` — operator console (POSIX sh, run via `docker exec`): calls `/api/admin/*`
  on 127.0.0.1 with the token the server writes to `var/operator.token` at start-up
  (`infra/OperatorToken`, `web/admin/OperatorInterceptor`). Admin actions go there until
  the dashboard exists.
- `config/application.yml` — **the only settings file**, read by both the app and the
  migrator. `config/logger.yml` — Monolog cascade.
- `migrations/analytics/NNN-*.sql` — Postgres schema, datasource `analytics`.
  `migrations/run.sh` applies them; `migrations/build-phar.sh` builds Winter Boot's
  `winter-migrations-app.phar` (git-ignored).
- `src/` — namespace `dev\suvera\snowprint\` (PSR-4, lowercase folder names):
  - `boot/` role starters and `Roles`
  - `infra/` framework wiring in code (`#[Configuration]` beans, interceptors)
  - `ingest/` `/api/event`, `/matomo.php`, batching, bot filter, GeoIP
  - `privacy/` daily salt, visitor hashing
  - `rollup/` scheduled hourly/daily aggregation, retention sweeps
  - `query/` reporting queries shared by the dashboard and MCP
  - `web/` dashboard, management API, auth, share links (`StatusController` → `/api/status`)
  - `mcp/` MCP endpoint (`/api/mcp`) and tools
  - `site/` sites, users, roles, API keys, goals
  - `matomo/` tracker compatibility and importer
- `ui/` the dashboard: React + TypeScript SPA (Vite), decision D10. **Winter Boot is a
  backend framework only: it never renders HTML.** The SPA talks to the JSON API in
  `src/web/ui` (`/api/ui/*`, session cookie, `X-Snowprint: 1` header on every request) and
  is built to `public/ui/` (git-ignored), served by Swoole at `/ui/`. Charts follow the
  dataviz palette roles in `ui/src/styles.css` (accent = current period, grey = previous,
  one-hue bars, text in ink tokens, light + dark). `npm test` (Vitest) and `npm run build`
  must pass.
- `public/` served directly by Swoole's static handler (`server.swoole.static_handler_locations`
  = `/snow.js`, `/ui`): the `snow.js` tracker (≤ 1.5 KB gzipped, no cookies/localStorage,
  tested by `composer test:tracker`) and the built dashboard in `public/ui/`.
- `tests/` PHPUnit (namespace `dev\suvera\snowprint\tests\`). `bench/` ingest benchmark.
- `docker/Dockerfile`, `docker/entrypoint.sh` — the single app image. There is **no
  docker-compose**: users bring their own PostgreSQL (README "Install").
- `docs/PRD.md` — product requirements, architecture, Matomo gap register, milestones
  (local reference only, git-ignored; never commit it).
- `TASKS.md` — local, git-ignored implementation plan (SP-xxx tasks, open decisions D1–D6).
  Work tasks in order, mark them `[x]` when done, never commit the file.

## URL layout

Only three things live at the root: `/ui/` (the dashboard), `/snow.js` (the tracker, pasted
into customers' sites) and `/api/`. Every backend endpoint is under `/api/`:
`/api/event` (tracker), `/api/mcp` (MCP), `/api/ui/*` (dashboard API), `/api/admin/*`
(operator console), `/api/system/{health,info,prometheus}` (ops, actuator paths set in
`application.yml`), `/api/status`. Never add a backend route outside `/api/`.

## Configuration

- Values written as `$env.NAME || default` come from environment variables (property source
  `env`, `EnvPropertySource`). Plain values are literals.
- `server.port` is **7669** (S-N-O-W on a phone keypad). Change the published host port with
  `docker run -p` instead. Never use 8080.
- Database env vars: `SNOWPRINT_DB_URL`, `SNOWPRINT_DB_USER`, `SNOWPRINT_DB_PASSWORD`.
- `.env` is for `docker run --env-file .env` only (not loaded by `php bin/server.php`),
  values unquoted. It is git-ignored and holds secrets: never commit it, print it, or copy
  secrets into tracked files. Never point tests at the user's own databases.
- Keep `swoole.hook_flags: SWOOLE_HOOK_ALL` (without it PDO blocks the worker) and
  `datasource.connection.autoCommit: true`. Keep `worker_num × winter.coroutine.db.maxConnections`
  below Postgres `max_connections`.

## Migrations

- Add a new file `migrations/analytics/NNN-description.sql` (next number, zero-padded).
  Never edit a migration that has been committed; applied files are tracked in
  `winter_migrations`.
- Files run through psql (`useCli: true`, `ON_ERROR_STOP=1`); the first failure stops the run.
- Docker runs migrations on every start (`docker/entrypoint.sh`) before the server.
- Locally: `migrations/build-phar.sh` once, then `migrations/run.sh`
  (`WINTER_SQL_MIGRATIONS_PHAR` overrides the PHAR location).
- Don't run framework `vendor/bin/migrate.php`: it loads its own `vendor/autoload.php` and
  only works inside a Winter Boot checkout. That is why the PHAR exists.

## Build, Run, Verify

**Always test the product exactly as the README describes it.** `tests/e2e.sh` follows the
README "Install" section step by step: PostgreSQL in a throwaway container (published on a
host port, standing in for the user's own server), the role and database created with the
README's SQL, a `.env` like `.env.example`, then the README's `docker build` / `docker run`
command. A change is verified only when `tests/e2e.sh` prints `PASS`. If you change the
README install steps, change `tests/e2e.sh` to match, and the other way round. Never test
against the user's own PostgreSQL servers.

```bash
composer install
composer test                         # PHPUnit unit tests
composer test:tracker                 # snow.js (Node)
tests/integration.sh                  # report SQL against a throwaway PostgreSQL
tests/e2e.sh                          # documented install, end to end (needs Docker)
E2E_PG_IMAGE=postgres:15-alpine tests/e2e.sh   # oldest supported PostgreSQL
php bin/server.php                    # quick local boot: http://localhost:7669
curl localhost:7669/api/system/health            # {"status":"UP"}
```

- Use the throwaway PostgreSQL the test scripts start; never a developer's own database.
- Always live-boot (`php bin/server.php` or `tests/e2e.sh`) after adding beans,
  attributes or config: unit tests with hand-wired doubles do not prove the container wiring.
- Logs: stdout and `var/log/snowprint.log` (`var/` is git-ignored runtime output).

## Docker

- `docker/Dockerfile` builds everything from `php:8.5-cli-alpine`. Its `builder`/`runtime`
  stages are Winter Boot's base image (`winter-boot/build/docker/Dockerfile`) copied as-is
  and adjusted; every adjustment is marked `Snowprint change:` (Swoole with
  `--enable-swoole-pgsql`, + APCu, + pdo_mysql, − rdkafka). The required native
  `winter_boot` extension is built from `vendor/suvera/winter-boot/php-ext`, so it always
  matches the Winter Boot release in `composer.lock`. These are Snowprint's own needs, not
  framework issues: add PHP extensions here, with a `Snowprint change:` note.
- A `migrator` build stage compiles `winter-migrations-app.phar` from the Winter Boot
  package in `vendor/` (the version `composer.lock` pins).
- The container runs as user **`app`** (never root, never `www-data`); `/app/var` is
  owned by `app`.
- Health check: `wget http://127.0.0.1:7669/api/system/health`.
- `docker/build.sh` builds `<repo>:<winter.application.version>` and `:latest` (same
  conventions as winter-boot's `build/docker/build.sh`); `<repo>` is `SNOWPRINT_IMAGE`, else
  `image.repository` from `deploy/values.local.yaml`, else `suvera/snowprint`. `--push`
  publishes; for the public `suvera/snowprint` it refuses `-dev` versions and dirty trees.
  Agents never run `--push` without the user asking.

## Kubernetes (Helm)

- Chart in `deploy/helm` (`mode: single` = one Deployment, `split` = web / ingest / worker),
  migrations as a pre-install/pre-upgrade Job, pods with `SNOWPRINT_SKIP_MIGRATIONS=true`.
  Liveness `/api/status` (no DB), readiness `/api/system/health`.
- `deploy/deploy.sh` requires `deploy/values.local.yaml` (git-ignored, from
  `values.example.yaml`); never commit it. Guide: `docs/deploy-helm.md`, verified by
  `tests/helm-e2e.sh` (kind cluster with its own kubeconfig).
- **Never deploy to the user's own clusters or change their kubectl context.** Test with
  `tests/helm-e2e.sh` only.

## Winter Boot Rules (verified against framework source, 2.1.0)

- **No constructor params** on `#[Service]` / `#[Component]` / `#[Configuration]` classes.
  Inject with `#[Autowired]` on plain `private` fields (never `readonly`).
- Only autowire beans. Helpers (mappers, parsers, validators) are created manually.
- A stereotype class whose name ends in `Impl` is also registered under each interface it
  implements (`VisitorHasherImpl implements VisitorHasher`). Otherwise provide a `#[Bean]`
  method returning the interface. Multiple `*Impl` beans for one interface → use names
  (`#[Autowired('name')]`).
- `#[Value('${a.b}', default)]` injects config values, converted to the property type
  (2.1.6+: `"false"`/`"no"`/`"off"`/`"0"` are `false` for a `bool`); prefer it to
  `ApplicationContext::getProperty*()` reads. `ApplicationContext` is autowirable.
- App-level annotations on the starter: `#[EnableTransactionManagement]`, `#[EnableAsync]`,
  `#[EnableScheduling]` (from `dev\winterframework\stereotype\txn` / `...\task`).
- **Swoole safety:** never read `$_SERVER`, `$_COOKIE`, `$_GET`, `$_POST` or use `session_*`
  (shared worker process). Read everything from the injected `HttpRequest`; use
  `SessionManager` for dashboard logins. Never call blocking `sleep()`; use `\Co::sleep()`.
- `#[Scheduled]` work that must run once cluster-wide gets `#[Lockable(..., ttlSeconds: ...,
  lockManager: LockConfig::PG)]` (Winter Boot's `PdoLockManager`, table `winter_locks`).
  Put it on the service method that does the work, not on the `#[Scheduled]` method, and
  catch `LockException` in the job (busy = another pod runs it; skip quietly), otherwise
  the scheduler logs it as an error.
- Multi-statement writes go in a `#[Transactional]` public service method (the native AOP
  extension also advises `$this->method()` calls).
- `PdbcTemplate::queryForScalar()` throws when no row is found (like Spring's
  `queryForObject`); for optional rows use `queryForList(...)[0] ?? null`.
- Unserializing anything (e.g. Matomo blob archives) must use
  `unserialize($x, ['allowed_classes' => false])`.

### REST

- `#[RestController]` class; routes via `#[GetMapping(path: '/x')]`, `#[PostMapping]`, etc.
  or `#[RequestMapping(path, method: [RequestMethod::GET])]`.
- `#[PathVariable]`, `#[RequestParam(name, required, source: 'request'|'get'|'post'|'cookie'|'header')]`,
  `#[RequestBody]`; `HttpRequest` can be a handler parameter.
- Return `array` → JSON, `string` → text, `ResponseEntity` for status/headers
  (`ResponseEntity::ok()->withJson($x)`).
- Errors: throw `site\InvalidInput` (400) or `web\ui\UiError` (any status). Both are
  `HttpRestException`s, which Winter Boot answers as `{"status", "message", "error": <text>}`
  (the SPA reads `error`) and logs as one INFO line. No try/catch wrappers in handlers.
- The dashboard's CSRF header check (`X-Snowprint: 1` on non-GET `/api/ui`, `/api/share`)
  is `web/ui/UiHeaderInterceptor`; handlers don't repeat it.
- Interceptors: `HandlerInterceptor` registered in a
  `#[Configuration(name: 'webMvcConfigurer')]` class implementing `WebMvcConfigurer`.

### Logging

`use dev\winterframework\util\log\Wlf4p;` in the class, then `self::logInfo('msg')`
(`logDebug/Info/Notice/Warning/Error/Critical`, `logEx($e, $msg)`). Never log raw IPs,
user agents, API keys, or MCP tool arguments containing free text.

## Code Style

- `declare(strict_types=1);` in every PHP file; 4-space indent (`.editorconfig`).
- Namespaces and folders lowercase, classes `PascalCase`, matching the existing code.
- Comments explain *why*, sparingly. No speculative abstractions.
- Tests for behaviour changes; privacy and ingest code gets tests first.

## Git

- Single branch: **`master`**. No feature branches unless the user asks.
- Agents may commit; **agents must never push** (the user pushes).
- Commit as the user's global git identity (`suvera`); never override author/committer.
- Commit messages: at most 2 sentences, no conventional-commit prefixes (`feat:` etc.),
  no Claude/AI attribution or `Co-Authored-By` trailers.
- Never commit `.env`, `vendor/`, `var/`, `*.phar`, GeoIP `*.mmdb` files.

## Framework References

- Winter Boot source (exact version in use): `vendor/suvera/winter-boot/src`
- Winter Boot docs: https://suvera.github.io/winter-boot
- Winter Boot repositories: https://github.com/suvera/winter-boot (framework),
  https://github.com/suvera/winter-modules,
  https://github.com/suvera/winter-doctrine

## Winter Boot Version

Winter Boot comes from Packagist (`suvera/winter-boot: ^2.1.6`, exact version in
`composer.lock`); the native extension and the migrator PHAR are built from that package.
To try framework changes locally, add a temporary Composer path repository pointing at a
winter-boot checkout and do not commit it. `minimum-stability` stays `dev` because Winter
Boot requires `suvera/monolog-cascade: dev-master`.

Snowprint uses Winter Boot **2.1.6** (case-insensitive headers, coroutine-safe KV client,
dotted routes, `$env` port typing, worker hooks `#[OnWorkerStart]` / `#[OnWorkerStop]`,
`/api/system/health` answers 503 when DOWN, metrics recorded before the first scrape, compact JSON,
closed pool connections that really disconnect, typed `#[Value]`, 4xx logged at INFO and
`PdoLockManager` for distributed `#[Lockable]`; Snowprint caps the pool at 4). Report new framework issues in
`TASKS.md` under "Winter Boot upstream list".
