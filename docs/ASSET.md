# Asset Management Module (Future Addon 06)

Asset registration, depreciation, transfers, maintenance and disposal,
gated by the `asset` module flag (non-core; core tenants unaffected).

## Concepts

- **Assets** (`assets`): code-unique per tenant, purchase cost/salvage/life
  validated (salvage below cost, positive life), statuses
  `active|assigned|maintenance|disposed`.
- **Depreciation** computed on demand (no stored rows to drift):
  straight-line monthly with rounding absorbed in the final month, and
  double declining-balance floored at salvage. Convention: the purchase
  month counts as month 1; elapsed months cap at useful life.
- **Transfers** (`asset_transfers`): custodian/location changes append
  history rows; assigning sets `assigned`, unassigning keeps prior status.
- **Maintenance** (`asset_maintenances`): preventive/corrective with cost
  and next-due date; assets return explicitly to active/assigned.
- **Disposal**: gain/loss = proceeds − computed book value as of the given
  date, audited. Disposed assets reject transfer/maintenance/disposal.

## Surfaces

- UI: `/assets`, `/assets/{id}/schedule` — `module:asset` plus
  `asset.view` / `asset.manage`.
- API: `/api/v1/assets{/,/{id}/schedule,/{id}/dispose}` (sanctum + tenant
  scope + same policies).

## Intentional boundaries (v1)

- Depreciation is calculated, not posted to accounting journals; posting
  stays an explicit journal action.
- No barcode/label printing: identification uses the asset code.
