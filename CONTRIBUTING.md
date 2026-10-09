# Contributing to Snowprint

Thanks for your interest! Snowprint is pre-alpha, so expect things to move quickly.

## Getting started

1. Install PHP 8.5 with `swoole`, `pdo_pgsql`, `pcntl` and `posix`, plus Composer,
   and set up PostgreSQL 15+ as in the README's Install section.
2. `composer install`
3. `php bin/server.php` and open http://localhost:7669/api/system/health
4. `composer test` and `tests/e2e.sh` (needs Docker) before opening a pull request.

## Ground rules

- Work on a branch off `master` and open a pull request against `master`.
- Keep pull requests focused; one change per PR.
- Follow the existing code style (`.editorconfig`, 4-space indent, `declare(strict_types=1)`).
- Add or update tests for behaviour changes.
- Privacy is a feature: never store raw IPs, user agents or anything that identifies
  a person. Changes touching `src/privacy` or `src/ingest` get extra review.
- Processes are stateless. Keep shared state in Postgres, Redis or Kafka, never in a
  worker's memory beyond a flush window.

## Reporting bugs

Open an issue with steps to reproduce, the expected and actual behaviour, and your
Snowprint version and deployment setup. For security issues, see [SECURITY.md](SECURITY.md).
