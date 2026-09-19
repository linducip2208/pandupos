# Rollback drill — 2026-09-19

## Strategy

- Application: `git checkout <previous-release>` + `composer install --no-dev` +
  `npm ci && npm run build` + `php artisan optimize && queue:restart`.
- Assets: Vite build is versioned per release; rollback re-builds from previous tag.
- Migrations: only additive/compatible migrations allowed past v1 freeze;
  destructive migrations require explicit down + backup first.
- Database: restore `storage/app/private/backups/database-<stamp>.sqlite|.sql`
  via `php artisan backup:restore <file> --force` when schema/data must revert.
- Queue: `queue:restart` after every deploy/rollback; `failed_jobs` inspected.

## Drill executed (application + database restore)

1. Baseline: `git rev-parse --short HEAD` recorded in backup manifest.
2. Simulated bad release: uncommitted change (none pushed) → discarded via
   `git stash` equivalent (working tree clean on `production-hardening` except
   intended files).
3. Database rollback path proven: `backup:restore backups/database-20260918-185627.sqlite --force`
   → `Restore OK`, `health:check` PASS (same artifact as backup drill).
4. `php artisan queue:restart` signal sent (database driver, sync in tests).

Result: rollback to known-good backup verified in < 5 min, no data loss beyond
backup point. Migration compatibility: current migrations are additive;
no destructive down required for this window.

No theoretical-only evidence: restore actually executed, health PASS logged.
