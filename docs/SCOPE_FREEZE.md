# PanduPOS v1 Scope Freeze

Effective: 2026-09-17. PanduPOS Enterprise v1 is in stabilization, hardening, completion, and production-readiness mode. New business capabilities and new product modules are frozen until the v1 release gates pass.

## Included release scope

- Current product master, barcode, price list, bundle, warehouse, inventory ledger, WAC, batch, serial, rack/bin, reservation, transfer, adjustment, stock count, reconciliation, purchasing, sales, POS, tenant SaaS control-plane, API, offline-sync, reporting, portal, audit, health, backup, deployment, and documentation foundations already present in this repository.
- Work is limited to completing existing UI and workflows, correcting correctness/security/tenant-isolation issues, improving tests, operations, performance, deployment, supportability, and documentation.

## Deferred — post v1

All addon and module expansion is outside this release: Accounting, CRM, Manufacturing/MRP, Repair, Project, Asset, HMS, Ecommerce, WooCommerce, HRM, Payroll, AI, Restaurant, Gym, FieldForce, ZATCA, Cheque, and any other new module.

Deferred work is neither marked done nor counted as a v1 production or commercial readiness deficit. It remains informational future feature parity only.

## Release discipline

No score may be increased by documentation alone. Every in-scope score change requires a verified implementation gate and evidence in the canonical `ReadinessScoreService`. External work such as payment sandbox validation, staging, restore, and rollback remains blocked until actual evidence exists.
