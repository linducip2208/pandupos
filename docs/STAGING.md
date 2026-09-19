# Staging acceptance — production-like

## Configuration (required)

- PHP 8.3 (prod version, matches `composer.json ^8.3` + CI `setup-php 8.3`).
- MySQL 8.x prod (sqlite used locally for drill; `backup:database` supports both).
- Redis for `CACHE_STORE`/`QUEUE_CONNECTION` in prod (`config/cache.php`,
  `config/queue.php` redis connections present; local default `database`).
- Queue worker: `deploy/supervisor.conf` (`queue:work --sleep=3 --tries=3`, 2 procs).
- Scheduler: cron `* * * * * cd /var/www/pandupos-enterprise && php artisan schedule:run`
  (backup 01:30, expiry, notifications, reminders, IndexNow).
- HTTPS: `deploy/nginx.conf` with TLS termination at LB/proxy (add cert block per host).
- `APP_ENV=staging|production`, `APP_DEBUG=false`, persistent `storage/` volume.

## Acceptance run (2026-09-19, local staging-equivalent)

```text
php artisan config:clear
php artisan backup:database --retention=7     # PASS, 811008 bytes
php artisan backup:restore <artifact> --force # PASS, Restore OK
php artisan health:check                      # 10/10 PASS
php artisan alert:check                       # scheduler-heartbeat NOTE only
php artisan platform:module:health            # PASS (existing gate)
bash scripts/scan-secrets.sh                  # PASS
composer audit                                # no advisories
php artisan test --filter=<security+concurrency+recovery>  # 50+ PASS
```

`APP_DEBUG=false` verified via error-shape test
(`ApiSecurityTest::test_consistent_error_shape_no_trace_leak` — no stack trace).

## Gaps noted

- Redis/MySQL versions in prod must match operator docs; local drill used
  sqlite+database cache/queue (same code paths, different driver).
- HTTPS cert provisioning is operator-side (LB), not in repo.
