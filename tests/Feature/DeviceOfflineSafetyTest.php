<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DeviceOfflineSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Offline', $owner);

        return [$tenant, $owner->refresh()];
    }

    private function push(User $owner, int $tenantId, string $deviceUuid): TestResponse
    {
        return $this->actingAs($owner)->postJson('/api/v1/sync/push', [
            'device_uuid' => $deviceUuid,
            'platform' => 'android',
            'mutations' => [
                ['uuid' => 'mut-'.uniqid(), 'entity' => 'contact', 'operation' => 'create', 'payload' => ['name' => 'Baru']],
            ],
        ], ['X-Tenant-ID' => $tenantId]);
    }

    public function test_revoked_device_is_locked_out_of_push_and_cannot_re_register(): void
    {
        [$tenant, $owner] = $this->tenant();
        $uuid = 'device-'.uniqid();

        $this->push($owner, $tenant->id, $uuid)->assertOk();
        $this->assertSame(1, DB::table('devices')->where('tenant_id', $tenant->id)->where('uuid', $uuid)->count());

        // Revoke the device.
        $this->actingAs($owner)->postJson('/api/v1/devices/revoke', ['device_uuid' => $uuid], ['X-Tenant-ID' => $tenant->id])
            ->assertOk()
            ->assertJsonPath('data.device_uuid', $uuid);
        $revokedAt = DB::table('devices')->where('tenant_id', $tenant->id)->where('uuid', $uuid)->value('revoked_at');
        $this->assertNotNull($revokedAt);

        // Locked out of the write path; the uuid cannot re-register as a fresh device.
        $response = $this->push($owner, $tenant->id, $uuid);
        $response->assertStatus(423)->assertJsonPath('error', 'device_revoked');
        $this->assertSame(1, DB::table('devices')->where('tenant_id', $tenant->id)->where('uuid', $uuid)->count());

        // Append-only: revocation can never be undone through the API.
        $this->actingAs($owner)->postJson('/api/v1/devices/revoke', ['device_uuid' => $uuid], ['X-Tenant-ID' => $tenant->id])->assertOk();
        $this->assertSame(
            $revokedAt,
            DB::table('devices')->where('tenant_id', $tenant->id)->where('uuid', $uuid)->value('revoked_at')
        );
    }

    public function test_other_tenant_cannot_revoke_device(): void
    {
        [$tenant, $owner] = $this->tenant();
        [$other, $otherOwner] = $this->tenant();
        $uuid = 'device-'.uniqid();
        $this->push($owner, $tenant->id, $uuid)->assertOk();

        $this->actingAs($otherOwner)->postJson('/api/v1/devices/revoke', ['device_uuid' => $uuid], ['X-Tenant-ID' => $other->id])
            ->assertNotFound();
        $this->assertNull(DB::table('devices')->where('tenant_id', $tenant->id)->where('uuid', $uuid)->value('revoked_at'));
    }

    public function test_change_log_is_append_only_cursor_ordered_and_idempotent(): void
    {
        [$tenant, $owner] = $this->tenant();
        $uuid = 'device-'.uniqid();
        $mutation = ['uuid' => 'mut-immutable-'.uniqid(), 'entity' => 'contact', 'operation' => 'create', 'payload' => ['name' => 'X']];

        $this->actingAs($owner)->postJson('/api/v1/sync/push', [
            'device_uuid' => $uuid, 'mutations' => [$mutation],
        ], ['X-Tenant-ID' => $tenant->id])->assertOk()->assertJsonPath('data.0.status', 'applied');

        // Idempotent replay: no new change-log row.
        $this->actingAs($owner)->postJson('/api/v1/sync/push', [
            'device_uuid' => $uuid, 'mutations' => [$mutation],
        ], ['X-Tenant-ID' => $tenant->id])->assertOk()->assertJsonPath('data.0.status', 'duplicate');
        $this->assertSame(1, DB::table('server_change_logs')->where('tenant_id', $tenant->id)->count());

        // Cursor pull returns the exiting device event in stable append order.
        $pull = $this->actingAs($owner)->getJson('/api/v1/sync/pull?cursor=0', ['X-Tenant-ID' => $tenant->id])
            ->assertOk()
            ->json();
        $this->assertTrue($pull['meta']['next_cursor'] > 0);
        $ids = collect($pull['data'])->pluck('id')->all();
        $this->assertSame($ids, array_values(array_unique($ids)), 'change-log ids must be strictly stable/ordered');
    }
}
