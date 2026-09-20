# Fresh-install drill — 2026-09-19

Goal: prove a brand-new environment can install `pandupos-enterprise` with zero
inherited state and reach healthy operation.

## Method

1. `robocopy` the release tree to an empty directory (`.git`, `node_modules`,
   `vendor`, logs, and the dev database excluded).
2. `composer install` → clean (86 packages, funding note only).
3. Remove the copied dev sqlite; create an empty `database/database.sqlite`.
4. `Copy-Item .env.example .env` + `php artisan key:generate`.
5. `php artisan migrate --force` → all 61 migrations ran from an EMPTY database
   (first-ever schema build).
6. `php artisan storage:link` → `public/storage` connected.
7. `php artisan migrate:status` → 61 `[Ran]`.
8. `php artisan health:check` → 10/10 PASS on the fresh database.

## Result

| Check | Result |
|---|---|
| composer install | PASS |
| key:generate | PASS |
| migrate --force (empty DB) | PASS (61 migrations) |
| storage:link | PASS |
| health:check | PASS 10/10 (app/db/cache/queue/failed_jobs/storage/disk/backup_freshness/mail/offline_sync) |

No demo seeder runs, no inherited rows, no manual SQL. The fresh-install path is
verified exactly as a buyer/operator would experience it.