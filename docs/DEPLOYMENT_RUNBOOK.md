# Deployment runbook — PanduPOS (repeatable)

## Prerequisites

Server: PHP 8.3+, MySQL 8 / PG 15, Node 20+, Nginx, Supervisor, cron, Redis
recommended for cache/queue prod. See `DEPLOYMENT.md` + `deploy/`.

## Repeatable process

```bash
git fetch --tags
git checkout <release-tag-or-sha>
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan platform:module:health
php artisan readiness:score
php artisan backup:database --retention=7
php artisan health:check
```

Adaptations to PanduPOS architecture:

- `storage:link` after first install (`public` disk for product images).
- `schedule:list` must show backup 01:30, expiry, notifications, reminders.
- Never run demo seeder in prod; set `PLATFORM_ADMIN_PASSWORD` env.
- `APP_ENV=production`, `APP_DEBUG=false`.

## Staging deployment executed (2026-09-19)

Local equivalent executed (sqlite standing in for MySQL):

- `composer validate --strict` → PASS (CI also runs).
- `./vendor/bin/pint --test` → pending full run (see final verification).
- `npm ci && npm run build` → covered by CI; local node build skipped (no dist change).
- `php artisan migrate --force` → no pending migrations on `database.sqlite`.
- `php artisan platform:module:health` → PASS.
- `php artisan backup:database` → PASS (811008 bytes).
- `php artisan health:check` → 10/10 PASS.

Evidence: backup artifacts in `storage/app/private/backups/`, health output
logged in `docs/RESTORE_DRILL_REPORT.md` + `docs/STAGING.md`.
