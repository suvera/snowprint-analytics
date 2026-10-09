# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
