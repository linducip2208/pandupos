# Support and troubleshooting guide — PanduPOS Enterprise

Target readership: support staff, operators and power users. Diagnostics are
command-first; every tool below is part of the product (no external agent
required).

## 1. Diagnostics console

| Symptom | Command / Check |
|---|---|
| Anything unhealthy | `php artisan health:check` (app, db, cache, queue, failed_jobs, storage, disk, backup freshness, mail, offline_sync) |
| DB connection drift | `php artisan db:monitor` |
| Queue back-pressure | `php artisan queue:monitor default`; inspect `failed_jobs` (must stay < 50) |
| Cron/scheduler drift | `php artisan schedule:list` vs documented schedule; `alert:check` reports scheduler heartbeat + backup stale |
| Backup missing/stale | `php artisan backup:database --retention=7`; freshness surfaced by `health:check backup_freshness` |
| Module health | `php artisan platform:module:health` |
| Readiness drift | `php artisan readiness:score` |
| Secrets/credentials leak check | `bash scripts/scan-secrets.sh` |

## 2. Common issues

- **"Login loops / session lost"**: verify `SESSION_DRIVER` + `SESSION_SECURE_COOKIE`
  with HTTPS; sessions regenerate on login by design.
- **Payments not settling**: check `payment_webhooks` + `billing_transactions`
  status; inbound webhooks are HMAC-signed with `PAYMENT_<GATEWAY>_SECRET` and
  idempotent on `gateway_ref`. Reconcile manually with `php artisan billing:reconcile`.
- **Offline device rejected**: devices report `423 device_revoked` when
  `devices.revoked_at` is set; see `docs/OFFLINE_SYNC.md` to re-provision.
- **Checkout stalls / oversell**: checkout is atomic; retries reuse the idempotency
  key — never resubmit with a new reference. `FailureRecoveryTest` documents the
  failure/retry matrix.
- **Tenant cannot access**: tenant blocks suspended/cancelled/archived unless a
  platform admin; membership is per-tenant via `memberships`.

## 3. Escalation paths

- Platform-level incidents → platform admin audit trail (`/platform/audit`) and
  `failed_jobs` first; escalate to code with `APP_DEBUG=false` stack traces captured
  in logs (never exposed to browsers).
- Data loss candidates → restore per [RESTORE_DRILL_REPORT.md](RESTORE_DRILL_REPORT.md);
  backups are append-only artifacts in `storage/app/private/backups`.

## Acceptance

Support diagnostics and the troubleshooting matrix above are verified against the
implemented command surface (`alert:check`, `health:check`, `db:monitor`,
`queue:monitor`, `billing:reconcile`, `backup:*`, `readiness:score`) run 2026-09-19.