# Billing, Subscriptions & Entitlements — PanduPOS Enterprise

- Plans: `plans` + `plan_entitlements(entitlement, value)`; unlimited = `null`.
- Subscriptions: statuses trialing/pending/active/past_due/grace_period/suspended/cancelled/expired; cycles monthly/quarterly/semiannual/yearly/lifetime; history via `subscription_events` immutable.
- Lifecycle: `SubscriptionService::subscribe/cancel/renew`; upgrade/downgrade = cancel active + create new (prorata future).
- Usage: `UsageLimitService::assertCanCreate(tenant, key)` for users/branches/warehouses/products/customers/suppliers/monthly invoices; dashboard snapshot + 80/90/100 alerts (caller).
- SaaS billing separated from POS payments: `billing_invoices`, `billing_transactions(gateway, gateway_ref unique)`, `payment_webhooks` idempotent.
- Gateways: `Manual/Cash` now; adapters Midtrans/Xendit/Duitku/Tripay/iPaymu via `PaymentGatewayInterface` + BYOK encrypted; secrets never to frontend.
- Coupons: percentage/fixed, plan-specific, max/per-tenant, valid range; `CouponService::validate/redeem` atomic.
- Affiliate: link + cookie attribution + first-subscription commission (unique per tenant) + payout saldo; anti self/duplicate/replay.
- Webhook inbound: `BillingService::handleWebhook` idempotent on `(gateway, gateway_ref)`.
