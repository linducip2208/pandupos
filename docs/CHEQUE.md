# Cheque Module (Future Addon 16)

Cheque receipt/issue, deposit batches, clearance, bounce with redeposit,
cancellation and reconciliation, gated by the `cheque` module flag
(non-core).

## Concepts

- **Instruments** (`cheques`): receipt/payment with bank + number uniqueness
  per tenant-bank, positive amounts, due never before issue. Numbers may
  repeat across different banks (different instruments).
- **Deposits** (`cheque_deposits`): batches of same-bank cheques in
  receivable/issued/bounced states; a batch closes automatically when
  nothing remains deposited.
- **Clearance**: deposited → cleared only; posts Dr Bank / Cr AR (receipts)
  or Dr AP / Cr Bank (payments) to accounting when enabled, idempotent by
  source reference.
- **Bounce**: requires a reason, detaches from the deposit, and the cheque
  redeposits cleanly later.
- **Cancellation**: only before deposit; deposited cheques must clear or
  bounce.
- **Reconciliation**: cleared/outstanding/bounced totals signed by direction
  (receipts positive, payments negative) with per-bank breakdowns over a
  date range.

## Surfaces

- UI: `/cheques` (register, deposit-by-IDs, clear/bounce/cancel, recon
  cards) — `module:cheque` plus `cheque.view` / `cheque.manage`.
- API: `/api/v1/cheques{/,/{id}/transition}` including reconciliation
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- No bank-statement import/matching: reconciliation compares ledger states,
  not external statements.
- No post-dated blocking: future-dated cheques are first-class (that is the
  point of cheques).
