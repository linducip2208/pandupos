<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $modules = [
            ['slug' => 'pos', 'name' => 'POS', 'is_core' => true, 'metadata' => ['dependencies' => ['inventory']]],
            ['slug' => 'inventory', 'name' => 'Inventory', 'is_core' => true, 'metadata' => ['dependencies' => []]],
            ['slug' => 'purchasing', 'name' => 'Purchasing', 'is_core' => true, 'metadata' => ['dependencies' => ['inventory']]],
            ['slug' => 'sales', 'name' => 'Sales', 'is_core' => true, 'metadata' => ['dependencies' => ['inventory']]],
            ['slug' => 'accounting', 'name' => 'Accounting', 'is_core' => false, 'is_paid' => true, 'metadata' => ['dependencies' => []]],
            ['slug' => 'crm', 'name' => 'CRM', 'is_core' => false, 'metadata' => ['dependencies' => []]],
            ['slug' => 'manufacturing', 'name' => 'Manufacturing', 'is_core' => false, 'metadata' => ['dependencies' => ['inventory']]],
            ['slug' => 'repair', 'name' => 'Repair', 'is_core' => false, 'metadata' => ['dependencies' => ['inventory']]],
            ['slug' => 'project', 'name' => 'Project', 'is_core' => false, 'metadata' => ['dependencies' => []]],
            ['slug' => 'asset', 'name' => 'Asset Management', 'is_core' => false, 'metadata' => ['dependencies' => []]],
        ];

        foreach ($modules as $m) {
            Module::updateOrCreate(['slug' => $m['slug']], $m + [
                'description' => $m['name'].' module',
                'version' => '1.0.0',
                'category' => 'business',
                'status' => 'installed',
            ]);
        }

        $starter = Plan::updateOrCreate(['slug' => 'starter'], [
            'name' => 'Starter',
            'description' => 'Core POS + Inventory for small retail',
            'monthly_price' => 99000,
            'yearly_price' => 990000,
            'trial_days' => 14,
            'is_active' => true,
            'is_public' => true,
        ]);

        $growth = Plan::updateOrCreate(['slug' => 'growth'], [
            'name' => 'Growth',
            'description' => 'Multi-branch + multi-warehouse',
            'monthly_price' => 249000,
            'yearly_price' => 2490000,
            'trial_days' => 14,
            'is_active' => true,
            'is_public' => true,
            'is_featured' => true,
        ]);

        $entitlements = [
            'starter' => [
                'pos.access' => '1', 'inventory.access' => '1', 'purchase.access' => '1', 'sales.access' => '1',
                'pos.multi_register' => '0', 'inventory.multi_warehouse' => '0',
                'users.max' => '5', 'branches.max' => '1', 'warehouses.max' => '1', 'products.max' => '1000',
            ],
            'growth' => [
                'pos.access' => '1', 'inventory.access' => '1', 'purchase.access' => '1', 'sales.access' => '1',
                'pos.multi_register' => '1', 'inventory.multi_warehouse' => '1',
                'users.max' => '15', 'branches.max' => '3', 'warehouses.max' => '3', 'products.max' => '10000',
            ],
        ];

        foreach ($entitlements as $slug => $map) {
            $plan = $slug === 'starter' ? $starter : $growth;
            foreach ($map as $key => $value) {
                $plan->entitlements()->updateOrCreate(['entitlement' => $key], ['value' => $value]);
            }
        }

        // Spatie roles (platform-level; tenant scoping via memberships + policies in later phases).
        foreach (['platform-admin', 'tenant-owner', 'tenant-admin', 'manager', 'cashier', 'warehouse', 'purchasing', 'sales'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach (['pos.sale.create', 'pos.sale.void', 'register.manage', 'register.open', 'register.close', 'inventory.view', 'products.manage', 'inventory.adjust', 'inventory.transfer', 'purchase.create', 'purchase.approve', 'sales.view', 'sales.create', 'reports.view', 'settings.manage', 'accounting.view', 'accounting.manage', 'crm.view', 'crm.manage', 'mrp.view', 'mrp.manage', 'repair.view', 'repair.manage', 'project.view', 'project.manage', 'asset.view', 'asset.manage'] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        foreach ([
            'platform.dashboard.view',
            'platform.tenants.view', 'platform.tenants.create', 'platform.tenants.update',
            'platform.tenants.suspend', 'platform.tenants.activate', 'platform.tenants.impersonate',
            'platform.plans.manage', 'platform.subscriptions.manage',
            'platform.billing.view', 'platform.billing.manage',
            'platform.modules.manage', 'platform.entitlements.manage',
            'platform.coupons.manage', 'platform.affiliates.manage',
            'platform.announcements.manage', 'platform.audit.view', 'platform.settings.manage',
        ] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $platformAdmin = Role::findOrCreate('platform-admin', 'web');
        $platformAdmin->givePermissionTo([
            'platform.dashboard.view',
            'platform.tenants.view', 'platform.tenants.create', 'platform.tenants.update',
            'platform.tenants.suspend', 'platform.tenants.activate', 'platform.tenants.impersonate',
            'platform.plans.manage', 'platform.subscriptions.manage',
            'platform.billing.view', 'platform.billing.manage',
            'platform.modules.manage', 'platform.entitlements.manage',
            'platform.coupons.manage', 'platform.affiliates.manage',
            'platform.announcements.manage', 'platform.audit.view', 'platform.settings.manage',
        ]);
    }
}
