<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\Register;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RBAC critical-action matrix. Server-side authorization is mandatory;
 * hidden buttons are never counted as security.
 */
class RbacMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithOwner(string $name = 'Toko RBAC'): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->update(['current_tenant_id' => $tenant->id]);

        return [$tenant, $owner];
    }

    public function test_owner_has_all_critical_permissions(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        foreach (['pos.sale.create', 'pos.sale.void', 'register.open', 'register.close', 'register.manage', 'inventory.view', 'products.manage', 'inventory.adjust', 'inventory.transfer', 'purchase.create', 'purchase.approve', 'sales.view', 'sales.create', 'reports.view', 'settings.manage'] as $perm) {
            $this->assertTrue($owner->can($perm), "Owner must have {$perm}");
        }
    }

    public function test_role_permission_boundaries(): void
    {
        $this->seed(PlatformSeeder::class);
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $this->assertFalse($cashier->can('pos.sale.void'), 'Cashier must not void by default');
        $this->assertFalse($cashier->can('purchase.approve'), 'Cashier must not approve purchases');

        $warehouse = User::factory()->create();
        $warehouse->assignRole('warehouse');
        $this->assertFalse($warehouse->can('pos.sale.create'), 'Warehouse must not checkout POS');
        $this->assertFalse($warehouse->can('reports.view'), 'Warehouse must not view finance reports by default');

        $viewer = User::factory()->create(); // no role/permissions = viewer
        foreach (['pos.sale.create', 'purchase.approve', 'reports.view', 'settings.manage', 'register.close'] as $perm) {
            $this->assertFalse($viewer->can($perm), "Viewer must not have {$perm}");
        }
    }

    public function test_sensitive_api_actions_enforce_permissions(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('Toko RBAC API');
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        Membership::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'user_id' => $cashier->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'), 'is_active' => true]);
        $cashier->update(['current_tenant_id' => $tenant->id]);

        // Cashier without pos.sale.void cannot void.
        $resVoid = $this->actingAs($cashier)->postJson('/api/v1/sales/1/void', [], ['X-Tenant-ID' => $tenant->id]);
        $this->assertContains($resVoid->getStatusCode(), [403, 404]);
        // Viewer without sales.view cannot list sales.
        $viewer = User::factory()->create();
        Membership::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'), 'is_active' => true]);
        $viewer->update(['current_tenant_id' => $tenant->id]);
        $this->actingAs($viewer)->getJson('/api/v1/sales', ['X-Tenant-ID' => $tenant->id])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/reports/sales', ['X-Tenant-ID' => $tenant->id])->assertForbidden();
    }

    public function test_platform_admin_actions_restricted(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('Toko Platform RBAC');
        $this->actingAs($owner)->getJson('/api/v1/platform/tenants')->assertForbidden();
        $this->actingAs($owner)->get('/platform/dashboard')->assertForbidden();
        $this->actingAs($owner)->get('/platform/health')->assertForbidden();

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $admin->assignRole('platform-admin');
        $this->actingAs($admin)->getJson('/api/v1/platform/health')->assertOk();
    }

    public function test_register_and_inventory_actions_require_permissions(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('Toko Register RBAC');
        $cashier = User::factory()->create();
        Membership::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'user_id' => $cashier->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'), 'is_active' => true]);
        $cashier->update(['current_tenant_id' => $tenant->id]);

        // No register.open permission -> web register open blocked (403 or redirect with error).
        $register = Register::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'), 'name' => 'R1', 'code' => 'R1-'.uniqid(), 'is_active' => true]);
        $res = $this->actingAs($cashier)->post("/registers/{$register->id}/open", ['opening_amount' => 100000]);
        $this->assertTrue(in_array($res->getStatusCode(), [302, 403]), 'Register open without permission must be blocked');
        $this->assertFalse($cashier->can('register.open'));
        $this->assertFalse($cashier->can('register.close'));
        $this->assertTrue($owner->can('register.open'));
    }
}
