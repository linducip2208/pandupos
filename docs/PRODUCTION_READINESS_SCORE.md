# Production Readiness Score

Updated: 2026-09-17.

This file renders the canonical score ledger from `app/Support/Readiness/ReadinessScoreService.php`. Run `php artisan readiness:score` to reproduce the values. Other documents must not maintain independent percentages.

| Dimension | Score | Evidence |
|---|---:|---|
| Core parity | 32% | Catalog, pricing and bundle workflows are evidenced; advanced inventory, purchasing, sales/POS and reporting remain incomplete |
| SaaS parity | 28% | Tenant/plan/entitlement foundations exist; billing and automation remain incomplete |
| Future addon parity | FROZEN / OUT OF SCOPE | Deferred post-v1 work; excluded from v1 release scoring by [SCOPE_FREEZE.md](SCOPE_FREEZE.md) |
| Test readiness | 70% | Current unit/feature suite is green; E2E, concurrency and full security matrices remain absent |
| Security readiness | 58% | Representative isolation/auth/webhook controls; full audit absent |
| Operations readiness | 27% | CI green; staging/load/restore/monitoring evidence absent |
| Production readiness | 26% | Weighted mandatory release-gate completion |
| Commercial readiness | 20% | Product, operational and redistribution gates remain open |

## Decision

Status: **PARTIAL — NO-GO for v1.0.0 or commercial production**.

The canonical evidence and calculation policy are in [CURRENT_STATE_AUDIT.md](CURRENT_STATE_AUDIT.md), [ULTIMATEPOS_PARITY.md](ULTIMATEPOS_PARITY.md), [GAP_TO_99.md](GAP_TO_99.md), and [SCOPE_FREEZE.md](SCOPE_FREEZE.md). A green CI run is required but is not sufficient to raise this decision.
