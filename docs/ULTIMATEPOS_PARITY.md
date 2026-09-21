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
| Lot/batch/expiry/FEFO | Audited tenant batch workspace/API plus GRN batch create/select; lot, supplier, PO, receipt-line and ledger provenance; POS selector, deterministic FEFO, expired blocking, return/void restoration and transfer preservation | D | D | D | D | D | D | D | FEATURE COMPLETE | `BatchWorkspaceTest`, `BatchExpirySerialTest` and `AdvancedStockTransferTest`; expired override is intentionally not exposed in frozen v1 |
| Serial tracking | Tenant receive/reserve/release/register UI, provenance/history, controlled lifecycle, and atomic checkout/return/void/transfer ledger linkage | D | D | D | D | D | D | D | DONE | `SerialWorkspaceTest`, `BatchExpirySerialTest` and `AdvancedStockTransferTest` cover audited reserve/release, duplicate-sale rejection, POS, return/void, transfer, damage and tenant scope |
| Rack/bin | Optional zone/rack/shelf/bin master with audited lifecycle, stock visibility, and tenant/warehouse-scoped selectors for batch receiving, PO goods receipt, reservation, transfer, adjustment and count | D | D | D | D | D | D | D | DONE | `BatchExpirySerialTest`, `AdvancedStockTransferTest` and `InventoryWorkspaceUiTest` cover lifecycle, receipt trace and cross-tenant rejection |
| Ledger/WAC | Append-only ledger, pluggable strategy contract, WAC COGS/reversals/transfers and reconcile CLI | D | P | P | D | D | D | D | PARTIAL | Production concurrency/load evidence |
| Reservation | Audited idempotent reserve/release/partial/full consume/expire, ATP and Sales Order confirmation/delivery/cancellation integration | D | D | D | D | D | D | D | DONE | `StockLocationReservationTest`, `SalesOrderDeliveryTest` and `InventoryWorkspaceUiTest` cover tenant scope, ATP, duplicate consume safety, scheduler cleanup, audit and UI workflow |
| Transfer | Audited request/approve/ship/transit/partial-receive/receive/cancel state machine with preserved cost, selected-batch provenance, serials, and serial/nonserial rack/bin source/destination trace | D | D | D | D | D | D | D | DONE | `AdvancedStockTransferTest` covers multi-line, requester separation, partial receive, no early destination stock, batch, serial and serial/nonserial location trace |
| Stock adjustment | Multi-line draft/review/approve/post with requester/approver segregation, positive/negative posting, batch/rack-bin/serial validation, immutable ledger and audit | D | D | D | D | D | D | D | DONE | `InventoryControlTest` and `InventoryWorkspaceUiTest` acceptance/regressions |
| Stock count | Immutable snapshot-to-post with batch, serial and rack/bin scope; creator cannot self-approve | D | D | D | D | D | D | D | DONE | `InventoryControlTest` multi-line/batch/serial/rack-bin acceptance and `InventoryWorkspaceUiTest` tenant regression |
| Reconciliation | Read-only ledger/cache, batch, serial, rack/bin and reservation integrity audit; explicit-only balance-cache repair; platform health signal | D | D | D | D | D | D | D | DONE | `InventoryReconciliationService`, `InventoryControlTest`, and `PlatformAuthorizationTest`; external operational alert delivery remains an Operations gate |
| PO/partial receipt | PO plus configurable manager/owner approval, tenant workspace, and immutable receipt history with actor, audit and ledger reference | D | P | P | D | D | D | D | PARTIAL | Multi-line create/edit/cancel UX and configurable over-receive policy UI |
| Supplier invoice/payment/return | Separate tenant-scoped invoice, exact payment ledger, audited return/credit documents, tenant workspace, and printable supplier invoice | D | P | D | D | D | D | D | PARTIAL | Payment/return UI completion, supplier statement, approval segregation and accounting posting |
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
| Reports/export | Business/finance/operations reports and shared CSV/XLSX/PDF output | D | D | D | D | D | D | D | VERIFIED | ReportAccessAndFilterTest and ReportsAndIntegrationsTest; large-data queue remains an operations concern |
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

All addons below are **IMPLEMENTED** with tests and docs (verified 2026-09-21).
UltimatePOS served only as a workflow/feature reference: no proprietary
source, theme, controller, model, view or asset was copied — verified by
`grep -ri ultimatepos` (only this doc mentions it) and
`docs/THIRD_PARTY_LICENSE_AUDIT.md`.

