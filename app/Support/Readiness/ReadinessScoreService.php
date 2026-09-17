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
            'core' => $this->dimension([
                ['Product, units, barcode and price groups end-to-end', 26, true, 'Feature/UI/API/isolation tests'],
                ['Bundle/combo UI and atomic component inventory', 6, true, 'Tenant component UI, audit and sale/return/void tests'],
                ['Inventory advanced workflows end-to-end', 18, false, 'Batch, serial, rack, reservation, transfer, count UX'],
                ['Purchasing/AP lifecycle end-to-end', 18, false, 'PR, multi-line PO, statements, exports'],
                ['Sales/POS/register lifecycle end-to-end', 22, false, 'Documents, register, receipts, refunds'],
                ['Reports, imports and role dashboard', 10, false, 'Catalog, queued export/import and role tests'],
            ]),
            'saas' => $this->dimension([
                ['Tenant/plan/entitlement foundations', 28, true, 'Tenant lifecycle, plan UI and middleware'],
                ['Subscription and billing automation', 25, false, 'Transitions, PDFs, reconciliation, sandbox'],
                ['Coupon, affiliate and announcements', 17, false, 'Atomic rules, portal, queue/history'],
                ['White-label and custom domains', 15, false, 'Ownership verification and rendering'],
                ['Scoped API and offline-sale hardening', 15, false, 'Scope/revocation and immutable events'],
            ]),
            'future_addon_parity' => $this->dimension([
                ['Deferred post-v1 addon parity', 0, false, 'Out of v1 scope; informational only and excluded from release readiness'],
            ]),
            'tests' => $this->dimension([
                ['Current unit/feature regression suite', 70, true, '272 tests / 803 assertions'],
                ['Browser E2E and onboarding coverage', 10, false, 'No verified browser suite'],
                ['Concurrency matrix', 10, false, 'Only representative stock proof'],
                ['Security regression matrix', 10, false, 'Full endpoint matrix absent'],
            ]),
            'security' => $this->dimension([
                ['Tenant isolation, RBAC, audit and webhook foundations', 58, true, 'Representative automated coverage'],
                ['Full web/API/file/secret audit', 25, false, 'No complete audit report'],
                ['Sensitive admin controls and token hardening', 17, false, '2FA/re-auth/scope matrix absent'],
            ]),
            'operations' => $this->dimension([
                ['CI/reproducible build and module health', 27, true, 'GitHub Actions and local gates'],
                ['Backup/restore, staging and rollback drills', 33, false, 'No verified drill evidence'],
                ['Monitoring, alerting and load evidence', 40, false, 'No operational metrics'],
            ]),
            'production' => $this->dimension([
                ['Verified release prerequisites', 26, true, 'CI only; release gates otherwise open'],
                ['Security, operations and staging gates', 74, false, 'Not yet verified'],
            ]),
            'commercial' => $this->dimension([
                ['Baseline product/CI artifacts', 20, true, 'Repository and build artifacts'],
                ['License, onboarding, installation and support package', 40, false, 'Not completed'],
                ['In-scope core/SaaS release gates', 40, false, 'Not completed; deferred addons excluded by v1 scope freeze'],
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
