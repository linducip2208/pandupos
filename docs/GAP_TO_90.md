# Gap to 90 — PanduPOS Enterprise v1

Updated: 2026-09-17. This is the v1 stabilization backlog. Deferred addons are excluded under [SCOPE_FREEZE.md](SCOPE_FREEZE.md); no score is increased until its canonical gate has reproducible evidence.

## Verified baseline

| Dimension | Score | Evidence still required for 90 |
|---|---:|---|
| Core | 32 | Stable current inventory, purchasing, sales/POS, report/dashboard flows and stock/money correctness proof |
| SaaS | 28 | Lifecycle, entitlement, billing/webhook, coupon/affiliate, domain/API/offline hardening |
| Tests | 70 | Browser critical paths, concurrency and complete security regressions |
| Security | 58 | IDOR/RBAC/API/upload/secret matrix and resolution of findings |
| Operations | 27 | Actual restore, staging, rollback, monitoring/alerts and load evidence |
| Production | 26 | Upstream core/SaaS/security/operations gates |
| Commercial | 20 | License audit, fresh install, support diagnostics and recovery/deployment evidence |

## Core milestones

The Core scorer preserves a 100-point total while separating meaningful release outcomes. The 32-point verified catalog/bundle baseline is unchanged. The next milestones are evidence outcomes, not manual targets: Batch/Expiry/FEFO (4), Serial (4), Rack/Bin (2), Reservation (3), Transfer (2), Adjustment/Count (2), and Reconciliation (1) can bring Core to 50 only when each full gate passes. Purchasing (18), current sales/POS/return correctness (22), and reports/dashboard (10) then provide the remaining independently verifiable route to 90+.

## Wave 1: current inventory

1. Batch/expiry selectors in current purchase and sales/POS flows; permissioned expired override only if the existing architecture permits it.
2. Serial lifecycle document integration: reserve, sell, return, transfer, damage, with no manual ledger divergence.
3. Rack/bin selectors and stock visibility in current receiving, transfer and POS paths.
4. Reservation consume UI/sales-order lifecycle and scheduler cleanup. Cleanup is now scheduled and audited; UI/document consume evidence remains.
5. Transfer requester/approver separation and multi-line current UI are now covered by server-side validation, audit and regression tests. Location/batch selectors and complete tenant UI acceptance remain open, so the transfer score gate remains unverified.
6. Adjustment/count multi-line current UI and post-immutability evidence.
7. Reconciliation visibility in existing system health.

## Release rule

An item is not closed by a route, migration, screen, or documentation alone. It needs applicable backend, UI, validation, authorization, tenant isolation, audit, automated tests, and release evidence.
