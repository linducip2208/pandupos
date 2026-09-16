# PanduPOS

Modern modular multi-tenant SaaS POS & business platform.

Independent application. UltimatePOS is used only as a feature/workflow reference — no proprietary code is copied, and PanduPOS is not a fork.

## Overview

PanduPOS is a multi-tenant SaaS for retail/SME operations:

- POS checkout (cashier-optimized, Livewire)
- Inventory with append-only stock ledger
- Purchasing (PO → receive → invoice → payment → return)
- Sales (quotation → order → invoice → payment → fulfillment → return/refund)
- Platform Admin (tenants, plans, subscriptions, billing, modules, entitlements)
- API v1 + offline-sync foundation for future Flutter client

## Architecture

Modular monolith (Laravel):

- `app/Models`, `app/Services`, `app/Http`, `app/Livewire` — current business implementation
- `Modules/{Inventory,Purchasing,Sales,POS}/module.json` — module manifests (migration to full modular structure is incremental)
- `app/Support`: `TenantContext`, `TenantScope`, `BelongsToTenant`
- `app/Services`: `ModuleRegistry`, `ModuleManager`, `EntitlementService`, `UsageLimitService`, `SubscriptionService`, `BillingService`, `StockService`, `PurchaseService`, `SaleService`, `AuditService`, `BrandingService`
- Docs: `docs/ARCHITECTURE.md`, `docs/ERD.md`, `docs/MODULE_SYSTEM.md`, `docs/PRODUCT_REQUIREMENTS.md`, `docs/IMPLEMENTATION_ROADMAP.md`

Definition of Done (per feature): migration + model + validation + authorization + service logic + UI/API + tenant isolation + error handling + tests + docs.

## Requirements

- PHP 8.3+
- Laravel 13
- MySQL 8+ (production) / SQLite (local/test)
- Redis recommended (cache/queue in production)
- Node.js + NPM
- Composer 2.x

## Installation

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
npm run build
php artisan serve
```

## Development

```bash
php artisan serve
npm run dev
php artisan queue:listen --tries=1
php artisan pail
```

Useful:

```bash
composer validate --strict
composer dump-autoload
php artisan optimize:clear
./vendor/bin/pint --test
php artisan test
php artisan platform:module:list
php artisan platform:module:health
```

Working branch for hardening: `feat/platform-hardening`.

## Tenant Architecture

- Every tenant-owned model uses `tenant_id` + `BelongsToTenant` global scope (`TenantScope`).
- `TenantMiddleware` resolves `TenantContext` from authenticated user membership — never trust `tenant_id` from request body.
- Tenant statuses: `trial`, `active`, `past_due`, `suspended`, `cancelled`, `archived`.
- Platform admin bypass is explicit via `can:platform-admin`.
- `withoutGlobalScopes()` usage must be audited and always re-constrained by explicit `tenant_id`.

See `docs/ARCHITECTURE.md`, `docs/ERD.md`.

## Module System

Manifests in `Modules/*/module.json`. Registry (`ModuleRegistry`) + manager (`ModuleManager`) control enable/disable per tenant.

Commands:

```bash
php artisan platform:module:list
php artisan platform:module:health
php artisan platform:module:health POS
php artisan platform:module:enable POS
php artisan platform:module:disable POS
php artisan platform:make-module CRM
```

Access requires both: module enabled AND plan entitlement allowed, enforced server-side (403 otherwise). Disabling a module never deletes data.

See `docs/MODULE_SYSTEM.md`.

## API

Base: `/api/v1` (Sanctum + `tenant` middleware).

- `GET /api/v1/me`, `GET /api/v1/tenant`
- `GET /api/v1/modules`, `GET /api/v1/entitlements`, `GET /api/v1/usage`, `GET /api/v1/subscription`
- `products`, `contacts`, `purchases` (+ `POST purchases/{id}/receive`), `sales` (+ `POST sales/{invoice}/void`)
- `GET reports/sales`, `GET reports/stock`
- `GET sync/pull`, `POST sync/push` (stub → real sync per roadmap)
- Platform: `/api/v1/platform/*` (`can:platform-admin`)

Convention: `{ "data": ... }`, errors `{ "message": ..., "errors": {} }`, paginated + `request_id`, rate-limited.

See `docs/API.md`.

## Testing

```bash
php artisan test
```

Coverage: `TenantIsolationTest`, `EntitlementTest`, `ModuleAccessTest`, `BusinessFlowTest` (purchase receive → stock, sale → stock, return, split-payment idempotency, void RBAC, usage limits).

Critical invariants (must always hold):

- PO creation does not increase stock; receiving does, exactly once (partial receives supported).
- Sale decreases stock; oversell fails; void/return restores via stock movement (no hard-delete of completed sales).
- Duplicate `Idempotency-Key` checkout creates one sale.
- Tenant A cannot touch Tenant B data (web + API).
- Disabled module / missing entitlement → 403; expired subscription blocks paid features.

## Queue

Default `database` locally; use Redis in production. Long sends (announcements) and webhooks must be queued.

## Scheduler

Enable cron for subscription expiry/renewal, trial-ending reminders, queued jobs, and health checks.

```bash
php artisan schedule:run
```

## Deployment

1. `composer install --prefer-dist --no-progress`
2. `npm run build`
3. `php artisan migrate --force`
4. `php artisan optimize`
5. Configure queue worker + scheduler + Redis + mail + storage link.

Checklist before release: `composer validate --strict`, `pint --test`, `php artisan test`, `composer audit`, `php artisan migrate --pretend`, `platform:module:health`.

## Security

- CSRF/XSS/SQLi/IDOR/tenant-escape/mass-assignment/upload/rate-limit/auth/API-token/webhook-signature/open-redirect/session hardening.
- Never log passwords, tokens, API secrets, payment credentials.
- `composer audit` must be clean; add security tests for authz and tenant isolation.

See `docs/SECURITY.md`.

## License

Proprietary — PanduPOS Enterprise. All rights reserved unless a separate commercial license states otherwise. Do not redistribute. UltimatePOS reference does not grant any right to its proprietary code.
