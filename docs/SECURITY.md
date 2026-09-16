# Security & Deployment — PanduPOS Enterprise

## Security
- FormRequest validation everywhere; Policies/Gates server-side (never menu-only).
- Tenant isolation: `TenantScope` + middleware + tests; platform-admin bypass explicit.
- `DB::transaction` for sale/payment/stock/receipt/return/refund; idempotency keys.
- Secrets encrypted, never to frontend; no `.env` editor; no debug trace in prod.
- Rate limiting on API + auth; HMAC webhooks; mass-assignment guarded via `$fillable`.

## Deployment
- Ubuntu + Nginx + MySQL 8.4 + Redis (prod) + Supervisor (queue + scheduler).
- `php artisan migrate --force`, `config:cache`, `route:cache`, `queue:restart`.
- Scheduler: subscription renew/reminders; queue: email, webhooks, reports, AI.
- Health: `/api/v1/platform/health` (no secrets).

## CI
- GitHub Actions: composer validate/install, Pint lint, `php artisan test`, security audit.
