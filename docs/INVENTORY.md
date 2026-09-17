# Inventory & POS — PanduPOS Enterprise

## Inventory
- Master: `categories`, `brands`, `units`, `products`, `product_variants`, and `product_locations`.
- Products distinguish `stock`, `service`, and `bundle`; carry inventory-active, image, tax and reorder metadata. Variant barcode and attributes are stored separately from the product master.
- `unit_conversions` stores tenant-owned directed factors. `UnitConversionService` resolves direct, inverse, and chained conversion paths and rejects cross-tenant units. Stock remains expressed in the product base unit.
- `barcode_profiles` provides tenant-configurable weighing/price barcode positions and decimal precision. `BarcodeParserService` resolves normal product/variant barcodes before active scale profiles.
- `price_lists` and `price_list_items` support global, branch, customer-group and promotional scopes, effective dates, quantity breaks, priority and fallback to the variant price.
- Bundle definitions are relational `bundle_items`. Checkout, partial return and void mutate component inventory, never fake bundle stock.
- `inventory_batches` records tenant/variant/warehouse lot identity, manufacture/expiry dates and supplier/purchase provenance. `BatchInventoryService` receives stock by batch and allocates sales with FEFO, excluding expired stock unless a caller explicitly enables the controlled override.
- `serial_numbers` records the received/available/sold/returned/damaged/transferred lifecycle. `SerialNumberService` binds every stock mutation to one serial, validates tenant-owned invoices and rejects duplicate sales.
- `warehouse_locations` provides optional zone/rack/shelf/bin addressing per warehouse. Ledger movements may retain the physical location and stock can be queried per location.
- `stock_reservations` protects sales-order, held-sale and future ecommerce demand without posting inventory. Active, unexpired reservations reduce available-to-promise; release restores availability and consume posts exactly one append-only movement. Creation, release and consume are audited and idempotency keys prevent duplicate reservation creation.
- Truth: `stock_movements(tenant, warehouse, variant, ref_type/id, in/out, qty, unit_cost, occurred_at)` append-only.
- Batch and serial references are carried on the append-only movement; historical movements are never rewritten when lifecycle state changes.
- `inventory_balances` is only an atomically maintained cache. `php artisan inventory:reconcile` compares it with the ledger by tenant/warehouse/variant and changes nothing unless an operator explicitly passes `--fix`.
- Costing is resolved through the `CostingStrategy` contract; the active independent implementation is perpetual weighted average. Sale COGS, sale return/void restoration, transfers and original-cost purchase-return movements retain ledger cost evidence, while a future FIFO implementation can replace the strategy without rewriting movements.
- Adjustments require a controlled reason, explanatory notes, approval and a separate post action. Cycle counts snapshot expected quantities, require every count, expose variance for review/approval, and post append-only correction movements exactly once.
- `StockService::increase/decrease/onHand/transfer`; oversell rejected (no auto-adjust).
- Transfers use explicit `draft -> approved -> shipped -> in_transit -> partial_received -> received` (or pre-shipment cancellation). Shipping posts source `transfer_out`; each partial receipt posts destination `transfer_in`, so destination stock never appears before physical receipt. The shipment WAC is preserved on every receipt and all transitions are audited.
- Still missing: barcode label UI/printing, price-list UI, inventory-control management UI, fine-grained separation between adjustment requester/approver/poster, and purchase-document integration for purchase returns.

## POS / Sales
- `cash_sessions`, `sales_invoices(uuid, idempotency_key unique per tenant)`, `sales_lines`, `sale_payments(method cash/transfer/qris/ewallet/card)`, `sales_returns`.
- `SaleService::checkout` in `DB::transaction`: idempotency check → validate split totals → create invoice → lines + stock decrease → payments.
- `void` requires `pos.sale.void` permission; restores stock. `return` restores per lines.
- Receipt: template service (future) + signed URL; payment via intent abstraction.

## Purchasing
- `purchases(draft/ordered/partial/received/cancelled)` + `purchase_lines` immutable.
- `PurchaseService::createDraft` (no stock) → `receive` (stock in). Double-receive idempotent.
