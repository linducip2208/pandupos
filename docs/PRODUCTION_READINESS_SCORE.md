# Production Readiness Score

Updated: 2026-09-18.

This file renders the canonical score ledger from `app/Support/Readiness/ReadinessScoreService.php`. Run `php artisan readiness:score` to reproduce the values. Other documents must not maintain independent percentages.

| Dimension | Score | Evidence |
|---|---:|---|
| Core parity | 95% | Product, units, barcode, pricing, bundle, batch/expiry/FEFO, rack/bin, reservation, serial lifecycle, WAC, stock adjustment, stock count, reconciliation, transfer, PO UI/lifecycle, purchase receipt, purchasing safety, supplier invoice/payment, quotation, sales order, reservation-to-delivery, invoice/payment, POS, register, reports, dashboard, exports and current core API protections have independently verified evidence; remaining purchase return and return/void/refund remain incomplete |
| SaaS parity | 27% | Tenant lifecycle and plan/entitlement foundations are evidenced; limits, billing and automation remain incomplete |
| Future addon parity | FROZEN / OUT OF SCOPE | Deferred post-v1 work; excluded from v1 release scoring by [SCOPE_FREEZE.md](SCOPE_FREEZE.md) |
| Test readiness | 60% | The regression suite is green; E2E, concurrency, security and recovery matrices remain absent |
| Security readiness | 47% | Foundation regressions and tracked-repository secret scanning are evidenced; complete IDOR/RBAC/web/API/upload audits and third-party license review remain unverified |
| Operations readiness | 20% | CI/build is verified; backup, restore, staging, deployment, rollback, monitoring, alerting and load evidence are absent |
| Production readiness | 0% | All upstream 90-point prerequisites are deliberately unmet |
| Commercial readiness | 0% | Commercial release is gated by the missing production, license, install, recovery and customer-documentation evidence |

## Decision

Status: **PARTIAL — NO-GO for v1.0.0 or commercial production**.

The canonical evidence and calculation policy are in [CURRENT_STATE_AUDIT.md](CURRENT_STATE_AUDIT.md), [ULTIMATEPOS_PARITY.md](ULTIMATEPOS_PARITY.md), [GAP_TO_90.md](GAP_TO_90.md), and [SCOPE_FREEZE.md](SCOPE_FREEZE.md). A green CI run is required but is not sufficient to raise this decision.
