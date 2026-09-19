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
                ['Batch, expiry and FEFO', 4, true, 'BatchWorkspaceTest, BatchExpirySerialTest and AdvancedStockTransferTest: audited tenant-scoped API/workspace receipt, supplier/PO/GRN provenance, POS selector, deterministic FEFO, expired blocking, return/void restoration and transfer preservation'],
                ['Serial lifecycle', 4, true, 'SerialWorkspaceTest, BatchExpirySerialTest and AdvancedStockTransferTest: audited tenant-scoped receive/reserve/release/sell/return/transfer/damage/history with idempotent reservation and POS selector'],
                ['Rack/bin', 2, true, 'BatchExpirySerialTest, AdvancedStockTransferTest and InventoryWorkspaceUiTest: audited location lifecycle, stock visibility and tenant/warehouse-scoped receiving, reservation, transfer, adjustment and count selectors'],
                ['Reservation', 3, true, 'StockLocationReservationTest, SalesOrderDeliveryTest and InventoryWorkspaceUiTest: tenant-scoped idempotent reserve/release/partial/full consume, ATP, scheduled expiry cleanup, audit and Sales Order confirm/deliver/cancel workflow'],
                ['Transfer', 3, true, 'AdvancedStockTransferTest and InventoryWorkspaceUiTest: multi-line request/segregated approval/ship/transit/partial receive/cancel with batch, serial and rack/bin provenance'],
                ['Adjustment', 2, true, 'InventoryControlTest and InventoryWorkspaceUiTest: multi-line draft/review/segregated approval/post, batch/rack-bin/serial trace, tenant rejection, audit and idempotency'],
                ['Stock count', 2, true, 'InventoryControlTest and InventoryWorkspaceUiTest: immutable snapshot/count/variance/review/segregated approval/post with batch, serial and rack/bin trace'],
                ['Reconciliation', 1, true, 'InventoryReconciliationService, InventoryControlTest and PlatformAuthorizationTest: read-only ledger/balance, batch, serial, rack/bin and reservation anomaly checks exposed in platform health'],
                ['WAC/costing', 2, true, 'WeightedAverageCostTest: purchase, sale, return, transfer and purchase-return cost preservation'],
                ['Purchasing UI', 5, true, 'PurchasingWorkspaceUiTest and PurchaseApprovalTest: tenant-scoped multi-line PO create UI, requester-owned draft edit/submit/cancel lifecycle, threshold approval routing, audit trail, no-stock-before-receipt invariant and printable PO output'],
                ['Purchase receipt', 4, true, 'PurchasingSafetyTest, BatchExpirySerialTest and PurchasingWorkspaceUiTest: tenant-scoped partial GRN UI/API with 30/40/30 limits, idempotency, audit, batch/PO/supplier provenance, rack/bin and no-double-stock serial registration'],
                ['Supplier invoice', 3, true, 'PurchasingWorkspaceUiTest, SupplierDocumentTest and PurchasingSafetyTest: tenant-scoped AP UI/API, supplier/PO validation, duplicate protection, immutable money totals, audit and printable document output'],
                ['Supplier payment', 2, true, 'SupplierDocumentTest, PurchasingSafetyTest and PurchasingWorkspaceUiTest: tenant-scoped partial/full AP payment, row lock, balance/overpayment and duplicate-reference protection, permission enforcement and audit'],
                ['Purchase return', 2, true, 'PurchaseReturnLifecycleTest (8 tests): staged draft/review/segregated-approve/post lifecycle, multi-line, batch/location/serial provenance, supplier/warehouse match, cost/WAC preservation, idempotency, over-return/double-return guards, tenant isolation, audit chain and posted immutability; workspace draft/submit/approve/post UI plus tenant-scoped API'],
                ['Purchasing safety', 2, true, 'PurchasingSafetyTest, PurchaseApprovalTest and SupplierDocumentTest: PO stock timing, 30/40/30 partial receipt, idempotent completed retry, over-receipt, duplicate supplier invoice, overpayment, over-return, approval and cross-tenant proof'],
                ['Quotation', 2, true, 'SalesCommercialDocumentTest: tenant workspace/API create, immutable non-posting lifecycle, transition validation, audit, printable document and cross-tenant denial'],
                ['Sales order', 3, true, 'SalesOrderDeliveryTest: tenant-scoped multi-line workspace draft, customer validation, audited confirm/cancel lifecycle, reservation and server-authorized partial fulfillment inputs'],
                ['Reservation to delivery', 3, true, 'SalesOrderDeliveryTest: tenant-scoped multi-line order workspace, transactional reservation on confirmation, partial/full delivery through controlled fulfillment inputs, reservation consumption/release, stock changes only on delivery, audit and cross-tenant route denial'],
                ['Invoice and payment', 4, true, 'SalesOrderService and SalesOrderDeliveryTest: fully delivered order creates one idempotent immutable posted invoice, preserves stock, separates fulfillment/payment status, validates partial/full payment against balance, enforces tenant-unique payment references and audits each transition'],
                ['POS workflow', 6, true, 'PosKasir, SaleService, PriceListWorkspaceTest, UnitConversionTest, SerialWorkspaceTest, BusinessFlowTest and RegisterSessionWorkflowTest: tenant cashier UI supports name/SKU/barcode lookup, variants, unit conversion, price-source resolution, customer, batch/serial selection, line discount, exclusive product tax, split payment, hold/resume and open-register atomic checkout with money invariant regression coverage'],
                ['Register', 4, true, 'RegisterSessionWorkflowTest: tenant-scoped register create/deactivate, one locked active session, opening cash, cash-in/out, cashier-owned POS settlement, denomination count, expected/actual variance, immutable closure, RBAC, cross-tenant rejection and audit trail'],
                ['Return, void and refund', 3, true, 'SalesReturnRefundLifecycleTest (8 tests): full/partial/multi-line/batch/serial/expired-batch returns with original-cost restoration, idempotent returns, over-return guards, payment-reversing refunds with paid-balance caps and reference idempotency, cash-session cash_out register impact with closed-session blocking, net-only void after returns with refunded payment state, RBAC, tenant isolation, audit chain; sales-returns workspace plus tenant-scoped API'],
                ['Reports', 4, true, 'ReportAccessAndFilterTest and ReportsAndIntegrationsTest: server-authorized tenant reports with validated branch/warehouse/date filters, final-sale totals, COGS/valuation consistency and foreign-filter rejection'],
                ['Dashboard', 2, true, 'DashboardController and DashboardFinancialVisibilityTest: tenant-filtered sales, purchasing, inventory, approval and role metrics with server-side financial permission gating and no shared cached aggregate state'],
                ['Export', 2, true, 'ReportAccessAndFilterTest and ReportsAndIntegrationsTest: same validated tenant-filtered report dataset is rendered as CSV, XLSX and PDF with format regression coverage'],
                ['Core API', 2, true, 'ApiSecurityTest and SecurityRegressionMatrixTest: authenticated tenant-context enforcement, token expiry/revocation, permission checks, replay-safe checkout, response error redaction, cross-tenant GET/write/export regression and body tenant rejection for current core APIs'],
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
                ['Regression suite', 60, true, 'Latest local full suite: 404 tests / 1337 assertions'],
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
                ['Repository secret scan', 3, true, 'scripts/scan-secrets.sh: tracked-content credential signatures, forbidden env/dump/key files and .env.example real-secret checks; latest local run clean'],
                ['Third-party license security review', 3, false, 'Commercial redistribution review remains incomplete'],
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
