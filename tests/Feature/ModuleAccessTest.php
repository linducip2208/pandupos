<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_module_blocks_protected_route(): void
    {
        $this->seed(PlatformSeeder::class);

        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko M', $owner);
        $owner->refresh();

        $pos = Module::where('slug', 'pos')->firstOrFail();
        TenantModule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('module_id', $pos->id)
            ->update(['enabled' => false, 'disabled_at' => now()]);

        $this->actingAs($owner)
            ->getJson('/api/v1/pos/ping', ['X-Tenant-ID' => $tenant->id])
            ->assertForbidden();
    }

    public function test_rbac_permission_required_for_void(): void
    {
        $this->seed(PlatformSeeder::class);

        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->assertTrue($cashier->hasRole('cashier'));
        $this->assertFalse($cashier->hasPermissionTo('pos.sale.void'));

        $manager = User::factory()->create();
        $manager->givePermissionTo('pos.sale.void');

        $this->assertTrue($manager->hasPermissionTo('pos.sale.void'));
    }
}
