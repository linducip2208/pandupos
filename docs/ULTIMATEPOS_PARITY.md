# UltimatePOS Behavioral Parity Matrix

Updated: 2026-09-17. UltimatePOS is a behavioral/workflow reference only. PanduPOS has no runtime dependency on it. This matrix does not authorize copying proprietary source, themes, controllers, models, views, or assets.

Legend: `D` done, `P` partial, `M` missing, `—` not applicable. `FEATURE COMPLETE` requires every relevant layer; `TESTED` requires the mandatory automated matrix; `STABLE` additionally requires all release gates.

## Core parity

Canonical readiness percentages live in `PRODUCTION_READINESS_SCORE.md`; this matrix supplies its row-level evidence.

| Reference feature | PanduPOS evidence | BE | UI | API | Perm | Tenant | Tests | Docs | Status | Gap |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| Product/category/brand/unit/variant | Responsive tenant CRUD, image, attributes, locations, safe archive, audit and API foundation | D | D | P | D | D | D | D | PARTIAL | Complete API variant/location update parity |
| Sub-units/conversion | Tenant-safe direct/inverse/chained conversion manager integrated with purchasing, POS and Sales Order | D | D | D | D | D | D | D | FEATURE COMPLETE | Sprint 2 gates passed locally; CI verification recorded per sprint |
| SKU/barcode/weighing/labels | Normal/variant barcode, configurable weight/price parser and Code 128 labels | D | D | D | D | D | D | D | FEATURE COMPLETE | A4 sheet and dedicated thermal layouts verified |
| Selling price groups | Retail/wholesale/member/VIP/branch/group/promo/date/quantity resolver with POS source trace | D | D | D | D | D | D | D | FEATURE COMPLETE | Create/edit/archive, audit and automatic POS customer-group selection verified |
| Combo/bundle | Relational components, audited tenant component manager and atomic sale/return/void stock | D | D | D | D | D | D | D | FEATURE COMPLETE | UI sync and lifecycle regression verified |
| Opening stock/location assignment | Generic ledger increase only | P | M | M | M | P | M | P | PARTIAL | Governed workflow/audit |
| Lot/batch/expiry/FEFO | Tenant batch workspace plus GRN batch create/select; lot, supplier, PO, receipt-line and ledger provenance; expiry visibility and controlled FEFO allocation | D | D | P | P | D | D | D | PARTIAL | Complete sales selector coverage, granular override permission, transfer preservation and end-to-end acceptance |
| Serial tracking | Tenant receive/register UI, provenance/history, controlled lifecycle, and atomic checkout/return/void/transfer ledger linkage | D | D | P | P | D | D | D | PARTIAL | POS serial selector, granular permission and end-to-end UI acceptance |
| Rack/bin | Optional zone/rack/shelf/bin master with audited lifecycle, stock visibility, and tenant/warehouse-scoped selectors for batch receiving, PO goods receipt, reservation, transfer, adjustment and count | D | D | D | D | D | D | D | DONE | `BatchExpirySerialTest`, `AdvancedStockTransferTest` and `InventoryWorkspaceUiTest` cover lifecycle, receipt trace and cross-tenant rejection |
| Ledger/WAC | Append-only ledger, pluggable strategy contract, WAC COGS/reversals/transfers and reconcile CLI | D | P | P | D | D | D | D | PARTIAL | Production concurrency/load evidence |
| Reservation | Audited idempotent reserve/release/partial/full consume/expire, ATP and Sales Order confirmation/delivery/cancellation integration | D | D | D | D | D | D | D | DONE | `StockLocationReservationTest`, `SalesOrderDeliveryTest` and `InventoryWorkspaceUiTest` cover tenant scope, ATP, duplicate consume safety, scheduler cleanup, audit and UI workflow |
| Transfer | Audited request/approve/ship/transit/partial-receive/receive/cancel state machine with preserved cost, selected-batch provenance, serials, and serial/nonserial rack/bin source/destination trace | D | D | D | D | D | D | D | DONE | `AdvancedStockTransferTest` covers multi-line, requester separation, partial receive, no early destination stock, batch, serial and serial/nonserial location trace |
| Stock adjustment | Multi-line draft/review/approve/post with requester/approver segregation, positive/negative posting, batch/rack-bin/serial validation, immutable ledger and audit | D | D | D | D | D | D | D | DONE | `InventoryControlTest` and `InventoryWorkspaceUiTest` acceptance/regressions |
| Stock count | Immutable snapshot-to-post with batch, serial and rack/bin scope; creator cannot self-approve | D | D | D | D | D | D | D | DONE | `InventoryControlTest` multi-line/batch/serial/rack-bin acceptance and `InventoryWorkspaceUiTest` tenant regression |
| Reconciliation | Read-only ledger/cache, batch, serial, rack/bin and reservation integrity audit; explicit-only balance-cache repair; platform health signal | D | D | D | D | D | D | D | DONE | `InventoryReconciliationService`, `InventoryControlTest`, and `PlatformAuthorizationTest`; external operational alert delivery remains an Operations gate |
| PO/partial receipt | PO plus configurable manager/owner approval, tenant workspace, and immutable receipt history with actor, audit and ledger reference | D | P | P | D | D | D | D | PARTIAL | Multi-line create/edit/cancel UX and configurable over-receive policy UI |
| Supplier invoice/payment/return | Separate tenant-scoped invoice, exact payment ledger, audited return/credit documents, and tenant workspace | D | P | D | D | D | D | D | PARTIAL | Multi-line return UX, supplier statement, approval segregation and accounting posting |
| Quotation/proforma/order/delivery | Audited quotation/proforma plus sales-order reservation and partial delivery with stock posting only on delivery | P | M | P | D | D | D | P | PARTIAL | Tenant UI, quotation-to-order conversion and posted invoice conversion |
| Invoice/payment | Atomic checkout, lines, split payments | P | P | P | P | D | P | P | PARTIAL | Posting/receivable state machine |
| Recurring sale | Not found | M | M | M | M | — | M | M | NOT STARTED | Templates/scheduler |
| Register/cash/Z | Cash-session schema only | P | M | M | M | P | M | M | PARTIAL | Open/count/close/movements/Z |
| Hold/resume | Not found | M | M | M | M | — | M | M | NOT STARTED | Non-posting held carts |
| Discount/tax | Line arithmetic and columns only | P | P | P | M | D | P | M | PARTIAL | Policy/groups/override |
| Receipt/ESC-POS | Portal invoice PDF only | P | P | M | P | D | P | P | PARTIAL | 58/80/A4 sales receipt/adapter |
| Void/return/refund | Void and partial stock return | P | M | P | P | D | P | P | PARTIAL | Refund separation/UI/full costing |
| Contact/group/credit | Unified contact basics and portal | P | P | P | P | D | P | P | PARTIAL | Address/group/terms/statement |
| Expense | No expense domain | M | M | M | M | — | M | M | NOT STARTED | Complete approved workflow |
| Reports/export | Small aggregates and generic export routes | P | P | P | P | D | P | P | PARTIAL | Required catalog/queued exports |
| Role dashboard | One dashboard, limited role filtering | P | P | M | P | D | P | P | PARTIAL | Cashier/warehouse/owner matrices |
| Import | Not found | M | M | M | M | — | M | M | NOT STARTED | Preview/errors/atomic imports |

