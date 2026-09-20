# Ecommerce Module (Future Addon 09)

Published catalog, carts, checkout, shipment lifecycle and a public
storefront, gated by the `ecommerce` module flag (non-core).

## Concepts

- **Catalog**: `products.is_online` + active + stock type drives visibility;
  prices come from variant sell prices at transaction time. Publishing is an
  audited admin action.
- **Carts** (`ecommerce_carts`): one row per tenant/email with JSON lines;
  variants resolve tenant-explicitly (lazy relations honor ambient context,
  which is wrong for cross-context callers). Offline products are refused.
- **Checkout**: validates email/recipient/phone/address, shipping method
  (flat fees), warehouse stock availability; creates the customer Contact by
  email, snapshots line prices, clears the cart. Stock is validated here
  but deducted once, at payment.
- **Payment**: exact-total only; deducts every line via `ecommerce_sale`
  provenance. Over/underpayment refused.
- **Lifecycle**: `pending → paid → shipped → delivered`; cancellation only
  while pending (paid orders need a refund flow — explicit boundary).
- **Storefront**: resolved by tenant `slug`, only for trial/active tenants
  with the module on; suspended tenants 404. Guest cart/checkout endpoints
  are throttled (60/20 per minute); tracking requires number + email match.

## Surfaces

- Shop: `/shop/{slug}`, `/shop/{slug}/checkout`, `/shop/{slug}/track`.
- Admin UI: `/ecommerce` (orders, publish toggles).
- API: public `/api/v1/shop/{slug}/{catalog,cart,checkout,track}` +
  admin `/api/v1/ecommerce/{orders,orders/{id}/transition}` (sanctum +
  tenant scope + policies).

## Intentional boundaries (v1)

- No payment gateway: payment is recorded, not processed.
- No refunds/returns online: paid-order reversal stays a manual flow.
- No pro-rating or discounts online beyond shipping fees.
