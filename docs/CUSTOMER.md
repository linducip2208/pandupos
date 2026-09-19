# PanduPOS Enterprise — customer documentation (console)

This is the operator-level documentation a customer receives with a commercial
license. It complements the product UI (help text, module documentation) and the
technical guides for self-hosting operators.

## Get started

1. **Login** — your staff user and password come from the tenant owner. Sessions
   rotate on login; keep HTTPS enabled.
2. **First setup** — create your branch, warehouse, and open the register
   (`Register`). Products with barcodes/weighing profiles import through the
   catalog; purchase receipts add stock (stock only ever increases on receive).
3. **Daily flow** — POS checkout (cash/split/payment-off, hold/resume), purchase
   receipt + supplier payment, returns/refunds with the original cost restored.
4. **Offline** — desktop/cashier devices sync via `/api/v1/sync/*`; mutations are
   append-only and idempotent (safe retry). See `docs/OFFLINE_SYNC.md`.
5. **Reports & exports** — tenant-filtered reports (sales, stock, purchasing)
   export to CSV/XLSX/PDF with the same server-authorized dataset.

## Data safety

- Backups run automatically (daily 01:30) and restore is a documented drill
  (`BACKUP_RUNBOOK.md`, `RESTORE_DRILL_REPORT.md`).
- Payments are settled by invoice + payment attempt with idempotent webhooks;
  `billing:reconcile` closes issued invoices from successful transactions.
- Your data is tenant-isolated: no cross-tenant reads/writes possible
  (enforced at model + route + API layers; see `docs/TENANCY.md`).

## Limits & subscriptions

- Your plan controls entitlements and usage limits (products, contacts,
  invoices/month) — enforced at write time with 403 beyond the cap.
- Trials → past-due → grace → suspension are deterministic on schedule
  (`docs/SUBSCRIPTIONS.md`).

## Getting help

See `docs/SUPPORT.md` diagnostics, or contact support with the output of
`php artisan health:check` and the affected flow. Support diagnostics were
accepted against this documentation matrix on 2026-09-19.