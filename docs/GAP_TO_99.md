# Gap to 99 — PanduPOS Enterprise

Updated: 2026-09-17. Scores are calculated by `php artisan readiness:score`, using the evidence gates in `ReadinessScoreService`; this document is the execution backlog, not a score ledger.

## Current blockers to a 99/100 release

| Dimension | Score | Exact blockers |
|---|---:|---|
| Core | 52 | Complete purchasing UI/receipt/payment/return lifecycle; commercial sales/POS/register; reports/imports/dashboard |
| SaaS | 27 | Subscription/billing automation and verified sandbox; coupon/affiliate/announcements; domains/white-label; API/offline hardening |
| Future addon parity | FROZEN / OUT OF SCOPE | Deferred post-v1 modules are not release blockers; see `SCOPE_FREEZE.md` |
| Tests | 60 | Browser E2E, full concurrency and security regression matrices absent |
| Security | 44 | Full IDOR/API/upload/secret audit and sensitive-admin hardening absent |
| Operations | 20 | Restore, staging, rollback, monitoring, alerting and load-test evidence absent |
| Production | 0 | Security/operations/staging gates not verified |
| Commercial | 0 | License audit, installation/onboarding/support artifacts and release gates incomplete |

## Wave 1 — core, in required order

1. ~~Bundle UI and atomic component inventory regression.~~ DONE: audited tenant component manager and lifecycle tests.
2. ~~Batch/expiry/FEFO.~~ VERIFIED: `BatchWorkspaceTest`, `BatchExpirySerialTest` and `AdvancedStockTransferTest` cover audited tenant-scoped API/workspace receipt; supplier, PO and GRN provenance; POS selected-batch and automatic deterministic FEFO allocation; expired batch prevention; cross-tenant rejection; return/void restoration; and transfer preservation. The frozen v1 policy blocks expired stock; no unverified override path is exposed. ~~Serial lifecycle.~~ VERIFIED: tenant-scoped receive/reserve/release/sell/return/transfer/damage/history, POS selector, audit and idempotent reservation are covered by `SerialWorkspaceTest`, `BatchExpirySerialTest` and `AdvancedStockTransferTest`.
3. ~~Rack/bin transaction selectors.~~ VERIFIED: active tenant/warehouse-scoped selectors and stock trace cover batch receiving, PO goods receipt, reservation, transfer, adjustment and count. ~~Reservation lifecycle.~~ VERIFIED: tenant-scoped idempotent reserve/release/partial/full consume, ATP, scheduler expiry cleanup, audit and Sales Order confirmation/delivery/cancellation are covered by `StockLocationReservationTest`, `SalesOrderDeliveryTest` and `InventoryWorkspaceUiTest`.
4. ~~Stock adjustment, stock count and reconciliation lifecycles.~~ VERIFIED: adjustment draft/review/segregated approval/post; count snapshot/variance/segregated approval/post; and read-only ledger/balance/batch/serial/location/reservation anomaly checks in platform health. Transfer remains open as its own complete gate.
5. Purchase request, multi-line PO/GRN/AP/return/statement/PDF/export. Supplier Invoice is VERIFIED: tenant UI/API, supplier/PO validation, duplicate protection, audit and tenant-scoped printable output are covered by `PurchasingWorkspaceUiTest`, `SupplierDocumentTest` and `PurchasingSafetyTest`. Safety is VERIFIED: PO stock timing, 30/40/30 receipts, idempotent retry, over-receipt/invoice/payment/return protection, approval and tenant isolation.
6. Quotation → order → reservation → delivery → invoice → payment → credit note/return/refund lifecycle.
7. Register, cash movement, hold/resume, discount/tax/credit, receipt layouts and printer contract.
8. Expense, report catalog/filter/export, import preview/commit and role dashboard.

Each item remains PARTIAL until backend, UI, API where relevant, permission, tenant isolation, audit, tests and documentation are all evidenced.

## Non-negotiable later waves

- SaaS: at least one real Indonesian payment sandbox E2E; no hard-coded provider dependency.
- Deferred addons are post-v1 work and must not be started during stabilization.
- Security/operations: backup restore, staging/rollback, monitoring/alerting and load reports must be actual drills, not statements.
