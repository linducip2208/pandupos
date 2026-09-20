# WooCommerce Connector (Future Addon 10)

Store connections with encrypted credentials, product/inventory push,
order/customer pull and signed webhooks, gated by the `woocommerce` module
flag (depends on `ecommerce`; non-core).

## Concepts

- **Connections** (`wo_connections`): store URL + consumer key/secret
  encrypted at rest via Laravel Crypt; secrets never appear in logs, views
  or audit payloads. Status flips to `error` with the message on transport
  failure.
- **Transport abstraction** (`WooClientInterface`): `HttpWooClient` (basic
  auth, 20s timeout, HTTP failures mapped to `WooTransportException`) for
  production; `FakeWooClient` (in-memory products/orders/customers) for
  tests, demos and CI — no live store required.
- **Push**: variants become Woo products (created once via
  `wo_product_links`, updated after); inventory push sends on-hand levels
  for every linked variant. Every step writes a sync log.
- **Pull**: remote orders import into local ecommerce orders (pending,
  price/quantity snapshotted); unknown SKUs fail the single order with a
  log instead of partial import. Success logs make replays no-ops;
  failures retry on the next run. Customers upsert by email.
- **Webhooks** (`POST /api/v1/woocommerce/webhook/{connection}`): no
  session auth; verified with base64 HMAC-SHA256 (`X-WC-Webhook-Signature`)
  against the connection secret; 401 on mismatch, 422 on non-order topics,
  tenant-scoped by connection id, import-idempotent.

## Surfaces

- UI: `/woo` (connections, per-job live sync with confirmation, sync log).
- No admin JSON API beyond the workspace: connector state is managed in UI;
  the webhook is the machine endpoint.

## Intentional boundaries (v1)

- Live sync buttons hit the real store; schedule/queue workers are not
  included — pulls are manual or webhook-driven.
- Imported orders start `pending`: payment/shipment follow the ecommerce
  lifecycle (stock deducts at payment, as usual).
