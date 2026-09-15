# PanduPOS Enterprise — Product Requirements (V1 Core)

## 1. Vision
Modular business platform (POS + Inventory + Purchasing + Sales + SaaS control-plane) as modular monolith. V1 = foundation + core business that is production-grade. No Accounting/HRM/Manufacturing/Hotel/etc until core passes tests.

## 2. Scope V1 (Phases A–G core)
- Platform: Identity, Tenant, RBAC, Module Registry, Entitlement, Subscription, Billing foundation, Audit, Notification, API, Settings.
- Business: POS (register session, cart, discount/tax, split payment QRIS/cash/transfer/e-wallet/card, hold/resume, quotation/draft/final, receipt, return/refund/void, shortcuts, scanner+touch friendly), Inventory (products/categories/brands/units/variants, SKU/barcode, warehouses, immutable ledger, adjustment/transfer/count, reorder/low-stock, batch/lot/expiry/serial architecture), Purchasing (supplier, PR/PO/receipt/invoice/payment/return, statuses draft→closed, stock only on receiving), Sales (quotation/order/delivery/invoice/payment/return/credit-note, customer balance, unpaid/partial/paid/overpaid, unfulfilled/partial/fulfilled), Contacts unified (customer/supplier/both, credit limit, history), Pricing (retail/wholesale/group/location/promo, future price-lists), Tax (inclusive/exclusive, groups, per-product/location, ID-ready generic core), Payments (Manual/Cash now, Midtrans/Xendit/Duitku/Tripay/iPaymu adapters + BYOK encrypted), Reports (sales/purchase/stock/customer/profit approx, CSV/Excel/PDF, heavy via queue), API v1, offline-sync foundation (UUID, device, cursor, changelog, idempotency, tombstone), webhooks (HMAC, retry), white-label branding, notifications (db+email queued), queue/cache, health, CI/CD.

Out of V1: full Accounting double-entry, HRM/Payroll, Manufacturing MRP, Repair, Projects, Hotel/HMS, Restaurant full, Ecommerce/Marketplace, WhatsApp, AI (provider abstraction only), Mobile Flutter (backend sync only).

## 3. Tenancy & Org
- Shared DB + `tenant_id`. `tenants(id, uuid, name, slug, status[trial/active/past_due/suspended/cancelled/archived], timezone, currency, locale, logo, settings, trial_ends_at)`.
- `branches(tenant_id)`, `warehouses(tenant_id, branch_id)`, `registers(tenant_id, branch_id)`. User multi-branch via `memberships`.
- Isolation: `TenantContext/Resolver/Manager/Scope/Middleware`. No tenant-sensitive query without context. Test: A cannot read B.

## 4. Users & RBAC
- Roles: Platform Superadmin, Tenant Owner/Admin, Manager, Cashier, Warehouse, Purchasing, Sales, Accounting, Custom.
- Permissions: `pos.sale.create`, `pos.sale.void`, `inventory.view/adjust/transfer`, `purchase.create/approve`, `sales.view`, `reports.view`, `settings.manage`, etc. Server-side Gate/Policy, not menu-only. Spatie permission (v8) + tenant-aware teams + policies.

## 5. Modules & Entitlements
- `modules(id, slug, name, description, version, category, status, is_core, is_paid, provider, metadata)`, `tenant_modules(tenant_id, module_id, enabled, settings, enabled_at/disabled_at)`, states installed/enabled/disabled/incompatible/broken. Manifest `Modules/<X>/module.json` validated at boot/deploy. No arbitrary ZIP exec without verify.
- Entitlements: `pos.access`, `inventory.access`, `purchase.access`, `sales.access`, `pos.offline`, `pos.multi_register`, `inventory.multi_warehouse/batch/serial/transfer`, `manufacturing.bom/mrp`, `ai.analytics/forecasting`, etc. `EntitlementService::allowed(tenant, key)`, middleware `entitlement:xxx`.

## 6. SaaS Plans/Subscriptions/Usage/Billing
- `plans(id, name, slug, description, monthly_price, yearly_price, currency, trial_days, is_active/is_public/is_featured)`, `plan_entitlements(plan_id, entitlement, value)` boolean/numeric, unlimited=`null`.
- `subscriptions(tenant_id, plan_id, status[trialing/pending/active/past_due/grace_period/suspended/cancelled/expired], billing_cycle[monthly/quarterly/semiannual/yearly/lifetime], starts_at, trial_ends_at, current_period_start/end, cancelled_at, ends_at, metadata)` + history immutable + trial/upgrade/downgrade/cancel/renew/manual/grace.
- Usage: users/branches/warehouses/products/customers/suppliers/monthly invoices/transactions/storage/API/AI/WA. `assertCanCreate(tenant, key)`, dashboard `8/10`, alerts 80/90/100%.
- Billing separated: `billing_transactions/invoices/items + payment_attempts + payment_webhooks` idempotent. Coupons (%, fixed, plan-specific, max/per-user, valid range, first/recurring). Affiliate (link, cookie, registration/subscription conversion, commission, payout, anti self/duplicate/replay).

## 7. Superadmin
- Nav: Dashboard (tenants active/trial/suspended, MRR/ARR/new/expansion/churn, subs expiring/failed, charts revenue/MRR/tenants/plan/growth/churn, tables recent/expiring/failed/top), Tenants (all/trial/active/suspended/expired), Plans & Billing (plans/subs/transactions/invoices/coupons/gateways), Modules (registry/tenant/entitlements/matrix), Affiliate, Communication (announcements/email), Platform (branding/domains/API/webhooks/queue/audit/health), Settings.
- Impersonation: explicit permission + banner + audit + IP/UA + re-auth for dangerous.

## 8. Non-functional
- PHP 8.3+, Laravel 13, MySQL 8.4+, Redis (prod, file/cache dev), Queue+Scheduler, Sanctum API v1 (Passport only if audit says so — decision: Sanctum), Pest/PHPUnit, Docker optional, Ubuntu+Nginx+Supervisor, GH Actions (validate/install/lint/tests/audit).
- Security: SQLi/XSS/CSRF/IDOR/mass-assignment/upload/leakage/BAC/redirect/webhook-spoof/API-abuse; FormRequest+Policy+Gate+transactions+encrypted secrets+rate-limit+secure session; no debug trace prod.
- DB: bigint/UUID consistent, indexes tenant/warehouse/product/customer/supplier/status/ref/created_at, migrations only.
- UI: Blade + Livewire (Inertia only if clear win), Tailwind, modern > classic, sidebar logical, desktop backoffice + touch/keyboard/scanner POS, dark-ready, a11y.
- Search indexed DB (no ES unless needed). Import/export CSV/Excel with preview + queue for large. Health page without secrets.

## 9. Definition of Done
DB + validation + authz + logic + UI + error states + tests + pass + docs. Not done if only route/controller/page exists.

## 10. Test Minimum (must pass)
Tenant isolation, RBAC, entitlement, usage limit, product CRUD, stock movement/transfer, purchase receipt increases, sale decreases, return restores, split totals, duplicate pay idempotent, unauthorized void blocked, expired sub blocked, disabled module routes blocked.
