# Inventory & POS — PanduPOS Enterprise

## Inventory
- Master: `categories`, `brands`, `units`, `products`, `product_variants`.
- Truth: `stock_movements(tenant, warehouse, variant, ref_type/id, in/out, qty, unit_cost, occurred_at)` append-only.
- `StockService::increase/decrease/onHand/transfer`; oversell rejected (no auto-adjust).
- Transfers: `transfer_orders(draft/shipped/received/cancelled)` + `transfer_lines`; atomic out+in.
- Future: lots/batches/expiry/serials as entities, FEFO.

## POS / Sales
- `cash_sessions`, `sales_invoices(uuid, idempotency_key unique per tenant)`, `sales_lines`, `sale_payments(method cash/transfer/qris/ewallet/card)`, `sales_returns`.
- `SaleService::checkout` in `DB::transaction`: idempotency check → validate split totals → create invoice → lines + stock decrease → payments.
- `void` requires `pos.sale.void` permission; restores stock. `return` restores per lines.
- Receipt: template service (future) + signed URL; payment via intent abstraction.

## Purchasing
- `purchases(draft/ordered/partial/received/cancelled)` + `purchase_lines` immutable.
- `PurchaseService::createDraft` (no stock) → `receive` (stock in). Double-receive idempotent.
