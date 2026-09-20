# Manufacturing / MRP Module (Future Addon 03)

Versioned multi-level BOMs and work orders with availability-checked
release, proportional consumption, costed receipts and scrap, gated by the
`manufacturing` module flag (non-core; core tenants unaffected).

## Concepts

- **BOMs** (`mrp_boms` + `mrp_bom_lines`): one active revision per finished
  variant; creating a revision deactivates older ones while work orders keep
  pointing at their immutable revision. Components must be tenant-owned,
  non-self, unique per BOM, with quantity and scrap rate (0–1).
- **Explosion**: multi-level, scrap-inflated, cycle-guarded (422 on circular
  dependency, 10-level cap). Sub-assemblies with an active BOM explode to
  raw materials; the flat list drives release checks and consumption.
- **Work orders** (`mrp_work_orders`): `draft → released → in_progress →
  done`, plus `cancelled` from draft/released when nothing was consumed.
  Numbers are unique per tenant.
- **Release** verifies on-hand stock for the full planned quantity and
  refuses with an exact shortage list (SKU, required, available).
- **Production** (`recordProduction`): consumes components proportionally to
  the not-yet-consumed output basis (good + scrap), values consumption at
  component purchase price, and receives finished goods at running-average
  unit cost (`material_cost / quantity_produced`). Over-production beyond
  plan is refused. Every movement carries `reference_type` mrp_consume /
  mrp_produce with the work-order id; finished stock simply equals good
  output.
- **Scrap** counts toward the consumption basis (scrapped units consumed
  materials) but never becomes stock.

## Surfaces

- UI: `/mrp/boms`, `/mrp/orders` (shortage display, per-row produce/close
  forms) — `module:manufacturing` plus `mrp.view` / `mrp.manage`.
- API: `/api/v1/mrp/{boms,orders,orders/{id}/transition,orders/{id}/produce}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- No batch/serial selection on consume/produce: movements use warehouse
  defaults; batch/serial-tracked variants flow through the standard
  selectors at sale/purchase time.
- No labor/overhead rates: unit cost is pure material cost.
- No partial warehouse splits: one warehouse per work order.
