# UltimatePOS Behavioral Parity Matrix

Updated: 2026-09-17. UltimatePOS is a behavioral/workflow reference only. PanduPOS has no runtime dependency on it. This matrix does not authorize copying proprietary source, themes, controllers, models, views, or assets.

Legend: `D` done, `P` partial, `M` missing, `—` not applicable. `FEATURE COMPLETE` requires every relevant layer; `TESTED` requires the mandatory automated matrix; `STABLE` additionally requires all release gates.

## Core parity

| Reference feature | PanduPOS evidence | BE | UI | API | Perm | Tenant | Tests | Docs | Status | Gap |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| Product/category/brand/unit/variant | Types, tracking/tax/image metadata, variant attributes, locations and API | P | M | P | P | D | P | P | PARTIAL | Complete tenant CRUD UI and update/archive flow |
| Sub-units/conversion | Tenant-safe direct/inverse/chained service and API definitions | D | M | D | P | D | D | D | PARTIAL | Tenant UI and purchase/POS unit selectors |
| SKU/barcode/weighing/labels | Normal/variant barcode and configurable weight/price parser | P | M | D | P | D | D | D | PARTIAL | Label templates and print UI |
| Selling price groups | Global/branch/group/promo/date/quantity/priority resolver | D | M | D | P | D | D | D | PARTIAL | Tenant management UI and edit audit |
| Combo/bundle | Relational components and atomic sale/return/void stock | D | M | D | P | D | D | D | PARTIAL | Tenant management UI |
| Opening stock/location assignment | Generic ledger increase only | P | M | M | M | P | M | P | PARTIAL | Governed workflow/audit |
| Lot/batch/expiry/FEFO | Lot provenance, expiry report API and controlled FEFO allocation | D | M | P | P | D | D | D | PARTIAL | UI, granular permission/audit and purchase/sale selectors |
| Serial tracking | Controlled lifecycle, ledger linkage and duplicate-sale prevention | D | M | P | P | D | D | D | PARTIAL | UI, granular permission/audit and return/transfer integration |
| Rack/bin | Optional zone/rack/shelf/bin master with location-linked movements | D | D | D | D | D | D | D | PARTIAL | Edit/deactivate workflow and location selectors in transactions |
| Ledger/WAC | Append-only ledger, pluggable strategy contract, WAC COGS/reversals/transfers and reconcile CLI | D | P | P | D | D | D | D | PARTIAL | Production concurrency/load evidence |
| Reservation | Audited idempotent reserve/release/consume/expire and available-to-promise | D | P | D | D | D | D | D | PARTIAL | Sales-order/held-cart integration, consume UI and scheduler |
| Transfer | Audited state machine with approval, transit, partial receipt and preserved cost | D | D | D | D | D | D | D | PARTIAL | Requester/approver segregation and multi-line creation UI |
| Adjustment/count/reconcile | Audited approve/post adjustment, count snapshot-to-post, read-only reconcile CLI | D | D | D | D | D | D | D | PARTIAL | Requester/approver segregation and multi-line creation UI |
| PO/partial receipt | PO plus configurable manager/owner approval, tenant workspace, and immutable receipt history with actor, audit and ledger reference | D | P | P | D | D | D | D | PARTIAL | Multi-line create/edit/cancel UX and configurable over-receive policy UI |
| Supplier invoice/payment/return | Separate tenant-scoped invoice, exact payment ledger, audited return/credit documents, and tenant workspace | D | P | D | D | D | D | D | PARTIAL | Multi-line return UX, supplier statement, approval segregation and accounting posting |
| Quotation/proforma/order/delivery | Final invoice foundation only | M | M | M | M | — | M | M | NOT STARTED | Immutable documents/conversions |
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

| Addon | Evidence | Status |
|---|---|---|
| Essentials / HRM | None | NOT STARTED |
| Payroll | None | NOT STARTED |
| Accounting | Registry/entitlement name only | NOT STARTED |
| AssetManagement | None | NOT STARTED |
| Cms | Public/blog primitives are not a tenant CMS module | NOT STARTED |
| Connector | Generic provider/webhook primitives only | PARTIAL |
| Crm | Registry/entitlement name only | NOT STARTED |
| Ecommerce | None | NOT STARTED |
| FieldForce | None | NOT STARTED |
| Manufacturing / MRP | None | NOT STARTED |
| ProductCatalogue | Public marketing/pSEO is not a tenant catalogue | NOT STARTED |
| Project | None | NOT STARTED |
| Repair | None | NOT STARTED |
| Spreadsheet | None | NOT STARTED |
| Superadmin | Incomplete platform control plane | PARTIAL |
| WooCommerce | None | NOT STARTED |
| AiAssistance | Generic configurable adapters only | PARTIAL |
| Hms | None | NOT STARTED |
| InboxReport | None | NOT STARTED |
| CustomDashboard | No tenant widget registry | NOT STARTED |
| Gym | None | NOT STARTED |
| ZatcaIntegrationKsa | None | NOT STARTED |
| Cheque | None | NOT STARTED |
| Restaurant | None | NOT STARTED |

No addon is currently FEATURE COMPLETE, TESTED, or STABLE.

## Current scores

**Core 30%, SaaS 46%, Addons 1%, test readiness 37%, security readiness 58%, operations readiness 27%.** Move these only when repository evidence and release gates move.
