# Implementation Roadmap — PanduPOS Enterprise

## Order (strict)
### PHASE A — Platform Core (current)
- Tenancy (tenants/branches/warehouses/registers, TenantContext/Resolver/Manager/Scope/Middleware)
- Auth (Sanctum + session, memberships multi-branch)
- RBAC (spatie + policies, seed Owner/Admin/Manager/Cashier/Warehouse/Purchasing/Sales)
- Module Engine (registry/manager/resolver/checker + artisan commands + manifests POS/Inventory/Purchasing/Sales)
- Entitlement (`EntitlementService`, middleware, plan_entitlements seed)
- Tests: tenant isolation, RBAC, entitlement, module-disabled block
- Docs: audit + requirements + architecture + ERD + module system (done)

### PHASE B — Superadmin + SaaS
- Plans, Subscriptions (+events, scheduler renew, grace), Usage (`UsageLimitService`, meters, dashboard, 80/90/100 alerts), Billing foundation (transactions/invoices/attempts/webhooks idempotent), Coupons, Affiliate, Communicator, Platform dashboard (MRR/ARR/churn), Impersonation + audit.

### PHASE C — Products/Inventory/Warehouse
- Products/categories/brands/units/variants, SKU service, warehouses, `stock_movements` ledger, adjustment/transfer/count, reorder/low-stock, batch/lot/expiry/serial arch.

### PHASE D — Customers/Suppliers/Purchasing
- Unified contacts, PR/PO/receipt/invoice/payment/return, stock += on receipt only.

### PHASE E — POS/Sales/Payments/Returns
- Register session, cart/discount/tax, split (cash/transfer/QRIS/e-wallet/card), hold/resume, quotation/draft/final, receipt, return/refund/void, idempotency, scanner/touch UX.

### PHASE F — Reports/Audit/Notifications/API
- Sales/purchase/stock/customer/profit, CSV/Excel/PDF + queue, audit log, notif (db+email), API v1 (me/tenant/modules/entitlements/usage/subscription + products/inventory/customers/sales/purchase) with pagination/Resources/rate-limit/idempotency.

### PHASE G — Sync/Webhooks/White-label
- Offline sync (UUID/device/cursor/changelog/tombstone, push/pull), outgoing webhooks (HMAC/retry), branding service, custom domains (no DNS automation until stable).

### Future (blocked until core passes tests)
Accounting, CRM, HRM/Payroll, Manufacturing, Repair, Project, Hotel, Restaurant, Ecommerce/Marketplace, Asset, WhatsApp, AI, School, Rental, Logistics.

## Commits
`feat(tenancy): ...`, `feat(modules): ...`, `feat(subscription): ...`, `feat(inventory): ...`, `feat(pos): ...`, `test(tenancy): ...`. No giant commits.

## Exit Criteria (production ready)
Critical tests pass, isolation proven, payments idempotent, ledger balances, authz tested, security audit no criticals. UltimatePOS removable with zero runtime effect.
