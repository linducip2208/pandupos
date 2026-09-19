# Production Readiness Score

Updated: 2026-09-20.

This file renders the canonical score ledger from `app/Support/Readiness/ReadinessScoreService.php`. Run `php artisan readiness:score` to reproduce the values. Other documents must not maintain independent percentages.

| Dimension | Score | Evidence |
|---|---:|---|
| Core parity | 100% | All core gates have independently verified evidence, including the staged purchase-return lifecycle and the return/void/refund reversal workflow; see `php artisan readiness:score` |
| SaaS parity | 27% | Tenant lifecycle and plan/entitlement foundations are evidenced; limits, billing and automation remain incomplete |
| Future addon parity | FROZEN / OUT OF SCOPE | Deferred post-v1 work; excluded from v1 release scoring by [SCOPE_FREEZE.md](SCOPE_FREEZE.md) |
| Test readiness | 90% | Regression suite green and gates flipped for concurrency (invoice numbering, refund double-submit, coupon atomic redeem), failure/recovery (queue retry, gateway timeout, backup/restore drill) and regression matrices; browser E2E remains HTTP-kernel-level coverage only |
| Security readiness | 92% | Complete IDOR matrix, RBAC critical-action matrix, web security audit (XSS, mass-assignment, rate limits, upload MIME/dimension), API security audit (scoped tokens, expiry, revocation, rate limit, replay), and file-security audit (sniffing, size, private storage, owner-only download) are now verified; third-party license review and sensitive-admin re-authentication remain open |
| Operations readiness | 20% | CI/build is verified; backup, restore, staging, deployment, rollback, monitoring, alerting and load evidence are absent |
| Production readiness | 0% | All upstream 90-point prerequisites are deliberately unmet |
| Commercial readiness | 0% | Commercial release is gated by the missing production, license, install, recovery and customer-documentation evidence |

## Decision

Status: **PARTIAL — NO-GO for v1.0.0 or commercial production**.

The canonical evidence and calculation policy are in [CURRENT_STATE_AUDIT.md](CURRENT_STATE_AUDIT.md), [ULTIMATEPOS_PARITY.md](ULTIMATEPOS_PARITY.md), [GAP_TO_90.md](GAP_TO_90.md), and [SCOPE_FREEZE.md](SCOPE_FREEZE.md). A green CI run is required but is not sufficient to raise this decision.
