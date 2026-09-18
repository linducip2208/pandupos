# Current State Audit — PanduPOS Enterprise

Audit date: 2026-09-18

Branch: `main`

Verified CI commit: `9d301469c1dce8958a690cd13a1c196a4e1d70b3`

GitHub Actions run: [35208745723](https://github.com/linducip2208/pandupos/actions/runs/35208745723) — **success**

Canonical percentages are maintained in `PRODUCTION_READINESS_SCORE.md`; this audit mirrors them.

This replaces earlier optimistic status reports. A class, route, migration, screen, or document by itself does not make a feature complete. `DONE` means the repository contains the relevant workflow and automated evidence. `PARTIAL` means useful implementation exists but at least one required layer or invariant is missing. `MISSING` means no meaningful implementation was found. `BLOCKED` means an external decision or environment is required.

## Verified release-gate evidence

| Gate | Evidence | Result |
|---|---|---|
| CI environment | Testing APP_KEY, in-memory SQLite, array cache/session/mail, sync queue | DONE |
| Dependency validation | `composer validate --strict` in Actions | DONE |
| Reproducible install/build | Composer install, Node 22 `npm ci`, Vite build | DONE |
| Formatting/tests/audit | Pint, PHPUnit and Composer audit in Actions | DONE |
| Current CI | Run 35208745723 completed successfully for commit `9d30146` | DONE |
| Production/staging drill | No verified staging deploy, load test, restore drill, or live payment sandbox evidence | MISSING |

CI being green proves the checked pipeline, not product parity or production readiness.

## Repository inventory

- Four loadable manifests exist: Inventory, Purchasing, Sales, and POS. Their domain code still primarily lives in `app/`; manifests/providers alone do not establish module completeness.
- Core schema includes tenants, branches, warehouses, registers, catalog basics, an append-only stock ledger, purchases, invoices, payments, returns, and cash sessions.
- API v1 exposes catalog/product/contact, purchase receive, sale/void, two reports, subscription metadata, webhooks, and offline sync.
- Platform routes/screens exist for tenants, plans, subscriptions, billing, coupons, affiliates, announcements, modules, integrations, health, audit, and impersonation. Depth varies materially.
- Passing tests cover representative tenancy, billing webhook, subscription, entitlement, stock, purchase receipt, checkout, return, void, sync, portal, and relationship cases. They cannot cover requested workflows that do not yet exist.

## Layer-by-layer audit

| Area | Backend | UI | API | Permission | Tenant | Tests | Docs | Status |
|---|---|---|---|---|---|---|---|---|
| Tenant context/scoping | Present | Present | Present | Membership/platform gates | Representative isolation proven | Present | Present | DONE |
| Product master | Types, tracking, image/tax/reorder metadata, variants/attributes, warehouse assignments, safe archive | Responsive list/create/view/edit plus category/brand/unit management | Create/list/show/update/archive foundation | Product policy plus `products.manage` registry | Scoped and cross-tenant tested | 33 UI/isolation/archive assertions plus API basics | Present | PARTIAL |
| Unit conversion | Direct/inverse/chained tenant-safe conversion service | Responsive conversion manager plus purchase, POS and Sales Order selectors | List/create definitions plus purchase/Sales Order input | Product and transaction permissions | Tenant-scoped references and cross-tenant rejection tested | UI, inverse/chained, 2 carton→48 pcs, POS selector and 43 pcs balance | Present | DONE |
| SKU/normal barcode | Product and variant SKU/barcode plus Code 128 SVG generation | POS lookup and responsive A4/thermal label workspace | Product CRUD and label preview | Product policy | Scoped and cross-tenant label rejection | Product/variant and scannable label workflow | Present | DONE |
| Weighing barcode/labels | Configurable tenant parser for weight/price barcodes | Profile manager, parser preview, archive and print UI | Profile create/parse | Product policy | Tenant-filtered | Parser, UI, audit and isolation workflow | Present | DONE |
| Price lists | Scope/date/quantity/priority resolver with traceable source | Responsive create/edit/archive UI and POS customer/source display | List/create and resolver | Product/transaction permissions | Tenant-owned references | Resolver precedence, UI audit and POS customer group | Present | DONE |
| Bundle/combo | Relational components; checkout/return/void component stock | Tenant component manager | Component API | Product policy | Tenant validations | UI audit plus full stock lifecycle | Present | DONE |
| Batch/expiry/FEFO | Batch workspace, receipt and API create/select a batch; GRN line, stock ledger, supplier, PO and expiry provenance remain linked; deterministic FEFO allocation blocks expired stock | Tenant workspace, POS selector and receipt-line lot inputs | Audited receive/expiry endpoints | Product policy | Tenant references and batch/warehouse/variant ownership validated | FEFO tie-breaker, expiry prevention, GRN provenance, cross-tenant rejection, return/void restoration and transfer regressions | Present | DONE |
| Serial lifecycle | Audited receive/reserve/release/register UI with purchase/sale provenance and ledger history; sale checkout, return, void, and transfer receipt atomically keep serial status and exact ledger movement in sync | Tenant workspace, reserve UI, POS selector and transfer-line serial selector | Tenant-scoped receive/list/reserve/release plus serialized sale/transfer input | Product policy only | Tenant/invoice/serial/source-warehouse and route references validated | Duplicate sale, reservation idempotency, checkout/return/void/transfer lifecycle proof | Present | DONE |
| Rack/bin | Optional zone/rack/shelf/bin master, audited lifecycle, stock visibility and location-linked ledger including direct batch and PO goods receipt | Create/edit/deactivate workspace plus receipt selector | List/create and receipt inputs | Product policy | Tenant/warehouse references checked | Receipt trace, lifecycle audit and cross-tenant location rejection | Present | DONE |
| Inventory ledger/WAC | Append-only ledger, strategy contract, WAC COGS/reversal/transfer/original-cost return and cache reconcile | Inventory control workspace | Partial | Explicit inventory permissions | Checked | Costing matrix plus reconcile | Present | PARTIAL |
| Reservation | Idempotent reserve/release/partial/full consume/expire with available-to-promise and audit | Create/release inventory workspace plus Sales Order confirm/deliver/cancel actions | List/create/release/consume | `inventory.transfer` / `sales.create` | Tenant/warehouse/location/batch and route scope checked | ATP, duplicate consume, expiry scheduler, release-on-cancel, partial/full delivery and UI isolation | Present | DONE |
| Transfer | Audited request/approve/ship/transit/partial-receive/receive/cancel state machine; requester separation, cost, batch provenance, and serial/nonserial source/destination rack-bin ledger traces are preserved | Multi-line tenant request UI with batch, serial, and rack/bin selectors, state actions and receipt inputs | Full workflow endpoints | `inventory.transfer` | Tenant warehouses, variants, batches and active locations checked | Requester separation, partial receipt, no early destination stock, batch and serial/nonserial rack-bin trace regressions | Present | DONE |
| Stock adjustment | Multi-line `draft → reviewed → approved → posted`; submit ownership and requester/approver separation; positive/negative posting; batch/rack-bin/serial trace; immutable ledger | Multi-line workspace selectors and review/approve/post actions | Create/submit/approve/post API | `inventory.adjust` | Warehouse, variant, batch, serial, location and URL scope checked | Multi-line acceptance, self-approval, duplicate post, audit and cross-tenant regressions | Present | DONE |
| Stock count | Immutable snapshot/count/variance/review/approve/post workflow with warehouse or active rack/bin scope, batch lines and serial presence lines | Count workspace exposes location, batch and serial identifiers | Create/record/approve/post API | `inventory.adjust` | Warehouse/location/batch/serial and URL scope checked | Exact positive/negative variance, self-approval, duplicate-post and cross-tenant regressions | Present | DONE |
| Reconciliation | Read-only ledger/cache, batch, serial, rack/bin, reservation and duplicate-serial-reference anomaly audit; explicit `--fix` repairs only the derived balance cache | Platform health signal | CLI only | Platform operation | Tenant target option | Mutation-free anomaly and platform-health regressions | Present | DONE |
| Purchase/partial receipt | PO, configurable manager/owner approval, separate audited goods receipts and strict cumulative receiving | Purchasing workspace plus approval settings | List/create/receive with receipt history | Role/level checked | Checked | PO stock timing, threshold routing, 30/40/30 receipt, idempotent retry, over-receive rejection and workspace authorization | Present | PARTIAL |
| Supplier invoice/payment/return | Separate AP invoice, partial/full payment records, and received-minus-return controlled purchase returns | Purchasing workspace plus tenant-scoped printable AP document | Create invoice/payment/return | `purchase.create`/`purchase.approve` | Checked | Invoice totals, duplicate invoice/payment reference protection, invoice row locking, audit, document tenant isolation, overpayment and over-return | Present | PARTIAL |
| Purchase request | Approval foundation only | None | None | None | N/A | None | Target only | MISSING |
| Invoice/payment | Atomic checkout, lines, split payments | Basic POS/portal | Partial | Void gate | Checked | Partial | Present | PARTIAL |
| Quotation/proforma/order/delivery/recurring | Audited quotation/proforma, sales order reservation, and partial delivery | None | Quotation lifecycle plus order create/confirm/deliver/cancel | `sales.view`/`sales.create` | Checked | Non-posting, duplicate conversion, reservation and 2+4 partial delivery | Partial | PARTIAL |
| Register | Cash-session schema | No open/count/close flow | None | None | Model scoped | None | Target only | PARTIAL |
| Hold/cash movement/Z report | None | None | None | None | N/A | None | Reference only | MISSING |
| Discounts/tax | Line discount arithmetic and columns | Basic inputs | Partial | No override policy | Checked | Limited | Target only | PARTIAL |
| Receipts | Portal A4 invoice PDF only | PDF view | Download | Customer ownership | Checked | Portal test | Partial | PARTIAL |
| Contacts/credit | Basic unified contact and portal | Partial | List/create | Coarse | Scoped | Partial | Partial | PARTIAL |
| Reports/exports | Business, finance and operational aggregates; CSV/XLSX/PDF | Filter workspace | Final sales, purchases, inventory/COGS | Date, branch and warehouse | Tenant-validated filters | Limited to 500 operational rows | Server-side `reports.view` | VERIFIED |
| API/tokens | Versioned endpoints, Sanctum foundation | N/A | Partial coverage | Coarse; scopes/expiry absent | Partial tests | Partial | Present | PARTIAL |
| Offline sync | Device, cursor push/pull and idempotency foundations | None | Present | Auth/module | Checked | Present | Present | PARTIAL |
| Audit log | Service/model and platform mutations | Platform view | None | Platform gate | Tenant-aware | Limited | Present | PARTIAL |

## SaaS/control-plane audit

| Feature | Verified implementation | Status | Primary gap |
|---|---|---|---|
| Tenants/impersonation | Lifecycle actions, audit, impersonation session | PARTIAL | Complete dangerous-action lockout/test matrix |
| Plans/entitlements | Editable data and middleware/services | PARTIAL | Enforce all numeric limits on every write path |
| Subscriptions | Lifecycle service/events | PARTIAL | Scheduled transitions, grace/past-due and reminders |
| Billing | Invoice/items/transactions/webhook foundations | PARTIAL | Checkout, PDF/reconciliation and sandbox evidence |
| Payment integrations | Generic configurable format adapter | PARTIAL | No fully verified Indonesian sandbox flow |
| Coupons | Models/service/admin CRUD | PARTIAL | Complete rule matrix and invoice integration tests |
| Affiliate | Models/admin actions | PARTIAL | Portal, fraud, accrual/reversal/payout tests |
| Announcements | Model/audience/admin actions | PARTIAL | Queued delivery/dedupe/history tests |
| White label/domains | Branding/settings/domain foundations | PARTIAL | Ownership verification and complete application |
| Health | App/DB/cache/queue/storage/module checks | PARTIAL | Scheduler, backup, mail, payment, sync, disk alerts |

Accounting and CRM may appear as registry/entitlement names, but no complete module directory/domain exists. All requested addons fail the module completeness rule.

## Factual readiness snapshot

`DONE=1`, `PARTIAL=0.5`, `MISSING/BLOCKED=0`, weighted from the mandatory matrices.

| Dimension | Current | Why it is not 100% |
|---|---:|---|
| Core parity | 69% | Product, units, barcode, pricing, bundle, batch/expiry/FEFO, rack/bin, reservation, serial lifecycle, WAC, adjustment, stock count, reconciliation, transfer, receipt, supplier invoice/payment, purchasing safety, quotation, sales order, reports and exports have independently verified gates; purchasing UI/return, delivery/invoice/payment, POS, register, dashboard and API remain incomplete |
| SaaS parity | 27% | Tenant lifecycle and plan/entitlement foundations exist; limits, billing, gateways, affiliate, domains and renewal automation remain partial |
| Addon parity | 1% | Requested addons do not meet the completeness rule |
| Test readiness | 60% | Existing regression suite is green; browser E2E, full concurrency, security and recovery matrices remain incomplete |
| Security readiness | 44% | Foundation controls exist; full IDOR/RBAC/web/API/upload/secret audit matrix is incomplete |
| Operations readiness | 20% | CI/build is green; backup/restore/staging/deploy/alerting/rollback/load proof is missing |
| Production readiness | 0% | Upstream 90-point release gates are not satisfied |
| Commercial readiness | 0% | Production, license, install, support and recovery evidence remains incomplete |

## Release decision

**PanduPOS is PARTIAL. It is not parity complete, production ready, commercial ready, or eligible for `v1.0.0`.** See `GAP_TO_100.md` and `ULTIMATEPOS_PARITY.md`.
