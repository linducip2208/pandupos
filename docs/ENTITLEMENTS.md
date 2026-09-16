# Entitlements — PanduPOS

`EntitlementService::allowed(tenant, key)` reads active subscription → `plan_entitlements`. `null` = unlimited (allowed; count checked by `UsageLimitService`).

Access requires BOTH: module enabled AND entitlement allowed, enforced server-side via `module:` + `entitlement:` middleware (menu hiding is UX only).

Numeric limits (`users.max`, `branches.max`, `warehouses.max`, `products.max`, `monthly_transactions.max`, `storage.mb`, `api.requests.monthly`, `ai.credits.monthly`) enforced in `UsageLimitService::assertCanCreate`. Snapshot `/api/v1/usage` returns `{current, limit, percent, unlimited}` per key (limit `null` = unlimited, percent 0).
