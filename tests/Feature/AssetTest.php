<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use App\Services\AssetService;
use App\Services\ModuleManager;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Asset module: registration, depreciation schedules, transfers,
 * maintenance, disposal with gain/loss, isolation, RBAC and API.
 */
class AssetTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Asset', bool $enableAsset = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableAsset) {
            app(ModuleManager::class)->enable($tenant->id, 'asset');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko Asset Gate', false);

        $this->actingAs($owner)->get('/assets')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/assets', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'asset');
        $this->actingAs($owner)->get('/assets')->assertOk()->assertSeeText('Daftar aset');
    }

    public function test_straight_line_schedule_and_disposal_gain(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(AssetService::class);

        // Bought 2026-01-15 for 12jt, salvage 0, 12 months → 1jt/month.
        $asset = $svc->register($tenant->id, [
            'name' => 'Laptop', 'purchase_date' => '2026-01-15',
            'purchase_cost' => 12000000, 'salvage_value' => 0, 'useful_life_months' => 12,
        ], $owner->id);

        $rows = $svc->schedule($asset, '2026-06-20');
        $this->assertCount(6, $rows);
        $this->assertSame(1000000.0, $rows[0]['depreciation']);
        $this->assertSame(6000000.0, end($rows)['accumulated']);
        $this->assertSame(6000000.0, $svc->bookValue($asset, '2026-06-20'));

        $done = $svc->dispose($asset, 6500000, '2026-06-20', $owner->id);
        $this->assertSame('disposed', $done->status);
        $this->assertSame(500000.0, (float) $done->disposal_gain_loss);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'asset.disposed']);
    }

    public function test_declining_balance_never_below_salvage(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(AssetService::class);

        $asset = $svc->register($tenant->id, [
            'name' => 'Mobil', 'purchase_date' => '2026-01-01',
            'purchase_cost' => 200000000, 'salvage_value' => 20000000,
            'useful_life_months' => 60, 'depreciation_method' => 'declining_balance',
        ], $owner->id);

        $rows = $svc->schedule($asset, '2031-01-01'); // full life
        $this->assertCount(60, $rows);
        $last = end($rows);
        $this->assertGreaterThanOrEqual(20000000.0, $last['book']);
        $this->assertLessThan(200000000.0, $last['book']);
        // First month uses double declining rate on full cost.
        $this->assertSame(round(200000000 * (2 / 60), 2), $rows[0]['depreciation']);
    }

    public function test_transfer_maintenance_and_disposed_guards(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(AssetService::class);
        $tech = User::factory()->create();

        $asset = $svc->register($tenant->id, ['name' => 'Printer', 'purchase_cost' => 3000000, 'useful_life_months' => 36], $owner->id);
        $moved = $svc->transfer($asset, $tech->id, 'Cabang A', $owner->id, 'Mutasi');
        $this->assertSame('assigned', $moved->status);
        $this->assertSame($tech->id, $moved->custodian_id);
        $this->assertSame(1, $moved->transfers()->count());

        $svc->logMaintenance($moved, ['kind' => 'corrective', 'cost' => 250000], $owner->id);
        $this->assertSame('maintenance', $moved->refresh()->status);
        $svc->returnFromMaintenance($moved->refresh(), $owner->id);
        $this->assertSame('assigned', $moved->refresh()->status);

        $svc->dispose($moved->refresh(), 1000000, now()->toDateString(), $owner->id);
        foreach (['transfer', 'maintain', 'dispose'] as $op) {
            try {
                match ($op) {
                    'transfer' => $svc->transfer($moved->refresh(), null, 'X', $owner->id),
                    'maintain' => $svc->logMaintenance($moved->refresh(), [], $owner->id),
                    'dispose' => $svc->dispose($moved->refresh(), 0, now()->toDateString(), $owner->id),
                };
                $this->fail("Disposed asset must reject [{$op}].");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_validation_rejects_bad_inputs(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(AssetService::class);

        foreach ([
            ['name' => '', 'purchase_cost' => 100, 'useful_life_months' => 12],
            ['name' => 'X', 'purchase_cost' => 0, 'useful_life_months' => 12],
            ['name' => 'X', 'purchase_cost' => 100, 'salvage_value' => 100, 'useful_life_months' => 12],
            ['name' => 'X', 'purchase_cost' => 100, 'useful_life_months' => 0],
        ] as $bad) {
            try {
                $svc->register($tenant->id, $bad, $owner->id);
                $this->fail('Invalid asset input must be rejected: '.json_encode($bad));
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko Asset A');
        [$tenantB, $ownerB] = $this->context('Toko Asset B');
        $assetA = app(AssetService::class)->register($tenantA->id, ['name' => 'Rahasia A', 'purchase_cost' => 1000, 'useful_life_months' => 12], null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, Asset::query()->count());
        $this->actingAs($ownerB)->get('/assets')->assertOk()->assertDontSee('Rahasia A');
        $this->actingAs($ownerB)->get("/assets/{$assetA->id}/schedule")->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/assets')->assertForbidden();
        $member->givePermissionTo('asset.view');
        $this->actingAs($member)->get('/assets')->assertOk();
        $this->actingAs($member)->post('/assets', ['name' => 'X'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];

        $created = $this->actingAs($owner)->postJson('/api/v1/assets', [
            'name' => 'API Server', 'purchase_cost' => 24000000, 'useful_life_months' => 24,
        ], $headers)->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($owner)->getJson("/api/v1/assets/{$id}/schedule", $headers)->assertOk()
            ->assertJsonPath('data.book_value', 23000000); // purchase month counts as month 1
        // Invalid method is rejected.
        $this->actingAs($owner)->postJson('/api/v1/assets', [
            'name' => 'Bad', 'purchase_cost' => 100, 'useful_life_months' => 12, 'depreciation_method' => 'magic',
        ], $headers)->assertStatus(422);
        $this->actingAs($owner)->postJson("/api/v1/assets/{$id}/dispose", ['proceeds' => 5000000], $headers)->assertOk()
            ->assertJsonPath('data.status', 'disposed');
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/assets', ['name' => 'Web AC', 'purchase_cost' => 5000000, 'useful_life_months' => 60])->assertRedirect();
        $asset = Asset::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->get("/assets/{$asset->id}/schedule")->assertOk()->assertSeeText('Nilai buku');
        $this->actingAs($owner)->post("/assets/{$asset->id}/transfer", ['to_location' => 'Ruang A'])->assertRedirect();
        $this->actingAs($owner)->post("/assets/{$asset->id}/maintain", ['cost' => 100000])->assertRedirect();
        $this->actingAs($owner)->post("/assets/{$asset->id}/return")->assertRedirect();
        $this->actingAs($owner)->post("/assets/{$asset->id}/dispose", ['proceeds' => 1000000])->assertRedirect();
        $this->assertSame('disposed', $asset->refresh()->status);
        $this->actingAs($owner)->get('/assets')->assertOk()->assertSeeText('Web AC');
    }
}
