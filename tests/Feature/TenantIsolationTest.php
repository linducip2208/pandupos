<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_a_cannot_read_tenant_b_branches(): void
    {
        $a = Tenant::create(['uuid' => (string) \Str::uuid(), 'name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $b = Tenant::create(['uuid' => (string) \Str::uuid(), 'name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);

        Branch::withoutGlobalScopes()->create(['tenant_id' => $a->id, 'name' => 'A-1', 'code' => 'A1']);
        Branch::withoutGlobalScopes()->create(['tenant_id' => $b->id, 'name' => 'B-1', 'code' => 'B1']);

        $seenAsA = TenantContext::runAs($a, fn () => Branch::all()->pluck('name')->all());
        $seenAsB = TenantContext::runAs($b, fn () => Branch::all()->pluck('name')->all());

        $this->assertEquals(['A-1'], $seenAsA);
        $this->assertEquals(['B-1'], $seenAsB);
    }

    public function test_middleware_blocks_non_member(): void
    {
        $this->seed(PlatformSeeder::class);

        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $tenant = app(TenantProvisioningService::class)->provision('Toko A', $owner);

        $this->actingAs($outsider)
            ->getJson('/api/v1/me', ['X-Tenant-ID' => $tenant->id])
            ->assertForbidden();
    }
}
