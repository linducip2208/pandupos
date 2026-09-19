# Backup runbook — PanduPOS

## What is backed up

- Database: `php artisan backup:database` → `storage/app/private/backups/database-Ymd-His.{sqlite,sql}`
  (sqlite file copy / `mysqldump --single-transaction`).
- Uploads manifest: `*.manifest.json` beside artifact lists `uploads_count`,
  50-file sample, git SHA, sizes (restoration checklist).
- Config required for restoration: `.env` values (DB_*, APP_KEY, QUEUE_*, CACHE_*,
  MAIL_*, filesystem disks) — stored outside repo (secret manager), never committed.

## Frequency / retention / destination / encryption

- Frequency: daily `01:30` via `routes/console.php` (`backup:database`), plus
  pre-release manual run.
- Retention: `--retention=7` days prune (default 7, configurable per run).
- Destination: `local` disk `storage/app/private/backups`; production mounts
  persistent volume and syncs offsite (S3/MinIO) — operator step in
  `docs/DEPLOYMENT_RUNBOOK.md`.
- Encryption: volume-level + offsite SSE; application artifact is raw dump —
  restrict `storage/` permissions to `www-data`, never serve publicly.

## Verification

Command verifies artifact exists and size > 0, prints byte count, exits non-zero
otherwise. `php artisan health:check` reports `backup_freshness.ok` only if a
backup exists within 30h.

## Real run evidence (2026-09-19 Asia/Jakarta)

```text
$ php artisan backup:database --retention=7
Backup tersimpan: backups/database-20260918-185627.sqlite (811008 bytes, 1 uploads tracked)
```

Artifacts:

```text
storage/app/private/backups/database-20260918-185627.sqlite (811008 bytes)
storage/app/private/backups/database-20260918-185627.manifest.json
```

Manifest excerpt: `db_driver=sqlite`, `uploads_count=1`, `git_sha=<short HEAD>`.
`php artisan health:check` → `PASS backup_freshness {"latest_age_hours":0.00,...}`.
