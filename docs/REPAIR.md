# Repair Module (Future Addon 04)

Repair-shop workflow with warehouse-backed parts, gated by the `repair`
module flag (non-core; core tenants unaffected).

## Concepts

- **Orders** (`repair_orders`): `received → diagnosed → in_progress →
  ready → delivered`, with `waiting_parts` parking and `cancelled` only
  before work starts. Numbers are unique per tenant.
- **Diagnosis** records findings plus labor cost; totals recompute as
  `labor + parts − discount` on every change.
- **Parts** (`repair_order_parts`): added only while work is active, checked
  against on-hand stock at add time (clear shortage error), priced at the
  variant sell price snapshot (overridable). Stock is deducted **once, at
  delivery**, with `reference_type = repair_deliver` provenance — never at
  add time, so cancelled/parked orders never move inventory.
- **Warranty**: labor and parts bill at zero (customer total 0) while parts
  still deduct from stock on delivery.
- **Payment**: recorded against the order balance; overpayment refused;
  delivery requires a settled balance (zero for warranty).

## Surfaces

- UI: `/repair` (intake form, per-row diagnosis/part/payment/delivery
  forms) — `module:repair` plus `repair.view` / `repair.manage`.
- API: `/api/v1/repair/{orders,orders/{id}/transition,orders/{id}/parts,orders/{id}/pay}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- No repair invoicing integration: repair billing stays on the order
  balance; posting to sales/AR is an explicit future step.
- No technician scheduling: technician is recorded, not scheduled.
