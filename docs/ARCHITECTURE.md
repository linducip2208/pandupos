# Architecture — PanduPOS Enterprise (Modular Monolith, Laravel 13)

## 1. Principles
- Domain-oriented modular monolith (not microservices for V1). Clear boundaries. Cross-module via Services/Events/DTO/Contracts/Policies, never direct internal access.
- Tenancy-first: every business row carries `tenant_id`; `TenantScope` + `TenantMiddleware` + `TenantContext` enforce. No query without tenant context.
- Ledger-first inventory, immutable invoices, idempotent payments. `DB::transaction()` for sale/payment/stock/receipt/return/refund.
- SaaS control-plane separated from tenant business plane. Zero dependency on UltimatePOS runtime.

## 2. Layout
```
app/
  Domain/            # pure domain logic (Tenant, Identity, Catalog, Inventory, Purchase, Sales, Billing)
  Services/          # application services (TenantProvisioning, Entitlement, Usage, Branding, AI gateway)
  Support/           # TenantContext, TenantScope, BelongsToTenant, Helpers, ValueObjects
  Http/Controllers/Api/V1 + Web (thin, FormRequest + Policy)
  Models/            # Eloquent (Tenant, Branch, Warehouse, Register, Module, Plan, Subscription, etc.)
  Policies/          # authorization server-side
Modules/
  Platform/          # Identity, Tenant, RBAC, Module Registry, Entitlement, Subscription, Billing, Audit, Notif, API, Settings
  POS/ Inventory/ Purchasing/ Sales/   # V1 business (future: Accounting, CRM, HRM, Manufacturing, Repair, Project, Hotel, Restaurant, Ecommerce, AI, WhatsApp)
  <Future>/          # per module.json manifest
database/migrations/ routes/ tests/ docs/
```

## 3. Core Platform
- Identity: `users` + Sanctum tokens + `memberships(user_id, tenant_id, branch_ids JSON, role)`. Platform Superadmin via `is_platform_admin` + Gate `platform-admin`.
- Tenant: `Tenant(id, uuid, name, slug, status, timezone, currency, locale, logo, settings JSON, trial_ends_at)`. `TenantResolver` (subdomain/header/session/API token), `TenantManager` (set/current), `TenantScope` (global scope `where tenant_id`), `TenantMiddleware` (resolve + set + 403 if suspended).
- RBAC: Spatie permission + custom `branch_ids` ABAC. Permissions `pos.sale.create` etc. Policies per aggregate. Seeder: Owner/Admin/Manager/Cashier/Warehouse/Purchasing/Sales.
- Module Registry: `modules` + `tenant_modules` + `ModuleRegistry` (boot validate manifests), `ModuleManager` (enable/disable with dependency check), `DependencyResolver`, `CompatibilityChecker`. Artisan `platform:module:*`.
- Entitlement: `EntitlementService::allowed(tenantId, key)` reads `plan_entitlements` via active subscription + overrides + cache (tenant-keyed). Middleware `entitlement:xxx`. `plan_entitlements` boolean/numeric, `null`=unlimited.
- Subscription/Billing: `plans`, `subscriptions`, `subscription_events`, `billing_*`, `coupons`, `affiliates*`. Scheduler renew + reminders [7,3,1,0,-3] + grace read-only.
- Audit: `audit_logs(tenant_id, actor_id, action, subject_type/id, before/after JSON, ip, user_agent, request_id, created_at)` for login/role/sub/plan/payment/stock/sale-void/refund/suspend/module/impersonation. Never passwords/secrets.
- Notification: db+email queued, events low-stock/expiry/payment/suspension. Future WA/push adapters.
- API: `/api/v1` versioned, Resources, pagination, validation, rate-limit, tenant isolation, idempotency (`Idempotency-Key` header → `idempotency_keys` table) for POST pay/sale.
- Settings: `tenant_settings(key, value, type)` versioned + branch override. `BrandingService` (no hardcoded name). `tenant_domains` prepared (no DNS automation until mapping works).
- Cache: Redis (prod) tenant-keyed `tenant:{id}:entitlements`, `plan:{id}`, `module-registry`, dashboard aggregates. Queue: email/webhook/reports/import/export/notif/AI with retry/backoff.
- AI: `AIProviderInterface` (OpenAI/Anthropic/Gemini/DeepSeek/Qwen/Ollama future), never auto-mutate finance/inventory without approval.
- Webhooks out: `webhook_endpoints(tenant_id, url, secret, events)` + `webhook_deliveries` HMAC retry. Events: customer/product/sale/invoice/payment/stock.
- Offline sync foundation: client UUID, device registration, sync cursor, `server_change_log`, tombstone `deleted_at`, `last_changed_at`. `POST /sync/push`, `GET /sync/pull` (V1 backend only, Flutter later).

## 4. Business Modules (V1)
- POS: `cash_sessions` (open/close, opening/closing, counts), `sales_invoices` immutable + `sales_lines` + `payments + allocations`. Reservations on cart, commit on pay. Split totals validated. Hold/resume via `carts`.
- Inventory: `products`, `product_variants`, `warehouses`, `stock_movements(tenant, warehouse, variant, ref_type/id, movement_type in/out/adjust/transfer, qty, unit_cost, occurred_at)` immutable, `stock_on_hand` view, `transfer_orders` ship/receive, `lots/serials` architecture, reorder/low-stock events.
- Purchasing: `suppliers(contacts role)`, `purchase_orders`, `goods_receipts` (stock += on receipt only), `purchase_invoices`, `supplier_payments`, `purchase_returns`. Statuses draft/submitted/approved/ordered/partial/received/cancelled.
- Sales: `quotes`, `sales_orders`, `deliveries`, `invoices`, `payments`, `returns/credit_notes`. Payment unpaid/partial/paid/overpaid, fulfillment unfulfilled/partial/fulfilled.
- Contacts: unified `contacts(type customer/supplier/both, company/name/email/phone/WA/address/tax/credit/opening)` + history.
- Pricing/Tax/Payments: price_lists, tax engine inclusive/exclusive + groups, `PaymentGatewayInterface` (Manual/Cash now).

## 5. Request Lifecycle
`Request → TenantMiddleware (resolve tenant) → Auth Sanctum/session → RBAC Gate/Policy → Entitlement middleware → Module enabled check → FormRequest validation → Service (transaction) → Events → Resources/Blade → Audit`.

## 6. Security & DB Rules
- FormRequest + Policy + Gate + transactions + encrypted secrets + rate-limit. No debug trace prod. Indexes tenant/warehouse/product/customer/supplier/status/ref/created_at. Migrations only. Bigint + UUID.

## 7. Testing & CI
- PHPUnit (Pest optional later): tenant isolation, RBAC, entitlement, usage, CRUD, ledger, idempotency, void authz, expired block, disabled module block. GH Actions: validate/install/lint/tests/audit.

## 8. Deployment
- Ubuntu + Nginx + MySQL 8.4 + Redis + Supervisor (queue, scheduler). Docker optional. `.env` never edited from UI; secrets in vault/env. Health endpoint without secrets.
