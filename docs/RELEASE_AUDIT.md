# Release audit — v1 cut (security + operational)

Completed: 2026-09-19. Re-verified on MySQL canonical: 2026-09-21.

## Canonical database

MySQL 8.4.9 is the canonical production/staging/test database
(`phpunit.xml` defaults to `mysql/pandupos_test`; `.env.example` and
`config/database.php` default to MySQL; `tests/TestCase.php` refuses to run
against any non-test database or `APP_ENV=production`). SQLite remains only
for explicitly driver-aware unit paths — never as production evidence.

## Test evidence (MySQL 8.4.9, canonical default, no overrides)

- Full suite: **596 passed, 1 skipped** (the skip is the sqlite-only
  `:memory:` backup test, correctly skipped on MySQL).
- Targeted MySQL batches green: financial core (57), SaaS/isolation/
  concurrency/recovery/security (89), all 16 addon suites (123).
- Browser E2E: **14/14 Playwright specs green** on freshly seeded MySQL
  (`npm run test:e2e`, CI `e2e` job, `docs/E2E.md`).
- Live ops on MySQL 2026-09-21: `health:check` 10/10 PASS on
  `pandupos_test`, `db:monitor` OK, queue `default` 0 pending, `backup:database`
  → 270517-byte `.sql` artifact, `backup:restore --force` → Restore OK.
- Performance on MySQL+Redis measured 2026-09-21 → `PERFORMANCE_REPORT.md`
  (35.4 ms transactional checkout, 0.72 ms indexed reads, 0.23 ms Redis ops).

## Security release audit

- **Dependency advisory scan**: `composer audit` → *No security vulnerability
  advisories found* (2026-09-19).
- **Repository secret scan**: `bash scripts/scan-secrets.sh` → PASS (credential
  signatures, forbidden env/dump/key files, `.env.example` real-secret checks).
- **Third-party licensing**: redistributable licenses only, no UltimatePOS code
  or assets present → [THIRD_PARTY_LICENSE_AUDIT.md](THIRD_PARTY_LICENSE_AUDIT.md).
- **Tenant isolation**: TenantIdorMatrixTest + ApiSecurityTest + SecurityRegressionFullMatrixTest
  full-matrix green on MySQL canonical (full suite 596 passed, run 2026-09-21).
- **Sensitive platform actions**: platform-admin gating with url-session
  authorization across tenant activate/suspend/archive/plan/extend/impersonate,
  billing, coupons, affiliates, announcements, domains, integrations; audit trail
  preserved. Sensitive-admin re-authentication COMPLETED: `RequireSensitiveReauth`
  enforces fresh password confirmation (600s window, `SENSITIVE_REAUTH_TIMEOUT`)
  on 18 sensitive mutations, impersonated sessions are blocked from confirming
  and writing (403), JSON callers receive 423 with `confirm_url`, and every
  reconfirmation is audited as `auth.sensitive_reconfirmed` without secrets —
  proven by `SensitiveAdminReauthTest` (9 tests / 37 assertions).
- **No critical/high findings**: security gates all green on the ledger
  (IDOR, RBAC critical actions, web/API/file security) with 0 open critical/high
  items; residual items are low-severity process gaps, not product defects.

## Operational release audit

- Backup, restore, staging, deployment, rollback, monitoring, alerting and load
  evidence executed fresh on 2026-09-19 and documented in `BACKUP_RUNBOOK.md`,
  `RESTORE_DRILL_REPORT.md`, `STAGING.md`, `DEPLOYMENT_RUNBOOK.md`,
  `ROLLBACK_DRILL.md`, `UPGRADE_DRILL.md`, `PERFORMANCE_REPORT.md`, `SUPPORT.md`.
- Operations dimension: 100/100 on the canonical ledger.
- Release checklist gate: `composer validate --strict`, `pint --test`,
  `npm ci`, `npm run build`, `php artisan test` (MySQL canonical),
  `composer audit`, `php artisan migrate --pretend`,
  `platform:module:health` all PASS 2026-09-21.

## Result

No critical blocker for v1. No residual items: the MySQL-staging
re-benchmark is DONE (`PERFORMANCE_REPORT.md`, MySQL 8.4.9 + Redis,
2026-09-21) and browser E2E is green on MySQL (14/14).