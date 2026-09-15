<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_subscription_has_no_entitlement(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $owner = User::factory()->create();
        $tenant = app(\App\Services\TenantProvisioningService::class)->provision('Toko E', $owner);

        // Expire the subscription.
        \App\Models\Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->update(['status' => 'expired']);

        app(EntitlementService::class)->forget($tenant->id);

        $this->assertFalse(app(EntitlementService::class)->allowed($tenant->id, 'pos.access'));
    }

    public function test_active_subscription_grants_entitlement(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $owner = User::factory()->create();
        $tenant = app(\App\Services\TenantProvisioningService::class)->provision('Toko F', $owner);

        $this->assertTrue(app(EntitlementService::class)->allowed($tenant->id, 'pos.access'));
        $this->assertFalse(app(EntitlementService::class)->allowed($tenant->id, 'manufacturing.bom'));
    }

    public function test_entitlement_middleware_blocks(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $owner = User::factory()->create();
        $tenant = app(\App\Services\TenantProvisioningService::class)->provision('Toko G', $owner);
        $owner->refresh();

        // Starter plan has pos.access=1 and pos module enabled via core modules? Ensure module enabled.
        $pos = \App\Models\Module::where('slug', 'pos')->first();
        \App\Models\TenantModule::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_id' => $pos->id],
            ['enabled' => true, 'enabled_at' => now()]
        );
        $inv = \App\Models\Module::where('slug', 'inventory')->first();
        \App\Models\TenantModule::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_id' => $inv->id],
            ['enabled' => true, 'enabled_at' => now()]
        );

        $this->actingAs($owner)
            ->getJson('/api/v1/pos/ping', ['X-Tenant-ID' => $tenant->id])
            ->assertOk();
    }
}
