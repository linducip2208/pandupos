# Current State Audit — PanduPOS (baseline `feat/platform-hardening`)

Date: 2026-09-16. Branch: `feat/platform-hardening`. Baseline CI: GREEN.
`composer validate --strict` OK (`livewire/livewire` pinned `^4.4`, lock `v4.4.5`), `pint --test` PASS, `php artisan test` 15 passed, `composer audit` clean.

Rule applied: file/service existing ≠ feature done. Done = migration + model + validation + authz + logic + UI/API + isolation + errors + tests + docs.

## Matrix

| FEATURE | DOCUMENTED | IMPLEMENTED | TESTED | STATUS | NEXT ACTION |
|---|---|---|---|---|---|
| Tenancy (TenantContext/Scope/BelongsToTenant/Middleware) | Yes (ARCH, ERD, PRD) | Partial (context+scope+middleware exist; membership check minimal) | Partial (isolation 2 tests) | PARTIAL | Harden middleware (membership, status trial/active/past_due/suspended/cancelled/archived), expand isolation web+API |
| Module Registry/Manager (`all`, `isEnabled`, commands list/health/enable/disable/make) | Yes (MODULE_SYSTEM) | Partial (DB registry; `validateManifest` only checks name/slug/version; file manifests for 4 modules but code still in `app/`) | Partial (disabled-module 403) | PARTIAL | Add ManifestValidator (deps, circular, permissions, entitlements, provider), wire file↔DB, per-module providers |
| Module structure (Inventory/Purchasing/Sales/POS under `Modules/`) | Yes (target layout) | No (only `module.json`, impl in `app/Models/Services/Http`) | No | NOT STARTED | Incremental move Inventory→Purchasing→Sales→POS, no class duplication, keep autoload green |
| Entitlements (`EntitlementService`, middleware) | Yes | Partial (basic allowed/all) | Partial (active/expired + middleware block) | PARTIAL | Plan-entitlement matrix UI, cache invalidation on plan change, numeric limits |
| Subscriptions lifecycle (trialing/pending/active/past_due/grace/suspended/cancelled/expired) | Yes | Partial (service exists, statuses partial) | Partial (expiry block only) | PARTIAL | Implement startTrial/activate/renew/upgrade/downgrade/cancel/expire/suspend + history immutable + tests |
| Usage limits (`UsageLimitService`, snapshot `/api/v1/usage`) | Yes | Partial (snapshot + basic enforce) | Partial (one enforce test) | PARTIAL | Enforce users/branches/warehouses/products/transactions server-side + consistent unlimited repr + tests |
| SaaS Billing (invoices/items/transactions/attempts/webhooks idempotent) | Yes (BILLING) | Partial (service + tables, no items/attempts tables yet) | No (webhook test missing) | PARTIAL | Add invoice_items/payment_attempts, lifecycle test, idempotent webhook test |
| Coupons | Yes | Partial (service+tables) | No | PARTIAL | Validate fixed/%/dates/limits/plans/first-invoice + redemption history + tests |
| Affiliates (click/referral/commission/payout) | Yes | Skeleton (models exist) | No | SKELETON | Complete flow + anti-duplicate commission + tests |
| Announcements/Communication | Yes | Skeleton | No | SKELETON | Targeting (all/tenant/plan/trial/expired) + queue + tests |
| Inventory (Product/Category/Brand/Unit/Variant/Warehouse) | Yes | Partial (Product/Variant/Warehouse exist; Category/Brand/Unit missing) | Partial (via BusinessFlow) | PARTIAL | Add Category/Brand/Unit, validation/policy, modularize first |
| Stock ledger (`StockService` append-only, transfer/adjust) | Yes (INVENTORY) | Partial (append-only good; `onHand` then write → race risk; costing zero) | Partial (receipt/sale/transfer/return) | PARTIAL | Txn + row locking, weighted-average costing, concurrency test |
| Purchasing (draft→ordered→partial→received, stock only on receive) | Yes | Partial (PO + receive, partial supported?) | Partial (receipt increases) | PARTIAL | Explicit statuses, partial receive quantities test, invoice/payment/return completion |
| Sales (quote/order/invoice/payment/delivery/return, separate statuses) | Yes | Partial (invoice+lines+payments, single status) | Partial | PARTIAL | Split document/payment/fulfillment statuses, immutable invoice + reversal only |
| POS checkout (Livewire `PosKasir`, cart/discount/tax/hold/split/receipt/void/session) | Yes (PRD) | Skeleton (basic Livewire view) | Partial (via SaleService tests) | SKELETON | Production workflow: scanner/search, register session open/close, hold/resume, split, change, receipt |
| Checkout atomicity (DB txn) | Yes | Partial (service uses txn?) verify | Partial (idempotent split test) | PARTIAL | Audit + enforce txn for validate→sale→items→payments→stock→invoice; rollback test |
| Idempotency (`Idempotency-Key`) | Yes | Partial (sales/payments only) | Partial (duplicate key one sale) | PARTIAL | Extend to receiving/refund/sync/webhook + return original result |
| Void vs Return semantics | Yes | Partial | Partial (void authz + return restores) | PARTIAL | Define rules, no hard-delete, audit + stock movement only |
| Contacts (customer/supplier/both, statements) | Yes | Partial (model+API index/store) | No | PARTIAL | Full fields (code/WA/tax/credit), statements, isolation tests |
| Payments (cash/bank/card_manual/qris/ewallet) + gateway interface | Yes | Partial (manual only, no interface) | No | PARTIAL | Add `PaymentGatewayInterface`, adapters folder, separate SaaS vs POS money |
| Reports (sales/inventory/purchasing/finance/profit with COGS) | Yes | Partial (sales/stock endpoints) | No | PARTIAL | Add by product/category/customer/cashier/branch, valuation, payment/register summary, profit with COGS |
| API v1 (controllers vs closures, Resources, versioning, request_id, rate-limit) | Yes (API.md) | Partial (5 controllers + many closures in `routes/api.php`) | Partial (via feature tests) | PARTIAL | Move closures to controllers, FormRequest+Resource+Policy, pagination meta |
| Offline sync (`devices`, uuid, cursor, `server_change_logs`, push/pull) | Yes (roadmap) | Stub (`sync/push` returns ok, `pull` raw query) | No | STUB | Real push/pull, conflict strategy (no naive LWW for money), tests |
| Platform Admin UI (`/platform/*` dashboard/tenants/plans/subs/billing/modules/...) | Yes (PRD §7) | No (only `/platform/health` stub view + API tenants/health) | No | NOT STARTED | Build dashboard + tenant detail/actions + plans/entitlements matrix + modules/health |
| Platform authz (granular `platform.*` permissions) | Yes (spec lists ~20) | No (only `can:platform-admin` + `is_platform_admin`) | No | NOT STARTED | Add permissions + Gates/Policies + tests |
| Audit log | Yes | Partial (service+table) | No | PARTIAL | Cover login/logout/tenant/plan/sub/module/stock/sale/void/payment/impersonation; no secrets |
| Impersonation | Yes | No | No | NOT STARTED | Sessions table + banner + exit + block dangerous actions + audit |
| Navigation (module-driven, permission+entitlement filtered) | Yes | No | No | NOT STARTED | Menu registry from `module.json`, backend authz still mandatory |
| Branding/White-label | Yes | Partial (service exists) | No | PARTIAL | Tenant logo/receipt/invoice, hide-branding entitlement, no hardcoded name |
| Health (`/platform/health`) | Yes | Stub (app/php/laravel only) | No | STUB | Add DB/Redis/cache/queue/scheduler/storage/mail/failed-jobs/modules/writable checks, no secrets |
| Security hardening | Yes (SECURITY.md) | Partial | Partial (isolation/authz) | PARTIAL | Audit CSRF/XSS/SQLi/IDOR/mass-assignment/upload/rate-limit/tokens/webhooks/redirects + `composer audit` + tests |
| UI (Tabler, sidebar/topbar/cards/tables/empty/loading/error; POS layout) | Yes | Partial (Tabler + landing/dashboard/auth/POS view) | No (ExampleTest only) | PARTIAL | Consistency pass, no theme switch, POS dedicated layout |
| Seeders (PlatformAdmin/DemoTenant/Plans/Modules/Permissions/Products) | Roadmap | Partial (DatabaseSeeder/PlatformSeeder exist) | N/A | PARTIAL | Dev-only guards, no insecure prod passwords |

