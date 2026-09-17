# Gap to 100 — PanduPOS Enterprise

Updated: 2026-09-17. This is an executable backlog derived from repository evidence, not desired documentation.

## Release gates

| Gate | State | Evidence required to close |
|---|---|---|
| GitHub Actions | DONE | Keep validate, install/build, Pint, PHPUnit and Composer audit green |
| Core release | MISSING | Every mandatory core flow has UI, authorization, isolation, audit and tests |
| Addon release | MISSING | Each addon meets the module completeness rule and reaches RC |
| Security | PARTIAL | Full tenant/IDOR/CSRF/XSS/upload/API/webhook matrix with regression tests |
| Money/atomicity | PARTIAL | Decimal/rounding/rollback/concurrency proof for all money and stock mutations |
| Backup/restore | MISSING | Retained backup plus successful staging restore report |
| Performance | MISSING | Requested-volume and concurrent-checkout evidence |
| Operations | MISSING | Monitoring plus production deployment/rollback drill |
| Commercial/legal | MISSING | Dependency/assets license audit, packages, versioning and artifacts |

## Sprint 1 — product master and advanced inventory

Execute in this order:

1. Product types (stock/service/bundle), inventory flag, images, tax behavior, reorder, location assignment, variant attributes/barcode.
2. Base/sub-unit graph and precision-safe conversions; prove 2 cartons = 48 pcs, sell 5, balance 43.
3. Normal/variant/weighing parser, configurable scale rules, labels and print templates.
4. Price lists for tenant/location/customer group/date/priority with audited resolver.
5. Relational bundles with atomic component deduction.
6. Finish UI/permissions/audit and transaction selectors for implemented batch/expiry/FEFO and serial foundations; physical-location creation now has tenant UI.
7. Integrate the reservation workflow, whose create/release UI now exists, into sales-order/held-cart flows and schedule expiry cleanup.
8. Add requester/approver segregation to the implemented transfer, adjustment, and stock-count UI workflows.
10. Operationalize the implemented read-only `inventory:reconcile` command and alert on differences; retain explicit-only `--fix`.
11. WAC matrix and future FIFO strategy contract are implemented; retain as a regression gate and integrate original-cost returns with the upcoming purchase-return document.

Exit only when all applicable backend/UI/API/permission/isolation/audit/tests/docs layers exist, local gates pass, main is pushed, and Actions is green.

## Sprint 2 — purchasing, sales documents, and POS

1. Complete purchase request plus multi-line PO/return edit/cancel UX; PO, receipt, supplier invoice/payment and purchase return foundations and workspace now exist.
2. Complete requester/approver segregation and configurable over-receive policy UI; manager/owner threshold routing now exists.
3. Separate quotation, proforma, order, delivery, invoice, payment, return and credit note.
4. Reservation on confirmed orders and partial delivery.
5. Recurring templates without implicit charging.
6. Register open/count/close, cash movements, denominations and Z report.
7. Held carts and strict non-posting behavior.
8. Discount/tax policy engine and customer credit enforcement.
9. 58/80/A4 receipts and format-based ESC/POS abstraction.
10. Void/return/refund/original-COGS regression matrix.

## Sprint 3 — reports and data operations

Complete the requested sales, purchase, stock, expiry, statement, register, expense, tax, COGS and profit reports; tenant-safe filters; queued CSV/XLSX/PDF exports; previewed CSV/XLSX imports; and role-sensitive dashboards.

## Sprint 4 — SaaS

Close all entitlement limits; automate subscription transitions and deduplicated reminders; finish billing/reconciliation/PDF; prove one admin-configured Indonesian gateway sandbox through generic format adapters; finish coupons, affiliate, communications, branding/domains, scoped API tokens, and immutable offline-sale validation.

## Sprints 5–7 — addons

Build Accounting first with balanced posting tests, then CRM, Repair, Project, Asset, Manufacturing/MRP, HMS, Ecommerce/WooCommerce, HRM/Payroll, Field Force, AI, and remaining addons. A manifest/route never advances a module beyond DRAFT. Every module needs migrations, domain/service, real UI, permissions, entitlements, tenant scope, integration tests and docs.

## Sprint 8 — release hardening

Run the complete security and load/concurrency matrices; implement and test backup/restore; finish monitoring/health/deploy/rollback/installation docs; audit redistribution licenses; create `VERSION`, `CHANGELOG.md`, and `v1.0.0` only after all mandatory gates pass.

## Mandatory after every sprint

```text
composer validate --strict
vendor/bin/pint --test
php artisan test
composer audit
php artisan platform:module:health
```

Then update this file, `ULTIMATEPOS_PARITY.md`, and `PRODUCTION_READINESS_SCORE.md`; commit, push to `main`, and confirm Actions is green. Stop progression while CI is red.
