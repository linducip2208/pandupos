<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates tenant + branch + subscription + module enablement atomically. */
final class TenantProvisioningService
{
    public function provision(
        string $name,
        User $owner,
        ?Plan $plan = null,
        string $status = 'trial',
    ): Tenant {
        return DB::transaction(function () use ($name, $owner, $plan, $status) {
            $tenant = Tenant::create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'status' => $status,
                'trial_ends_at' => now()->addDays($plan?->trial_days ?? 14),
            ]);

            $branch = Branch::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => 'Pusat',
                'code' => 'PST',
                'is_active' => true,
            ]);

            $owner->memberships()->create([
                'tenant_id' => $tenant->getKey(),
                'branch_ids' => [$branch->getKey()],
            ]);
            // A tenant owner receives the tenant-scoped role at provisioning.
            // Platform permissions remain isolated on the platform-admin role.
            $owner->assignRole('tenant-owner');
            $owner->forceFill(['current_tenant_id' => $tenant->getKey()])->save();

            $plan ??= Plan::where('slug', 'starter')->first();

            if ($plan) {
                Subscription::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->getKey(),
                    'plan_id' => $plan->getKey(),
                    'status' => 'trialing',
                    'billing_cycle' => 'monthly',
                    'starts_at' => now(),
                    'trial_ends_at' => $tenant->trial_ends_at,
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                ]);
            }

            // Enable core modules by default.
            $core = Module::where('is_core', true)->pluck('id');
            foreach ($core as $moduleId) {
                TenantModule::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->getKey(),
                    'module_id' => $moduleId,
                    'enabled' => true,
                    'enabled_at' => now(),
                ]);
            }

            return $tenant;
        });
    }
}
