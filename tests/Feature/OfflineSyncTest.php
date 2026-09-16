<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_is_idempotent_and_pull_cursor(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Sync', $owner);
        $owner->refresh();

        $payload = [
            'device_uuid' => 'dev-123',
            'mutations' => [
                ['uuid' => 'm-1', 'entity' => 'product', 'operation' => 'upsert', 'payload' => ['name' => 'A']],
                ['uuid' => 'm-2', 'entity' => 'sales_invoice', 'operation' => 'create', 'payload' => ['total' => 1000]],
            ],
        ];

        $r1 = $this->actingAs($owner)->postJson('/api/v1/sync/push', $payload, ['X-Tenant-ID' => $tenant->id])->assertOk();
        $r2 = $this->actingAs($owner)->postJson('/api/v1/sync/push', $payload, ['X-Tenant-ID' => $tenant->id])->assertOk();
        $this->assertEquals('applied', $r1->json('data.0.status'));
        $this->assertEquals('duplicate', $r2->json('data.0.status'));

        $pull = $this->actingAs($owner)->getJson('/api/v1/sync/pull?limit=10', ['X-Tenant-ID' => $tenant->id])->assertOk();
        $this->assertArrayHasKey('data', $pull->json());
        $this->assertArrayHasKey('meta', $pull->json());
        $this->assertArrayHasKey('next_cursor', $pull->json('meta'));
    }

    public function test_immutable_entity_conflict(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Sync2', $owner);
        $owner->refresh();

        $this->actingAs($owner)->postJson('/api/v1/sync/push', [
            'device_uuid' => 'dev-1',
            'mutations' => [['uuid' => 'inv-1', 'entity' => 'sales_invoice', 'operation' => 'update', 'payload' => []]],
        ], ['X-Tenant-ID' => $tenant->id])->assertOk()->assertJsonPath('data.0.status', 'conflict');
    }
}
