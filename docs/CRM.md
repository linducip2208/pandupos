# CRM Module (Future Addon 02)

Leads, opportunity pipeline, activities and sales linkage, tenant-isolated
and gated by the `crm` module flag (non-core; core tenants unaffected).

## Concepts

- **Leads** (`crm_leads`): status machine
  `new → contacted → qualified → converted`, with `lost` from any open
  state and re-open `lost → new`. Skips are rejected (422).
- **Conversion** (`CrmService::convertLead`): contacted/qualified leads
  convert into a customer `Contact` inside a row-locked transaction.
  Idempotency is checked **before** the status guard, so replays return the
  same customer and never duplicate it.
- **Opportunities** (`crm_opportunities`): stage machine
  `prospect → negotiation → won`, `lost` from open stages, re-open
  `lost → prospect`. Moving to `won` requires a linked customer (converted
  lead contact or attached contact) — pipeline value cannot be faked without
  a real customer behind it.
- **Quotation linkage**: an opportunity links a tenant-owned `SalesQuotation`
  (IDOR-checked); linking backfills the contact from the quotation when the
  opportunity has none.
- **Activities** (`crm_activities`): call/meeting/email/note/follow-up
  attached to a lead and/or opportunity, with scheduling, completion, and an
  overdue follow-up query used by the workspace banner.

## Surfaces

- UI: `/crm` (pipeline cards, overdue banner, leads, opportunities,
  activity form) — `module:crm` plus `crm.view` / `crm.manage`.
- API: `/api/v1/crm/{leads,opportunities,opportunities/{id}/advance}`
  (sanctum + tenant scope + same policies).

## Reports

- Pipeline value/count per stage (`CrmService::pipeline`), overdue
  follow-ups (per owner or tenant-wide).

## Intentional boundaries (v1)

- No email sending: activities record intent; delivery stays with the
  announcement/notification subsystem.
- No automatic quotation creation: linkage is explicit to keep sales
  documents under their own approval rules.
