# Deployment guide — PanduPOS Enterprise

Target readership: operators deploying the software or commercial buyers running
a self-hosted instance. Reference the executable checklist in
[DEPLOYMENT_RUNBOOK.md](DEPLOYMENT_RUNBOOK.md) and the process templates in
`deploy/` (`nginx.conf`, `supervisor.conf`) — this guide explains the decisions.

## 1. Anatomy

| Component | Requirement |
|---|---|
| PHP | 8.3+ (project tested on 8.3.30) |
| Database | MySQL 8 / PostgreSQL 15 (SQLite accepted for small installs & drills) |
| Node | 20+ (build-time only) |
| Cache/Queue | Redis recommended for production (`CACHE_STORE`, `QUEUE_CONNECTION`) |
| Web | Nginx + PHP-FPM (TLS at LB/proxy; `deploy/nginx.conf`) |
| Workers | Supervisor: `queue:work --sleep=3 --tries=3` (2 procs, `deploy/supervisor.conf`) |
| Scheduler | `* * * * * cd <app> && php artisan schedule:run` |

## 2. Install

Follow [FRESH_INSTALL_DRILL.md](FRESH_INSTALL_DRILL.md). Essentials:

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, generated `APP_KEY`,
  real `DB_*`, `*_CACHE_STORE`, `QUEUE_CONNECTION`; never commit secrets.
- `composer install --no-dev --optimize-autoloader`
- `npm ci && npm run build`
- `php artisan migrate --force` then `php artisan storage:link`
- Optional platform seeding + credentials for the platform admin (`PLATFORM_ADMIN_PASSWORD`); never run demo seeds in prod.

## 3. Operate

- Scheduler list (`php artisan schedule:list`) must contain: overdue escalation
  (hourly), pending notifications (5 min), reminders (08:00), database backup
  (01:30), expiry reservation cleanup (5 min), IndexNow (02:45).
- Queue worker + scheduled `backup:database` + offsite sync per
  [BACKUP_RUNBOOK.md](BACKUP_RUNBOOK.md) and [RESTORE_DRILL_REPORT.md](RESTORE_DRILL_REPORT.md).
- Monitor with `php artisan health:check`, `db:monitor`, `queue:monitor`,
  `alert:check`; see [PERFORMANCE_REPORT.md](PERFORMANCE_REPORT.md) for measured
  calibration and [SUPPORT.md](SUPPORT.md) for troubleshooting.

## 4. Release/rollback

- Deploy: tag + `DEPLOYMENT_RUNBOOK.md` steps.
- Rollback: `ROLLBACK_DRILL.md` / `UPGRADE_DRILL.md` (application checkout +
  `backup:restore` for schema/data revert; migrations must be additive).

## 5. White label & custom domains

Domain ownership verification and safe host resolution are built in (`TenantDomainService`);
see `docs/BILLING.md`, `docs/PLATFORM_ADMIN.md`. Payment gateway webhook secrets are set
via `PAYMENT_*_SECRET` env; inbound callbacks are HMAC-signed.