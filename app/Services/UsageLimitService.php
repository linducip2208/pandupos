<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Minimal usage meter for V1. Counts rows per tenant for guarded resources.
 * Limits come from plan_entitlements (null = unlimited).
 */
final class UsageLimitService
{
    public function __construct(private EntitlementService $entitlements) {}

    /** Map of usage key => [table, extra where] */
    private const METERS = [
        'users.max' => ['table' => 'memberships', 'column' => 'tenant_id'],
        'branches.max' => ['table' => 'branches', 'column' => 'tenant_id'],
        'warehouses.max' => ['table' => 'warehouses', 'column' => 'tenant_id'],
        'products.max' => ['table' => 'products', 'column' => 'tenant_id'],
        'customers.max' => ['table' => 'contacts', 'column' => 'tenant_id', 'where' => ['type' => 'customer']],
        'suppliers.max' => ['table' => 'contacts', 'column' => 'tenant_id', 'where' => ['type' => 'supplier']],
        'invoices.monthly' => ['table' => 'sales_invoices', 'column' => 'tenant_id', 'monthly' => true],
    ];

    public function count(int $tenantId, string $usageKey): int
    {
        $meter = self::METERS[$usageKey] ?? null;

        if (! $meter) {
            return 0;
        }

        $q = DB::table($meter['table'])->where($meter['column'], $tenantId);

        foreach ($meter['where'] ?? [] as $col => $val) {
            $q->where($col, $val);
        }

        if (! empty($meter['monthly'])) {
            $q->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
        }

        return (int) $q->count();
    }

    public function limit(int $tenantId, string $usageKey): ?int
    {
        $raw = $this->entitlements->value($tenantId, $usageKey);

        if ($raw === null) {
            return null; // unlimited
        }

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /** Throw when creation would exceed plan limit. */
    public function assertCanCreate(int $tenantId, string $usageKey): void
    {
        $limit = $this->limit($tenantId, $usageKey);

        if ($limit === null) {
            return;
        }

        if ($this->count($tenantId, $usageKey) >= $limit) {
            abort(403, "Usage limit reached for [{$usageKey}]: {$limit}. Upgrade your plan.");
        }
    }

    public function snapshot(int $tenantId): array
    {
        $out = [];
        foreach (array_keys(self::METERS) as $key) {
            $used = $this->count($tenantId, $key);
            $limit = $this->limit($tenantId, $key);
            // Consistent unlimited representation: limit null, percent 0.
            $percent = $limit === null || $limit <= 0 ? 0 : (int) min(100, round($used / $limit * 100));
            $short = str_replace(['.max', '.monthly'], '', $key);
            $entry = ['current' => $used, 'used' => $used, 'limit' => $limit, 'percent' => $percent, 'unlimited' => $limit === null];
            $out[$key] = $entry;
            $out[$short] = $entry;
        }

        return $out;
    }
}
