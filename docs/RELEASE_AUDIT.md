# Release audit — v1 cut (security + operational)

Completed: 2026-09-19.

## Security release audit

- **Dependency advisory scan**: `composer audit` → *No security vulnerability
  advisories found* (2026-09-19).
- **Repository secret scan**: `bash scripts/scan-secrets.sh` → PASS (credential
  signatures, forbidden env/dump/key files, `.env.example` real-secret checks).
- **Third-party licensing**: redistributable licenses only, no UltimatePOS code
  or assets present → [THIRD_PARTY_LICENSE_AUDIT.md](THIRD_PARTY_LICENSE_AUDIT.md).
- **Tenant isolation**: TenantIdorMatrixTest + ApiSecurityTest + SecurityRegressionFullMatrixTest
  full-matrix green (462 tests / 1686 assertions overall, run 2026-09-19).
- **Sensitive platform actions**: platform-admin gating with url-session
  authorization across tenant activate/suspend/archive/plan/extend/impersonate,
  billing, coupons, affiliates, announcements, domains, integrations; audit trail
  preserved. Known residual: re-authentication for the highest-sensitivity actions
  is tracked (Security dimension carries it as a non-blocking gap at 92→95).
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
  `php artisan test`, `composer audit`, `php artisan migrate --pretend`,
  `platform:module:health` all PASS 2026-09-19.

## Result

No critical blocker for v1. Residual non-blocking items tracked separately:
browser-E2E (HTTP-kernel level covers critical paths), sensitive-admin
re-authentication, and MySQL-staging re-benchmark before the SLO is committed.