## SaaS parity

| Feature | Evidence | BE | UI | Tests | Security | Status | Gap |
|---|---|:---:|:---:|:---:|:---:|---|---|
| Tenant administration | Lifecycle, plan change, impersonation | P | D | P | P | PARTIAL | Full safety matrix |
| Plans/modules/limits | Registry/manifests/entitlement/usage | P | P | P | P | PARTIAL | Universal limit enforcement |
| Subscription lifecycle | Service/events | P | P | P | P | PARTIAL | Automation/reminders |
| Billing | Invoice/items/transaction/webhook primitives | P | P | P | P | PARTIAL | Checkout/PDF/reconcile/sandbox |
| Coupons | Service/models/admin | P | P | M | P | PARTIAL | Rule matrix/invoice tests |
| Affiliate | Models/admin | P | P | M | M | PARTIAL | Portal/fraud/accrual/payout |
| Announcements | Models/admin send path | P | P | M | P | PARTIAL | Queue/dedupe/history |
| Domains/white label | Domain/settings/branding foundations | P | P | M | P | PARTIAL | Verification/complete rendering |
| Audit/health | Audit/health screens/services | P | D | P | P | PARTIAL | Operational signals/alerts |
| API/offline | v1 and device push/pull | P | — | P | P | PARTIAL | Token scopes/offline-sale rules |

## Addon release matrix

All addons below are **DEFERRED — POST V1** under [SCOPE_FREEZE.md](SCOPE_FREEZE.md). They are intentionally excluded from v1 production and commercial readiness; this is not a completion claim.

| Addon | Evidence | Status |
|---|---|---|
| Essentials / HRM | None | DEFERRED — POST V1 |
| Payroll | None | DEFERRED — POST V1 |
| Accounting | Registry/entitlement name only | DEFERRED — POST V1 |
| AssetManagement | None | DEFERRED — POST V1 |
| Cms | Public/blog primitives are not a tenant CMS module | DEFERRED — POST V1 |
| Connector | Generic provider/webhook primitives only | DEFERRED — POST V1 |
| Crm | Registry/entitlement name only | DEFERRED — POST V1 |
| Ecommerce | None | DEFERRED — POST V1 |
| FieldForce | None | DEFERRED — POST V1 |
| Manufacturing / MRP | None | DEFERRED — POST V1 |
| ProductCatalogue | Public marketing/pSEO is not a tenant catalogue | DEFERRED — POST V1 |
| Project | None | DEFERRED — POST V1 |
| Repair | None | DEFERRED — POST V1 |
| Spreadsheet | None | DEFERRED — POST V1 |
| Superadmin | Incomplete platform control plane | DEFERRED — POST V1 |
| WooCommerce | None | DEFERRED — POST V1 |
| AiAssistance | Generic configurable adapters only | DEFERRED — POST V1 |
| Hms | None | DEFERRED — POST V1 |
| InboxReport | None | DEFERRED — POST V1 |
| CustomDashboard | No tenant widget registry | DEFERRED — POST V1 |
| Gym | None | DEFERRED — POST V1 |
| ZatcaIntegrationKsa | None | DEFERRED — POST V1 |
| Cheque | None | DEFERRED — POST V1 |
| Restaurant | None | DEFERRED — POST V1 |

No deferred addon is currently FEATURE COMPLETE, TESTED, or STABLE.

## Current scores

**Core 39%, SaaS 27%, test readiness 60%, security readiness 44%, operations readiness 20%, production readiness 0%, commercial readiness 0%. Future addon parity is FROZEN / OUT OF SCOPE FOR V1.** These values are produced by `php artisan readiness:score`; see `PRODUCTION_READINESS_SCORE.md`, `GAP_TO_90.md`, and `SCOPE_FREEZE.md`. The stricter granular release ledger replaced earlier broad foundation gates; no capability was removed.
