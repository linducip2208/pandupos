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
use Spatie\Permission\Models\Permission;

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
            // Provisioning is used before roles are seeded in isolated flows.
            // Direct grants preserve explicit revoke semantics; platform grants
            // are never included here.
            $tenantPermissions = Permission::query()->whereIn('name', [
                'pos.sale.create', 'pos.sale.void', 'register.manage', 'register.open', 'register.close', 'inventory.view', 'products.manage',
                'inventory.adjust', 'inventory.transfer', 'purchase.create', 'purchase.approve',
                'sales.view', 'sales.create', 'reports.view', 'settings.manage', 'accounting.view', 'accounting.manage', 'crm.view', 'crm.manage', 'mrp.view', 'mrp.manage', 'repair.view', 'repair.manage', 'project.view', 'project.manage', 'asset.view', 'asset.manage', 'hrm.view', 'hrm.manage', 'payroll.view', 'payroll.manage', 'ecommerce.view', 'ecommerce.manage', 'woocommerce.view', 'woocommerce.manage', 'hms.view', 'hms.manage', 'gym.view', 'gym.manage', 'ai.view', 'ai.manage',
            ])->get();
            if ($tenantPermissions->isNotEmpty()) {
                $owner->givePermissionTo($tenantPermissions);
            }
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
