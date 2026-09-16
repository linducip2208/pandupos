# POS — PanduPOS

Production workflow: barcode/SKU search, variants, cart qty/discount/tax, customer, hold/resume, cash/bank-manual/QRIS-ready/e-wallet-ready, split payment (must balance), change, receipt, void/return/refund, register session (open/close, cash movement).

Rules:
- Checkout runs in `DB::transaction`: validate tenant/branch/warehouse/customer/variants → create sale → items → payments → stock out (WAC) → invoice number. Any failure rolls back all.
- `Idempotency-Key` header: same key returns original invoice, never duplicates.
- VOID cancels per permission (`pos.sale.void`); RETURN restores via stock movement with original cost. Completed sales are never hard-deleted; all actions audited.
- Oversell rejected (422). Stock truth = `SUM(stock_movements)`.
