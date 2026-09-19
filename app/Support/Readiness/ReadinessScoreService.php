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
                ['Usage limits', 10, true, 'UsageLimitTest (4 tests): snapshot invariants, customers/suppliers enforcement at StoreContact (type both), invoices.monthly quota at SaleService checkout after idempotency, and contact-limit race; UsageLimitService asserts every meter across write paths'],
                ['Subscriptions', 15, true, 'SubscriptionSchedulerTest (4 tests): EscalateOverdueSubscriptions deterministic scheduled trial/past-due/grace/suspend/expiry transitions, exactly-once scheduling, no stale subscriptions-expire command'],
                ['Billing', 15, true, 'BillingLifecycleTest (5 tests): money-invariant invoice creation (total = subtotal - discount + tax, items, currency), payment attempt + signed webhook → paid, billing:reconcile closes fully-paid issued invoices idempotently, void guards (paid immutable), platform invoice PDF download (Dompdf, application/pdf)'],
                ['Webhook and payment safety', 10, true, 'WebhookSafetyTest (6 tests): HMAC signature verification on inbound /api/v1/payments/webhooks/{gateway}, unknown gateway 404, malformed/ref-less payload 422, idempotent replay (same gateway_ref, no double-apply), settled transactions immutable to later failed status, missing/invalid signature 401; sandbox secret from env (no live credentials exist)'],
                ['Coupon, affiliate and announcement safety', 8, true, 'CouponAffiliateAnnouncementSafetyTest (3 tests): CouponService redeemCode atomic (lockForUpdate) with max/per-tenant caps and expiry, AffiliateController::recordCommission once per subscription (dup-commission proof), announcement queued delivery drained by notifications:send-pending with updateOrInsert (no duplicate deliveries on resend) and non-admin denied; plus ConcurrencyMatrixTest coupon race'],
                ['White label and domain safety', 8, true, 'DomainSafetyTest (6 tests): TenantDomainService secret-token ownership verification (pending → verified, token-only, idempotent), safe exact-match host resolution of verified domains only (prefix/contains/trailing-dot/port/scheme variants and forged tokens rejected), primary promotion only for verified domains, domain uniqueness (duplicate claim forbidden), platform store + public verify route'],
                ['API and offline hardening', 7, true, 'ApiTokenScopeTest (scoped issuance/expiry/revocation) plus DeviceOfflineSafetyTest (3 tests): revoked device locked out of sync push with 423 and cannot re-register, cross-tenant revoke denied, append-only cursor-ordered server_change_logs with idempotent replay evidence; platform filter recorded on devices'],
            ]),
            'future_addon_parity' => $this->dimension([
                ['Deferred post-v1 addon parity', 0, false, 'Out of v1 scope; informational only and excluded from release readiness'],
            ]),
            'tests' => $this->dimension([
                ['Regression suite', 60, true, 'Latest local full suite: 461 tests / 1686 assertions'],
                ['Critical browser E2E', 10, false, 'No browser-driver suite; critical paths are covered at the HTTP-kernel level only'],
                ['Concurrency', 10, true, 'ConcurrencyMatrixTest (15 tests): last-stock, batch FEFO, serial reserve/sell/transfer, reservation ATP, invoice/payment/PO idempotency, webhook duplicate, usage-limit, atomic coupon redemption, numbering uniqueness, refund double-submit races'],
                ['Security regression', 10, true, 'SecurityRegressionFullMatrixTest: cross-tenant read/write/API/export matrix, RBAC denial on void/return/platform actions, platform-admin gating with no state change'],
                ['Failure and recovery paths', 10, true, 'FailureRecoveryTest (10 tests): failed checkout atomicity, duplicate-job idempotency, real database-queue fail-then-retry, payment timeout with retry, backup/restore file roundtrip, cache-outage serving, oversell rejection'],
            ]),
            'security' => $this->dimension([
                ['Tenant isolation foundations', 15, true, 'TenantIsolationTest and tenant-scoped route/model protections'],
                ['Complete tenant isolation and IDOR matrix', 10, true, 'TenantIdorMatrixTest: GET/POST/PATCH/DELETE/API/export/job matrix denies cross-tenant reads and writes (4 tests/63 assertions)'],
                ['RBAC critical action foundations', 8, true, 'ModuleAccessTest and server-side workspace permission checks'],
                ['Complete RBAC matrix', 7, true, 'RbacCriticalActionsTest (3/28) plus SecurityRegressionFullMatrixTest (4/45) and RbacMatrixTest cover inventory/purchase/sales/report/export/platform action gates including returned-and-audited grant paths'],
                ['Web security foundations', 5, true, 'CSRF framework defaults, webhook signature and audit redaction regression'],
                ['Complete web security audit', 10, true, 'WebSecurityAuditTest: stored-XSS catalog escaping, mass-assignment guards, staff and portal login rate limits, open-redirect rejection, upload MIME/dimension rejection; reset flow deliberately absent (404); session regenerated on login'],
                ['API security foundations', 5, true, 'Authenticated tenant API and body-context rejection regressions'],
                ['Complete API security audit', 10, true, 'ApiTokenScopeTest (scoped issuance/UI/expiry/revocation), ApiSecurityTest replay idempotency + expiry/revocation + consistent error shape, throttle:300,1 on all v1 routes'],
                ['File ownership foundation', 2, true, 'Customer payment-proof ownership regression'],
                ['Complete file-security audit', 8, true, 'PaymentProof (mimes + mimetypes sniffing + size cap + hashed name on private disk + owner-only download with nosniff) and ProductMaster (mimes/mimetypes/size/dimensions + delete-on-replace)'],
                ['Dependency advisory scan', 4, true, 'composer audit clean in local and CI gates'],
                ['Repository secret scan', 3, true, 'scripts/scan-secrets.sh: tracked-content credential signatures, forbidden env/dump/key files and .env.example real-secret checks; latest local run clean'],
                ['Third-party license security review', 3, true, 'docs/THIRD_PARTY_LICENSE_AUDIT.md: composer licenses + npm ls + UltimatePOS grep clean; MIT/LGPL library use only, no redistributedUI/fork violations'],
                ['Sensitive admin foundations', 5, true, 'Platform authorization and audit foundations'],
                ['Sensitive admin hardening', 5, false, 'Re-authentication and complete sensitive-action matrix absent'],
            ]),
            'operations' => $this->dimension([
                ['CI and reproducible build', 20, true, 'GitHub Actions plus local Composer, Pint, Vite, test, audit and module-health gates'],
                ['Backup', 10, true, 'docs/BACKUP_RUNBOOK.md: backup:database + uploads manifest, retention/verification; fresh run 2026-09-19 produced database-20260919-125024.sqlite (1265664 bytes) + manifest; health backup_freshness PASS'],
                ['Restore drill', 15, true, 'docs/RESTORE_DRILL_REPORT.md: fresh 2026-09-19 drill backup:restore <artifact> --force -> Restore OK, health:check 10/10 PASS after restore'],
                ['Staging', 10, true, 'docs/STAGING.md: production-like staging acceptance executed 2026-09-19 (config clear, backup, restore, health 10/10, alert check, module health, secret scan, audit, security+concurrency+recovery regression)'],
                ['Deployment', 10, true, 'docs/DEPLOYMENT_RUNBOOK.md: repeatable deploy drill executed 2026-09-19 (composer validate, migrate --force applied, config:cache/clear OK, platform:module:health PASS, monitors, backup, readiness:score, health PASS)'],
                ['Rollback', 10, true, 'docs/ROLLBACK_DRILL.md: DB rollback drill executed 2026-09-19 (backup:restore + migrate:rollback --step=1 DONE then re-migrate DONE; additive-only migrations past v1 freeze)'],
                ['Monitoring', 10, true, 'health:check 10/10 PASS 2026-09-19, php artisan db:monitor OK, php artisan queue:monitor default OK (no failures/oldest N/A); instrumented in docs/PERFORMANCE_REPORT.md'],
                ['Alerting', 5, true, 'php artisan alert:check 2026-09-19 emitted verified alert paths: db, cache, queue, scheduler heartbeat, backup stale, disk, failed jobs, payment webhooks/billing failures'],
                ['Load and performance', 10, true, 'docs/PERFORMANCE_REPORT.md measured 2026-09-19: full HTTP cycle 4-26 ms, 2000-row tenant write ~4.9-5.3 s sqlite floor, indexed reads ~5 ms, 5000-row JSON serialize 10-135 ms; staging MySQL re-measurement is the v1 precondition'],
            ]),
            'production' => $this->dimension([
                ['Core product stability', 20, true, 'Requires Core >= 90 (canonical Core = 100): all core gates verified incl. purchase-return lifecycle, return/void/refund reversal, WAC, batch/serial/location, reservation-to-delivery, POS/register, reports/export and API matrix'],
                ['SaaS stability', 10, true, 'Requires SaaS >= 90 (canonical SaaS = 100): usage limits, subscriptions, billing, webhook/payment safety, coupon/affiliate/announcement, white-label/domain and API/offline hardening gates all flipped with named test suites'],
                ['Tests', 15, true, 'Requires Tests >= 90 (canonical Tests = 90): latest local full suite 461 tests / 1686 assertions green plus concurrency, failure/recovery and security regression matrices'],
                ['Security', 20, true, 'Requires Security >= 90 (canonical Security = 95) with no critical/high findings; release security audit closed in docs/RELEASE_AUDIT.md (IDOR/RBAC/web/api/file matrices green, composer audit clean, scan-secrets PASS)'],
                ['Operations', 20, true, 'Requires Operations >= 90 (canonical Operations = 100): backup, restore, staging, deployment, rollback, monitoring, alerting and measured load evidence all executed 2026-09-19'],
                ['Deployment and staging', 10, true, 'docs/STAGING.md + docs/DEPLOYMENT_RUNBOOK.md: production-like staging acceptance and repeatable deployment drill executed 2026-09-19 (migrate, config cache, module health, monitors, backup, health 10/10)'],
                ['No critical blocker', 5, true, 'docs/RELEASE_AUDIT.md: completed security and operational release audit 2026-09-19; no critical/high findings; residual low-severity process gaps tracked non-blocking'],
            ]),
            'commercial' => $this->dimension([
                ['Product stability', 20, true, 'Canonical Core = 100 and SaaS = 100; release audit + fresh-install drill green as release gates'],
                ['Production readiness', 20, true, 'Requires Production >= 90 (canonical Production = 100): all production gates flipped with evidence'],
                ['Commercial license audit', 15, true, 'docs/THIRD_PARTY_LICENSE_AUDIT.md: MIT/LGPL library use only, no proprietary UltimatePOS assets, composer audit clean, no redistribution blockers'],
                ['Fresh install', 10, true, 'docs/FRESH_INSTALL_DRILL.md executed 2026-09-19: empty database -> 127 migrations, storage:link, health:check 10/10, no demo seed and no inherited state'],
                ['Deployment documentation', 10, true, 'docs/DEPLOYMENT.md (operator guide) + docs/DEPLOYMENT_RUNBOOK.md (executable runbook) + deploy/nginx.conf + deploy/supervisor.conf; drill executed 2026-09-19'],
                ['Backup and recovery documentation', 10, true, 'docs/BACKUP_RUNBOOK.md + docs/RESTORE_DRILL_REPORT.md: retention/verification/offsite policy and fresh restore drill (Restore OK + health 10/10)'],
                ['Support and troubleshooting', 5, true, 'docs/SUPPORT.md: diagnostics matrix mapped to implemented command surface (health:check, alert:check, db:monitor, queue:monitor, billing:reconcile, backup:*) run 2026-09-19'],
                ['Upgrade and rollback', 5, true, 'docs/UPGRADE_DRILL.md + docs/ROLLBACK_DRILL.md executed 2026-09-19: migrate:rollback --step=1 and restore both DONE with health PASS; additive-only migration policy past v1 freeze'],
                ['Customer documentation', 5, true, 'docs/CUSTOMER.md: operator/customer console documentation (setup, daily flow, data safety, limits, support escalation) accepted 2026-09-19'],
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
