# Inventory & POS — PanduPOS Enterprise

## Inventory
- Master: `categories`, `brands`, `units`, `products`, `product_variants`, and `product_locations`.
- Products distinguish `stock`, `service`, and `bundle`; carry inventory-active, image, tax and reorder metadata. Variant barcode and attributes are stored separately from the product master.
- `unit_conversions` stores tenant-owned directed factors. `UnitConversionService` resolves direct, inverse, and chained conversion paths and rejects cross-tenant units. Stock remains expressed in the product base unit.
- `barcode_profiles` provides tenant-configurable weighing/price barcode positions and decimal precision. `BarcodeParserService` resolves normal product/variant barcodes before active scale profiles.
- `price_lists` and `price_list_items` support global, branch, customer-group and promotional scopes, effective dates, quantity breaks, priority and fallback to the variant price.
- Bundle definitions are relational `bundle_items`. Checkout, partial return and void mutate component inventory, never fake bundle stock.
- Truth: `stock_movements(tenant, warehouse, variant, ref_type/id, in/out, qty, unit_cost, occurred_at)` append-only.
- `StockService::increase/decrease/onHand/transfer`; oversell rejected (no auto-adjust).
- Transfers: `transfer_orders(draft/shipped/received/cancelled)` + `transfer_lines`; atomic out+in.
- Still missing: barcode label UI/printing, price-list UI, lots/batches/expiry/serials, FEFO, reservations, stock count, governed adjustments and advanced transfer receiving.

## POS / Sales
- `cash_sessions`, `sales_invoices(uuid, idempotency_key unique per tenant)`, `sales_lines`, `sale_payments(method cash/transfer/qris/ewallet/card)`, `sales_returns`.
- `SaleService::checkout` in `DB::transaction`: idempotency check → validate split totals → create invoice → lines + stock decrease → payments.
- `void` requires `pos.sale.void` permission; restores stock. `return` restores per lines.
- Receipt: template service (future) + signed URL; payment via intent abstraction.

## Purchasing
- `purchases(draft/ordered/partial/received/cancelled)` + `purchase_lines` immutable.
- `PurchaseService::createDraft` (no stock) → `receive` (stock in). Double-receive idempotent.
