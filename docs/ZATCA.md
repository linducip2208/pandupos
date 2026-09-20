# ZATCA Module (Future Addon 15)

Saudi e-invoicing artifacts (QR TLV, UBL XML, hashes, notes, reporting
ledger), gated by the `zatca` module flag (depends on `sales`; non-core).

## Concepts

- **Documents** (`zatca_documents`): invoice / credit_note / debit_note with
  UUID, seller/buyer snapshot, totals, ISO timestamp, TLV QR, XML, SHA-256
  hash and reporting state. Generation from a sales invoice is idempotent
  (same invoice returns the same document).
- **QR TLV**: tags 1 seller, 2 VAT (15 digits enforced), 3 timestamp,
  4 total, 5 VAT — base64, roundtrip-decodable, rendered to real SVG via
  `bacon/bacon-qr-code` (BSD-2-Clause, audited).
- **XML**: simplified UBL 2.1 with InvoiceTypeCode 388/381/383, supplier
  VAT scheme, customer, monetary and tax totals — well-formed and
  node-asserted in tests.
- **Hash**: SHA-256 over the canonical (uuid, type, VAT, timestamp, totals)
  tuple so artifact drift is detectable.
- **Notes**: credit/debit notes reference exactly one invoice with
  validated amounts.
- **Reporting ledger**: `generated → reported` with portal clearance id;
  double reporting refused; every step audited.

## Surfaces

- UI: `/zatca` (generate from final invoices, notes, QR display, reporting)
  — `module:zatca` plus `zatca.view` / `zatca.manage`.
- API: `/api/v1/zatca/{documents,documents/{id},documents/{id}/report}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- **No live portal submission**: clearance/QR-crypto stamping against
  ZATCA Fatoora needs production CSIDs; the module produces portal-ready
  artifacts and records outcomes. Do not present `reported` as portal
  clearance without a real integration run.
