# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Version 0.2.0.
- Sign-in lockout, share-link password attempts and the MCP rate limit are counted in
  PostgreSQL (new table `rate_limits`, migration 010), so the limits hold across workers
  and pods instead of per worker.
- Multi-step writes (daily rollups with their watermark, site deletion, API key creation,
  user access changes, accepting an invite, rollup rebuilds) run in one transaction with
  Winter Boot's `#[Transactional]`.
- The API key cache uses Winter Boot's `#[Cacheable]`; settings are injected with `#[Value]`.
- `/api/system/info` reports the name and version.
- Winter Boot 2.1.6. Rollups and the retention sweep take a PostgreSQL lock (`#[Lockable]`
  with `PdoLockManager`, table `winter_locks`, migration 011), so with several worker pods
  only one runs them at a time; the operator console answers 409 while one is running.
- API errors use Winter Boot's error responses: `{"timestamp", "status", "message",
  "error"}` (the `error` text is unchanged), and client errors log one INFO line.
- Bad `limit` values on `/api/ui/stats/breakdown` answer 400 instead of being read as 0.

### Fixed
- Expired dashboard and share-link sessions are deleted hourly; `winter_sessions` grew
  forever.
- Connection-count guidance: a container runs 6 database-using processes (24 connections
  at the default), not 7 (28); web and ingest pods run 4.

### Removed
- The tag-triggered GitHub Actions image release. Images are pushed with
  `docker/build.sh --push` (docs/releasing.md).

## [0.1.0] - 2026-10-09

First release (pre-alpha). Everything below is new.

### Added
- Tracking: `snow.js` (no cookies, under 1.5 KB gzipped, SPA aware, custom events),
  `POST /api/event` with batched writes, daily-salted visitor hash, bot filtering, Do Not
  Track, optional GeoIP from a MaxMind-format file, device detection.
- Sessions assigned in PostgreSQL by a worker job; monthly event partitions.
- Dashboard (React + TypeScript, served at `/ui/`): overview, sources, pages, locations,
  devices, events and goals, realtime, filters, period comparison, CSV export, dark mode.
- Daily rollups per site timezone, and raw-event retention per site (default 90 days).
- Users with per-site roles (viewer, admin) invited by single-use links; site settings and
  deletion; getting-started notice for sites without data.
- Public share links: read-only dashboards without an account, optional password.
- Built-in MCP server at `/api/mcp` with API keys, analytics tools, resources and prompts.
- Operator console `bin/console.sh` (sites, goals, API keys, share links, jobs).
- Roles `all`, `web`, `ingest`, `worker`; Helm chart with single and split modes.
- Prometheus metrics, health and status endpoints.
- Tests: unit, integration, UI, tracker, `tests/e2e.sh` (documented install) and
  `tests/helm-e2e.sh` (Helm guide on kind); GitHub Actions CI and a tag-triggered image release.