## Key gaps found in baseline

1. `ModuleRegistry::all()` reads DB `modules` (seed shows 6: inventory/pos/purchasing/sales + accounting/crm) while `Modules/` has only 4 manifests — file↔DB drift, no provider wiring.
2. `validateManifest()` only checks required keys + slug regex; no deps/circular/entitlement/provider checks; `platform:module:health` reports OK without those.
3. `routes/api.php` still closure-heavy (me/tenant/modules/entitlements/usage/subscription/sync/webhooks).
4. `sync/push` is a stub; no `devices`, cursor, conflict handling.
5. `/platform/*` UI missing; only API + stub view.
6. Stock concurrency + costing not hardened; reports profit risks COGS-less calc.

## Baseline evidence (2026-09-16)

- `composer validate --strict`: valid
- `composer install --prefer-dist`: nothing to install (lock in sync)
- `./vendor/bin/pint --test`: PASS (after auto-fix 25 files)
- `php artisan test`: 15 passed (BusinessFlow 6, Entitlement 3, ModuleAccess 2, TenantIsolation 2, Example 2)
- `php artisan route:list`: 47 routes
- `php artisan migrate:status`: all Ran (17 migrations, batches 1-2)
- `platform:module:list`: 6 rows; `platform:module:health`: 4 OK
- `composer audit`: no advisories
