# Production Readiness Score

Updated: 2026-09-18.

This file renders the canonical score ledger from `app/Support/Readiness/ReadinessScoreService.php`. Run `php artisan readiness:score` to reproduce the values. Other documents must not maintain independent percentages.

| Dimension | Score | Evidence |
|---|---:|---|
| Core parity | 43% | Product, units, barcode, pricing, bundle, rack/bin, reservation, serial lifecycle, WAC, stock adjustment, stock count, reconciliation and transfer have independently verified evidence; batch, purchasing, sales/POS and reporting remain incomplete |
| SaaS parity | 27% | Tenant lifecycle and plan/entitlement foundations are evidenced; limits, billing and automation remain incomplete |
| Future addon parity | FROZEN / OUT OF SCOPE | Deferred post-v1 work; excluded from v1 release scoring by [SCOPE_FREEZE.md](SCOPE_FREEZE.md) |
| Test readiness | 60% | The regression suite is green; E2E, concurrency, security and recovery matrices remain absent |
| Security readiness | 44% | Foundation regressions are evidenced; complete IDOR/RBAC/web/API/upload/secret audits remain absent |
| Operations readiness | 20% | CI/build is verified; backup, restore, staging, deployment, rollback, monitoring, alerting and load evidence are absent |
| Production readiness | 0% | All upstream 90-point prerequisites are deliberately unmet |
| Commercial readiness | 0% | Commercial release is gated by the missing production, license, install, recovery and customer-documentation evidence |

## Decision

Status: **PARTIAL — NO-GO for v1.0.0 or commercial production**.

The canonical evidence and calculation policy are in [CURRENT_STATE_AUDIT.md](CURRENT_STATE_AUDIT.md), [ULTIMATEPOS_PARITY.md](ULTIMATEPOS_PARITY.md), [GAP_TO_90.md](GAP_TO_90.md), and [SCOPE_FREEZE.md](SCOPE_FREEZE.md). A green CI run is required but is not sufficient to raise this decision.
