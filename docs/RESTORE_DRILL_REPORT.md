# Restore drill report — 2026-09-19

## Backup used

`storage/app/private/backups/database-20260918-185627.sqlite` (811008 bytes)
+ `database-20260918-185627.manifest.json` (sqlite, uploads_count=1).

## Environment

Same host, file-based sqlite `database/database.sqlite` (production-like for
drill; MySQL path in `backup:restore` uses `mysql < dump`).

## Steps

1. `php artisan backup:database --retention=7` → artifact above.
2. `php artisan backup:restore backups/database-20260918-185627.sqlite --force`
   → `Restore OK: 5+ tables visible`.
3. `php artisan health:check` → all PASS (app/db/cache/queue/failed_jobs/
   storage/disk/backup_freshness/mail/offline_sync).

## Times (Asia/Jakarta)

- Start: 2026-09-19 01:56:27 (backup), finish backup 01:56:28.
- Restore start ~01:57, finish same minute.
- Total drill < 5 minutes, no manual SQL edits.

## Verification results

- `health:check` database PASS, `failed_jobs` count 0.
- `storage/app/private/backups` count 2 (artifact + manifest).
- Failures: none. `:memory:` restore correctly refused
  (`Refusing to restore into :memory:` covered by FailureRecoveryTest).

## Conclusion

Restore gate satisfied: real backup restored into live sqlite file with health
PASS. For MySQL prod, same command streams dump via `mysql` client.
