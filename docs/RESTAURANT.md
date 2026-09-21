# Restaurant — Optional Business Feature (default OFF)

Retail stays the default. Restaurant (tables, bookings, modifiers,
kitchen/KDS) is a tenant-aware option under Settings → Business, never a
mandatory mode and never a redesign of retail.

## Flag (single source of truth)

- `TenantSetting` key `features.restaurant` (`1` on / absent-or-`0` off).
  Per-tenant rows: tenant A ON never affects tenant B.
- Toggled at Settings → Business → POS / Business Features (permission
  `settings.manage`), audited as `restaurant.enabled` / `restaurant.disabled`.
- There is deliberately **no module row**: one gate (the settings flag),
  not two parallel gates.
- Default migration state is OFF (absence of the key = OFF); nothing is
  seeded on.

## Enforcement (backend, not menu-hiding)

- `EnsureRestaurantEnabled` middleware (`restaurant` alias) on every
  restaurant web route and API route: 403 when the tenant flag is off.
- `restaurant.view` / `restaurant.manage` permissions (seeded, provisioned
  to owners) gate UI/API via `RestaurantPolicy`. Permission alone grants
  nothing while the flag is off, and the flag alone grants nothing without
  permission.
- Sidebar section renders only when both pass (`nav-restaurant`).

## Domain (only when ON)

- Floors/tables (`available|occupied|reserved`), bookings with a 2-hour
  overlap guard, seat/cancel/no-show transitions.
- Modifier groups (min/max picks) + options (price deltas), linked
  explicitly per product; firing validates linkage and min/max across ALL
  linked groups (absent groups count as zero — required groups cannot be
  skipped); prices resolve to base + deltas.
- Kitchen tickets (`queued → preparing → ready → served`, cancel only from
  queued) with item snapshots, KDS board, elapsed timer, and refire with
  mandatory reason while preparing/ready.
- Cashier close-out posts a **normal retail sale** through `SaleService`
  (full payment enforced there), links the invoice, serves the ticket and
  frees the table when idle. Stock deducts once, at close — never at fire.
- Dine-in needs a table (additive orders allowed; bookings coordinate),
  takeaway/delivery need none.

## Surfaces

- UI: `/settings/business`, `/restaurant` (tables/bookings/tickets),
  `/restaurant/kitchen` (KDS), `/restaurant/modifiers`.
- API: `/api/v1/restaurant/{tickets,tickets/fire,tickets/{id}/advance,tickets/{id}/close,bookings,bookings/{id}/transition,items/{id}/refire}`.

## Intentional boundaries (v1)

- No stock reservation between fire and close: availability re-checks at
  close (422 on kitchen sell-out).
- Prices are posted tax-inclusive as configured (tax param 0 at close).
- No restaurant dashboard widgets; no split bills (one payment set per
  close); no table merging.
- `sales_invoices` schema untouched: table/order-type context lives on the
  ticket, not the invoice.
