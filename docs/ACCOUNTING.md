# Accounting Module (Future Addon 01)

Double-entry bookkeeping, tenant-isolated, gated by the `accounting` module
flag (paid addon; core tenants are unaffected). All amounts are
`decimal(15,2)`; every mutation is transactional and audited.

## Concepts

- **Chart of accounts** (`accounts`): code-unique per tenant, types
  asset/liability/equity/income/expense, optional `is_cash` marker used by
  the cash-flow report. `accounting:ensure-chart` (or opening the workspace)
  bootstraps the 14 system accounts idempotently (including 2150 Hutang
  Gaji, 2160 Hutang Potongan Gaji and 5210 Beban Gaji for payroll).
- **Journals** (`journal_entries` + `journal_lines`): lifecycle
  `draft → posted → void`. Posting requires a balanced journal (debit ==
  credit, positive), active tenant-owned accounts, and an open period. Void
  never deletes: it posts a mirror **reversal entry** and flags the original
  `void`. Voided originals stay in the ledger, so original + reversal always
  net to zero and history is complete.
- **Periods** (`accounting_periods`): closing a date range refuses any later
  posting or void whose entry date falls inside (including reversals dated
  today). Close events are audited.
- **Idempotency**: every source posting is keyed by
  `(tenant, source_type, source_id)` — replays return the existing posted
  entry, never a duplicate.

## Automatic posting (inside the source transaction)

| Document event | Debit | Credit |
|---|---|---|
| Sales invoice posted | 1300 Piutang (total) | 4100 Pendapatan (subtotal−discount), 2200 Pajak Keluaran (tax) |
| Customer receipt | 1100 Kas / 1200 Bank by method | 1300 Piutang |
| Supplier invoice posted | 1400 Persediaan (subtotal−discount+shipping), 1500 Pajak Masukan (tax) | 2100 Hutang (total) |
| Supplier payment | 2100 Hutang | 1100 Kas / 1200 Bank by method |

Hooks live in `SaleService::checkout` (final sales only, never
pending-approval), `SalesOrderService::payInvoice`,
`SupplierDocumentService::createInvoice` and `::pay`, each guarded by
`ModuleRegistry::isEnabled($tenant, 'accounting')`. Tenants without the
module execute byte-identical code paths to before.

## Reports (posted + voided originals + reversals; drafts excluded)

- Trial balance (contra balances flip columns; `balanced` flag),
- Profit & loss, balance sheet (current earnings close into equity;
  `A = L + E` checked), cash flow over `is_cash` accounts, tax
  output/input/net, open receivables and payables.

## Surfaces

- UI: `/accounting` (chart + trial balance), `/accounting/journals`,
  `/accounting/reports?from=&to=`, `/accounting/periods` — all behind
  `module:accounting` plus `accounting.view` / `accounting.manage`.
- API: `/api/v1/accounting/{accounts,trial-balance,profit-loss,journals,journals/{id}/post,journals/{id}/void}` (sanctum + tenant scope + same policies).
- Command: `accounting:ensure-chart [--tenant=]`.

## Intentional boundaries (v1)

- COGS is not auto-posted on sale (cost basis stays in inventory/WAC until
  an explicit costing close is specified).
- No period re-open: closed means immutable; corrections go to the current
  open period via new journals.
- Multi-currency: single tenant currency (amounts as recorded).
