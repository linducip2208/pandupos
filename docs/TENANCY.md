# Tenancy — PanduPOS

- Shared DB + `tenant_id` on every business row. `BelongsToTenant` auto-fills + `TenantScope` filters.
- `TenantMiddleware`: resolves from `current_tenant_id` → `X-Tenant-ID` → route param. NEVER trusts body `tenant_id`. Verifies membership (explicit platform-admin bypass), status (`trial/active/past_due` allowed; `suspended/cancelled/archived` blocked; `archived` never accessible to members).
- Statuses: `trial, active, past_due, suspended, cancelled, archived`.
- `withoutGlobalScopes()` must always re-constrain by explicit `tenant_id`; audited.
- Isolation proven by `TenantIsolationTest` (model + API, products/sales/purchases/contacts/warehouses/stock).
