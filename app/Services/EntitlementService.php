<?php

namespace App\Services;

use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Central entitlement engine.
 * Reads active subscription -> plan_entitlements. Cached per-tenant.
 */
final class EntitlementService
{
    public function allowed(int|string $tenantId, string $key): bool
    {
        $map = $this->all((int) $tenantId);

        if (! array_key_exists($key, $map)) {
            return false;
        }

        $value = $map[$key];

        if ($value === null) {
            return true; // unlimited implies allowed
        }

        // boolean-ish
        if ($value === '1' || $value === 1 || $value === true || $value === 'true') {
            return true;
        }

        if ($value === '0' || $value === 0 || $value === false || $value === 'false') {
            return false;
        }

        // numeric limit > 0 means allowed (actual count checked by UsageLimitService)
        return is_numeric($value) && (int) $value !== 0;
    }

    public function value(int|string $tenantId, string $key, mixed $default = null): mixed
    {
        return $this->all((int) $tenantId)[$key] ?? $default;
    }

    /** @return array<string, mixed> entitlement => value */
    public function all(int|string $tenantId): array
    {
        $tenantId = (int) $tenantId;

        return Cache::remember("tenant:{$tenantId}:entitlements", 300, function () use ($tenantId) {
            $sub = Subscription::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', Subscription::ACTIVE_STATUSES)
                ->orderByDesc('current_period_end')
                ->first();

            if (! $sub) {
                return [];
            }

            return PlanEntitlement::query()
                ->where('plan_id', $sub->plan_id)
                ->pluck('value', 'entitlement')
                ->all();
        });
    }

    public function forget(int|string $tenantId): void
    {
        Cache::forget("tenant:{$tenantId}:entitlements");
    }
}
