<?php

namespace App\Support\Readiness;

/**
 * Single, auditable readiness ledger.
 *
 * Scores are weighted only from repository-verified gates. A gate may be
 * changed to true only with its listed evidence; the score is calculated, not
 * entered in documentation.
 */
final class ReadinessScoreService
{
    /** @return array<string, array{score:int, requirements:array<int, array{requirement:string,weight:int,verified:bool,evidence:string}>}> */
    public function dimensions(): array
    {
        return [
            // Bundle and WAC are two points each so the independently verifiable
            // release outcomes total exactly 100; all other weights retain the
            // v1 risk priorities agreed for this release.
            'core' => $this->dimension([
                ['Product master', 10, true, 'ProductMasterUiTest: CRUD, archive, audit, policy and tenant isolation'],
                ['Units and sub-units', 4, true, 'UnitConversionTest: direct/inverse/chained conversion and transaction selectors'],
                ['Barcode, weighing barcode and labels', 4, true, 'BarcodeWorkspaceTest and AdvancedCatalogInventoryTest'],
                ['Price groups', 4, true, 'PriceListWorkspaceTest: scope, precedence, audit and POS source'],
                ['Bundle/combo', 2, true, 'BundleWorkspaceTest and atomic component sale/return/void regression'],
                ['Batch, expiry and FEFO', 4, false, 'Receive, provenance, purchase/POS selectors, FEFO, expired prevention, audit and tests'],
                ['Serial lifecycle', 4, true, 'SerialWorkspaceTest, BatchExpirySerialTest and AdvancedStockTransferTest: audited tenant-scoped receive/reserve/release/sell/return/transfer/damage/history with idempotent reservation and POS selector'],
                ['Rack/bin', 2, true, 'BatchExpirySerialTest, AdvancedStockTransferTest and InventoryWorkspaceUiTest: audited location lifecycle, stock visibility and tenant/warehouse-scoped receiving, reservation, transfer, adjustment and count selectors'],
                ['Reservation', 3, true, 'StockLocationReservationTest, SalesOrderDeliveryTest and InventoryWorkspaceUiTest: tenant-scoped idempotent reserve/release/partial/full consume, ATP, scheduled expiry cleanup, audit and Sales Order confirm/deliver/cancel workflow'],
                ['Transfer', 3, true, 'AdvancedStockTransferTest and InventoryWorkspaceUiTest: multi-line request/segregated approval/ship/transit/partial receive/cancel with batch, serial and rack/bin provenance'],
                ['Adjustment', 2, true, 'InventoryControlTest and InventoryWorkspaceUiTest: multi-line draft/review/segregated approval/post, batch/rack-bin/serial trace, tenant rejection, audit and idempotency'],
                ['Stock count', 2, true, 'InventoryControlTest and InventoryWorkspaceUiTest: immutable snapshot/count/variance/review/segregated approval/post with batch, serial and rack/bin trace'],
                ['Reconciliation', 1, true, 'InventoryReconciliationService, InventoryControlTest and PlatformAuthorizationTest: read-only ledger/balance, batch, serial, rack/bin and reservation anomaly checks exposed in platform health'],
                ['WAC/costing', 2, true, 'WeightedAverageCostTest: purchase, sale, return, transfer and purchase-return cost preservation'],
                ['Purchasing UI', 5, false, 'PO multi-line draft/edit/approval/document UI'],
                ['Purchase receipt', 4, false, 'Goods receipt and partial-receipt UI with audit and tenant checks'],
                ['Supplier invoice', 3, false, 'Separate AP invoice UI, validation and document output'],
                ['Supplier payment', 2, false, 'Partial/full payment UI, validation and audit'],
                ['Purchase return', 2, false, 'Received-minus-return workflow UI and supplier credit evidence'],
                ['Purchasing safety', 2, false, 'Duplicate receipt, 30/40/30, over-return, overpayment and cross-tenant proof'],
                ['Quotation', 2, false, 'Current tenant UI, transition validation, print and audit'],
                ['Sales order', 3, false, 'Current UI, reservation, status, permission and tenant proof'],
                ['Reservation to delivery', 3, false, 'Partial delivery, stock timing and duplicate-movement proof'],
                ['Invoice and payment', 4, false, 'Posted lifecycle, payment states, immutability and idempotency proof'],
                ['POS workflow', 6, false, 'Current checkout UI: search, barcode, variants, unit, price, discount, tax and split payment'],
                ['Register', 4, false, 'Open, movements, count, close, expected/actual variance and audit'],
                ['Return, void and refund', 3, false, 'Controlled reversal, over-return and duplicate payment/void/refund proof'],
                ['Reports', 4, false, 'Current report totals, filters, tenant/RBAC and COGS consistency'],
                ['Dashboard', 2, false, 'Permission-safe tenant metrics and no-N+1 evidence'],
                ['Export', 2, false, 'Tenant-safe, permission-safe CSV/XLSX/PDF parity and large-data handling'],
                ['Core API', 2, false, 'Current core resource authorization, response consistency and tenant regression matrix'],
            ]),
            'saas' => $this->dimension([
                ['Tenant lifecycle foundation', 12, true, 'Tenant action routes, audit trail, impersonation foundation and representative authorization tests'],
                ['Plans and entitlements', 15, true, 'Plan editor, entitlement middleware and EntitlementTest'],
                ['Usage limits', 10, false, 'Every configured limit must be enforced across all write paths with race proof'],
                ['Subscriptions', 15, false, 'Deterministic scheduled trial/past-due/grace/suspend/expiry transitions'],
                ['Billing', 15, false, 'Invoice, payment attempt, reconciliation, PDF and lifecycle acceptance'],
                ['Webhook and payment safety', 10, false, 'Full idempotency/replay matrix and external sandbox evidence where credentials exist'],
                ['Coupon, affiliate and announcement safety', 8, false, 'Atomic redemption, duplicate-commission and queued delivery evidence'],
                ['White label and domain safety', 8, false, 'Verified ownership and safe host resolution'],
                ['API and offline hardening', 7, false, 'Token scope/revocation and full immutable device-event evidence'],
            ]),
            'future_addon_parity' => $this->dimension([
                ['Deferred post-v1 addon parity', 0, false, 'Out of v1 scope; informational only and excluded from release readiness'],
            ]),
            'tests' => $this->dimension([
                ['Regression suite', 60, true, 'Latest local full suite: 273 tests / 825 assertions'],
                ['Critical browser E2E', 10, false, 'No verified browser suite for v1 critical paths'],
                ['Concurrency', 10, false, 'No last-stock, numbering, usage, coupon and webhook race matrix'],
                ['Security regression', 10, false, 'Full cross-tenant, IDOR and RBAC endpoint matrix absent'],
                ['Failure and recovery paths', 10, false, 'No verified operational recovery-path matrix'],
            ]),
            'security' => $this->dimension([
                ['Tenant isolation foundations', 15, true, 'TenantIsolationTest and tenant-scoped route/model protections'],
                ['Complete tenant isolation and IDOR matrix', 10, false, 'GET/POST/PATCH/DELETE/API/export/job matrix absent'],
                ['RBAC critical action foundations', 8, true, 'ModuleAccessTest and server-side workspace permission checks'],
                ['Complete RBAC matrix', 7, false, 'All inventory/purchase/sales/report/platform actions not yet covered'],
                ['Web security foundations', 5, true, 'CSRF framework defaults, webhook signature and audit redaction regression'],
                ['Complete web security audit', 10, false, 'XSS, mass-assignment, upload, reset/session and rate-limit audit absent'],
                ['API security foundations', 5, true, 'Authenticated tenant API and body-context rejection regressions'],
                ['Complete API security audit', 10, false, 'Scope, expiry, revocation, rate-limit and replay matrix absent'],
                ['File ownership foundation', 2, true, 'Customer payment-proof ownership regression'],
                ['Complete file-security audit', 8, false, 'MIME, extension, size, filename and storage review absent'],
                ['Dependency advisory scan', 4, true, 'composer audit clean in local and CI gates'],
                ['Secret and license security scan', 6, false, 'Repository secret scan and third-party review absent'],
                ['Sensitive admin foundations', 5, true, 'Platform authorization and audit foundations'],
                ['Sensitive admin hardening', 5, false, 'Re-authentication and complete sensitive-action matrix absent'],
            ]),
            'operations' => $this->dimension([
                ['CI and reproducible build', 20, true, 'GitHub Actions plus local Composer, Pint, Vite, test, audit and module-health gates'],
                ['Backup', 10, false, 'No actual backup evidence recorded'],
                ['Restore drill', 15, false, 'No verified restore validation'],
                ['Staging', 10, false, 'No production-like staging acceptance'],
                ['Deployment', 10, false, 'No verified repeatable deployment drill'],
                ['Rollback', 10, false, 'No verified rollback drill'],
                ['Monitoring', 10, false, 'No operational monitoring evidence'],
                ['Alerting', 5, false, 'No verified alert paths'],
                ['Load and performance', 10, false, 'No measured performance report'],
            ]),
            'production' => $this->dimension([
                ['Core product stability', 20, false, 'Requires Core >= 90'],
                ['SaaS stability', 10, false, 'Requires SaaS >= 90'],
                ['Tests', 15, false, 'Requires Tests >= 90'],
                ['Security', 20, false, 'Requires Security >= 90 with no critical/high findings'],
                ['Operations', 20, false, 'Requires Operations >= 90'],
                ['Deployment and staging', 10, false, 'Requires staging and deployment drill evidence'],
                ['No critical blocker', 5, false, 'Requires completed security and operational release audit'],
            ]),
            'commercial' => $this->dimension([
                ['Product stability', 20, false, 'Requires verified Core and SaaS release gates'],
                ['Production readiness', 20, false, 'Requires Production >= 90'],
                ['Commercial license audit', 15, false, 'Third-party redistribution audit not complete'],
                ['Fresh install', 10, false, 'No verified fresh-install drill'],
                ['Deployment documentation', 10, false, 'No verified deployment documentation drill'],
                ['Backup and recovery documentation', 10, false, 'No restore drill documentation'],
                ['Support and troubleshooting', 5, false, 'Support diagnostics and troubleshooting acceptance absent'],
                ['Upgrade and rollback', 5, false, 'Upgrade/rollback drill evidence absent'],
                ['Customer documentation', 5, false, 'Current-product customer documentation acceptance absent'],
            ]),
        ];
    }

    /** @return array{score:int,requirements:array<int,array{requirement:string,weight:int,verified:bool,evidence:string}>} */
    private function dimension(array $requirements): array
    {
        $requirements = array_map(fn (array $gate): array => [
            'requirement' => $gate[0], 'weight' => $gate[1], 'verified' => $gate[2], 'evidence' => $gate[3],
        ], $requirements);

        return [
            'score' => array_sum(array_map(fn (array $gate): int => $gate['verified'] ? $gate['weight'] : 0, $requirements)),
            'requirements' => $requirements,
        ];
    }
}
