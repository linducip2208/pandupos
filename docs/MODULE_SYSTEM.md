# Module System — PanduPOS Enterprise

## 1. Concepts
- `modules`: registry pusat (slug, name, version, category, status, is_core, is_paid, provider, metadata).
- `tenant_modules`: enablement per tenant (enabled, settings, enabled_at/disabled_at).
- States: installed / enabled / disabled / incompatible / broken.
- Manifest: `Modules/<Slug>/module.json`:
```json
{
  "name": "POS",
  "slug": "pos",
  "version": "1.0.0",
  "description": "Point of Sale",
  "dependencies": ["inventory"],
  "permissions": ["pos.sale.create", "pos.sale.void"],
  "entitlements": ["pos.access", "pos.multi_register"],
  "navigation": [{"label": "POS", "route": "pos.index", "icon": "cart"}]
}
```
Validated at boot/deploy (`ModuleRegistry::validate()`). No arbitrary ZIP PHP execution without verify (signature + sandbox + review).

## 2. Communication Rules
- Modules MUST NOT access other module internals directly. Use Services, Events/Listeners, DTO, Contracts, Policies.
- Core emits: `TenantCreated`, `OrderPaid`, `StockReceived`, `SaleVoided`, `SubscriptionChanged`. Modules subscribe.
- Entitlement gate: `entitlement:manufacturing.bom` middleware + `EntitlementService::allowed()`.

## 3. Services
- `ModuleRegistry`: load manifests, validate deps/version, cache (tenant-keyed invalidation on change).
- `ModuleManager`: enable/disable with `DependencyResolver` (topological) + `CompatibilityChecker` (core version, PHP, DB).
- `ModuleDependencyResolver`: fail with clear message if `inventory` required by `pos` missing.
- Artisan:
  - `php artisan platform:module:list` — table slug/version/enabledtenants/status
  - `php artisan platform:module:health` — manifest valid, deps ok, migrations run, routes loaded
  - `php artisan platform:module:enable POS --tenant=1`
  - `php artisan platform:module:disable POS --tenant=1`
  - `php artisan platform:make-module CRM` — scaffolds `Modules/CRM/{Config,Database,Domain,Http,Models,Providers,Resources,Routes,Services,Tests,module.json}`

## 4. Generator Output (`platform:make-module`)
```
Modules/CRM/
  module.json
  Config/config.php
  Providers/CRMServiceProvider.php (register events, policies, nav, permissions)
  Routes/web.php + api.php (wrapped in tenant + entitlement middleware)
  Http/Controllers + Requests + Resources
  Models/ + Database/migrations/ (all with tenant_id)
  Services/ + Domain/ + Tests/
```

## 5. Enforcement
- Route groups: `->middleware(['tenant', 'auth', 'entitlement:crm.access', 'module:crm'])`.
- Disabled module → 403 + audit `module.disabled_access_blocked`. Covered by test.
- Frontend nav filtered by `ModuleRegistry::navigationFor(tenant)`.

## 6. Lifecycle
`install (migrate) → enable (tenant) → disable → upgrade (version check) → uninstall (data retained/archived, never hard-delete billing)`.
