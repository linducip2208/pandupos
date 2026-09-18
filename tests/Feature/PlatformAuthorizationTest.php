<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_platform(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create(['is_platform_admin' => false]);

        $this->actingAs($user)->get('/platform/dashboard')->assertForbidden();
        $this->actingAs($user)->get('/platform/tenants')->assertForbidden();
        $this->actingAs($user)->get('/platform/health')->assertForbidden();
    }

    public function test_platform_admin_can_access_dashboard(): void
    {
        $this->seed(PlatformSeeder::class);
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->get('/platform/dashboard')->assertOk();
        $this->actingAs($admin)->get('/platform/tenants')->assertOk();
        $this->actingAs($admin)->get('/platform/plans')->assertOk();
        $this->actingAs($admin)->get('/platform/modules')->assertOk();
        cache()->forget('health.inventory-reconciliation');
        $this->actingAs($admin)->get('/platform/health')->assertOk()
            ->assertSee('Inventory reconciliation')->assertSee('PASS');
    }
}