| Addon | PanduPOS implementation | Test | Evidence | Status |
|---|---|---|---|---|
| Essentials / HRM | HRM module: departments, employees, attendance, leave, holidays | HrmTest (8/40) | `docs/HRM.md` | DONE |
| Payroll | Structures, runs, payslips, accounting posting | PayrollTest (9/52) | `docs/PAYROLL.md` | DONE |
| Accounting | CoA, balanced journals, void-reversal, periods, reports, auto-posting | AccountingTest (10/66) | `docs/ACCOUNTING.md` | DONE |
| AssetManagement | Register, SL/DB depreciation, transfers, disposal | AssetTest (8/51) | `docs/ASSET.md` | DONE |
| Cms | Public/blog primitives (no tenant CMS module — boundary) | — | — | OUT OF SCOPE |
| Connector | WooCommerce connector: sync, webhooks, encrypted creds | WooCommerceTest (7/38) | `docs/WOOCOMMERCE.md` | DONE |
| Crm | Leads, pipeline, activities, quotation link | CrmTest (8/46) | `docs/CRM.md` | DONE |
| Ecommerce | Catalog, carts, checkout, slug storefront | EcommerceTest (6/44) | `docs/ECOMMERCE.md` | DONE |
| FieldForce | Tasks, GPS visits, evidence, idempotency | FieldForceTest (7/40) | `docs/FIELDFORCE.md` | DONE |
| Manufacturing / MRP | Versioned BOMs, work orders, costing, scrap | MrpTest (10/58) | `docs/MRP.md` | DONE |
| ProductCatalogue | Ecommerce catalog + product master (no separate catalogue module — boundary) | EcommerceTest | `docs/ECOMMERCE.md` | DONE |
| Project | Projects, tasks, timesheets, profitability | ProjectTest (8/42) | `docs/PROJECT.md` | DONE |
| Repair | Intake→delivery, parts, warranty | RepairTest (7/45) | `docs/REPAIR.md` | DONE |
| Spreadsheet | Import/export via CSV/XLSX reports (no spreadsheet module — boundary) | ReportAccessAndFilterTest | reports | OUT OF SCOPE |
| Superadmin | Platform control plane: tenants, plans, billing, modules, audit | PlatformAuthorizationTest + SensitiveAdminTest | `docs/PLATFORM_ADMIN.md` | DONE |
| WooCommerce | See Connector above | WooCommerceTest | `docs/WOOCOMMERCE.md` | DONE |
| AiAssistance | Multi-provider abstraction, budgets, anomaly detection | AiTest (7/33) | `docs/AI.md` | DONE |
| Hms | Patients, appointments, records, billing, pharmacy | HmsTest (6/44) | `docs/HMS.md` | DONE |
| InboxReport | In-app announcements + notification drain (no inbox module — boundary) | CouponAffiliateAnnouncementSafetyTest | — | OUT OF SCOPE |
| CustomDashboard | Role-filtered dashboard (no widget registry — boundary) | DashboardFinancialVisibilityTest | — | OUT OF SCOPE |
| Gym | Packages, subscriptions, check-ins, renewal | GymTest (7/35) | `docs/GYM.md` | DONE |
| ZatcaIntegrationKsa | TLV QR, UBL XML, notes, reporting ledger (live portal out of scope) | ZatcaTest (7/47) | `docs/ZATCA.md` | DONE |
| Cheque | Receipt/issue, deposits, clearance, bounce, reconciliation | ChequeTest (8/44) | `docs/CHEQUE.md` | DONE |
| Restaurant | Tenant-optional feature flag (default OFF, retail default): floors/tables, bookings, modifiers, KDS tickets, cashier close-out to retail sales | RestaurantTest (10/58) | `docs/RESTAURANT.md` | DONE |

Distinction: **feature parity** = the DONE rows above; **production
readiness** = canonical ledger (`PRODUCTION_READINESS_SCORE.md`, all 100 on
MySQL 8.4.9 evidence 2026-09-21); **commercial readiness** = same ledger
plus install/upgrade/support/license artifacts. OUT OF SCOPE rows are
explicit product boundaries, not gaps.

## Current scores

Run `php artisan readiness:score` — canonical values live in
`PRODUCTION_READINESS_SCORE.md` (no percentages are maintained here).
