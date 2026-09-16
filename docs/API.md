# API v1 — PanduPOS Enterprise

Base: `/api/v1` + `auth:sanctum` + `tenant` middleware. Pagination, FormRequest validation, Resources, rate-limit, tenant isolation, `Idempotency-Key` for POST sales.

- `GET /me`, `GET /tenant`, `GET /modules`, `GET /entitlements`, `GET /usage`, `GET /subscription`
- `GET/POST /products`, `GET /products/{id}`
- `GET/POST /contacts?type=customer|supplier`
- `GET/POST /purchases`, `POST /purchases/{id}/receive`
- `GET/POST /sales`, `POST /sales/{id}/void`
- `GET /reports/sales?from&to`, `GET /reports/stock`
- `GET /sync/pull?since`, `POST /sync/push` (stub backend, Flutter later)
- `GET /webhooks`
- Platform: `GET /platform/tenants`, `POST /platform/tenants/{id}/suspend`, `GET /platform/health` (gate `platform-admin`)

Tenant isolation enforced via `TenantScope` + middleware; disabled module / missing entitlement → 403.
