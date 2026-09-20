<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\MrpBom;
use App\Models\MrpWorkOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ModuleManager;
use App\Services\MrpService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Manufacturing module: versioned multi-level BOMs, guarded work-order
 * lifecycle, availability-checked release, costed production with scrap,
 * tenant isolation, RBAC and API.
 */
class MrpTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko MRP', bool $enableMrp = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableMrp) {
            app(ModuleManager::class)->enable($tenant->id, 'manufacturing');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    /** @return array{Branch, Warehouse, finished, compA, compB} */
    private function factory(string $prefix, $tenant): array
    {
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang '.$prefix, 'code' => 'WH-'.uniqid()]);
        $mk = function (string $sku, float $price) use ($tenant) {
            $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => $sku, 'sku' => $sku, 'product_type' => 'stock', 'track_inventory' => true]);

            return ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'Default', 'sku' => $sku.'-V', 'purchase_price' => $price, 'sell_price' => $price * 2]);
        };

        return [$branch, $warehouse, $mk($prefix.'-FG', 0), $mk($prefix.'-A', 100), $mk($prefix.'-B', 50)];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko MRP Gate', false);

        $this->actingAs($owner)->get('/mrp/boms')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/mrp/boms', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'manufacturing');
        $this->actingAs($owner)->get('/mrp/boms')->assertOk()->assertSeeText('BOM');
    }

    public function test_bom_validates_and_versions(): void
    {
        [$tenant, $owner] = $this->context();
        [, , $fg, $a, $b] = $this->factory('V', $tenant);
        $svc = app(MrpService::class);

        // Self reference is rejected.
        try {
            $svc->createBom($tenant->id, $fg->id, [['component_variant_id' => $fg->id, 'quantity' => 1]], $owner->id);
            $this->fail('Self-referencing BOM must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $v1 = $svc->createBom($tenant->id, $fg->id, [
            ['component_variant_id' => $a->id, 'quantity' => 2],
            ['component_variant_id' => $b->id, 'quantity' => 3, 'scrap_rate' => 0.1],
        ], $owner->id);
        $this->assertSame(1, $v1->version);

        // A new revision deactivates the previous one.
        $v2 = $svc->createBom($tenant->id, $fg->id, [['component_variant_id' => $a->id, 'quantity' => 1]], $owner->id);
        $this->assertSame(2, $v2->version);
        $this->assertFalse($v1->refresh()->is_active);
        $this->assertTrue($v2->refresh()->is_active);

        // Explosion honors quantities and scrap rates.
        $reqs = collect($svc->explode($tenant->id, $v1->id, 10))->keyBy('variant_id');
        $this->assertSame(20.0, $reqs[$a->id]['quantity']);
        $this->assertSame(33.0, $reqs[$b->id]['quantity']); // 10 * 3 * 1.1
    }

    public function test_multilevel_explosion_and_cycle_guard(): void
    {
        [$tenant, $owner] = $this->context();
        [, , $fg, $a, $b] = $this->factory('M', $tenant);
        $svc = app(MrpService::class);

        // Sub-assembly: A is itself manufactured from B.
        $sub = $svc->createBom($tenant->id, $a->id, [['component_variant_id' => $b->id, 'quantity' => 4]], $owner->id);
        $top = $svc->createBom($tenant->id, $fg->id, [['component_variant_id' => $a->id, 'quantity' => 2]], $owner->id);

        $reqs = collect($svc->explode($tenant->id, $top->id, 5))->keyBy('variant_id');
        $this->assertFalse(isset($reqs[$a->id]), 'Sub-assemblies explode to raw materials.');
        $this->assertSame(40.0, $reqs[$b->id]['quantity']); // 5 * 2 * 4

        // Closing the loop (B made of FG) must be rejected at creation.
        try {
            $svc->createBom($tenant->id, $b->id, [['component_variant_id' => $fg->id, 'quantity' => 1]], $owner->id);
            $this->fail('Circular BOM must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_release_blocks_on_shortage_and_full_flow_costs_production(): void
    {
        [$tenant, $owner] = $this->context();
        [, $warehouse, $fg, $a, $b] = $this->factory('F', $tenant);
        $svc = app(MrpService::class);
        $this->actingAs($owner);

        $bom = $svc->createBom($tenant->id, $fg->id, [
            ['component_variant_id' => $a->id, 'quantity' => 2],
            ['component_variant_id' => $b->id, 'quantity' => 1],
        ], $owner->id);
        $order = $svc->createWorkOrder($tenant->id, $bom->id, $warehouse->id, 10, $owner->id);
        $this->assertSame('draft', $order->status);

        // No stock: release lists exact shortages.
        try {
            $svc->release($order, $owner->id);
            $this->fail('Release without materials must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($a->sku, $e->getMessage());
        }

        app(StockService::class)->increase($tenant->id, $warehouse->id, $a->id, 20, 100, 'opening', null);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $b->id, 10, 50, 'opening', null);

        $svc->release($order, $owner->id);
        $svc->start($order, $owner->id);

        // Produce 6 good + 1 scrap: consumes 7 units of basis (A:14, B:7).
        $svc->recordProduction($order->refresh(), 6, 1, $owner->id);
        $mid = $order->refresh();
        $this->assertSame('1750.00', number_format((float) $mid->material_cost, 2, '.', ''));
        $this->assertSame(6.0, (float) $mid->quantity_produced);

        // Over-production beyond plan is refused.
        try {
            $svc->recordProduction($mid, 5, 0, $owner->id);
            $this->fail('Over-production must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->recordProduction($mid->refresh(), 3, 0, $owner->id);
        $done = $svc->finish($order->refresh(), $owner->id);
        $this->assertSame('done', $done->status);
        // 9 good units from (20×100 + 10×50) = 2500 material → 277.78/unit.
        $this->assertSame('2500.00', number_format((float) $done->material_cost, 2, '.', ''));
        $this->assertSame('277.78', number_format((float) $done->unit_cost, 2, '.', ''));

        // Traceability: every movement carries the work-order provenance
        // (two receipts and four consumptions: A+B across two recordings).
        $this->assertSame(2, StockMovement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('reference_type', 'mrp_produce')->where('reference_id', $done->id)->count());
        $this->assertSame(4, StockMovement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('reference_type', 'mrp_consume')->where('reference_id', $done->id)->count());
        // Finished stock on hand equals good output.
        $this->assertSame(9.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $fg->id));
    }

    public function test_cancel_rules(): void
    {
        [$tenant, $owner] = $this->context();
        [, $warehouse, $fg, $a] = $this->factory('C', $tenant);
        $svc = app(MrpService::class);

        $bom = $svc->createBom($tenant->id, $fg->id, [['component_variant_id' => $a->id, 'quantity' => 1]], $owner->id);
        $order = $svc->createWorkOrder($tenant->id, $bom->id, $warehouse->id, 5, $owner->id);
        $this->assertSame('cancelled', $svc->cancel($order, $owner->id)->status);

        app(StockService::class)->increase($tenant->id, $warehouse->id, $a->id, 5, 10, 'opening', null);
        $order2 = $svc->createWorkOrder($tenant->id, $bom->id, $warehouse->id, 5, $owner->id);
        $svc->release($order2, $owner->id);
        $svc->start($order2, $owner->id);
        $svc->recordProduction($order2->refresh(), 1, 0, $owner->id);
        try {
            $svc->cancel($order2->refresh(), $owner->id);
            $this->fail('Cancelling after consumption must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko MRP A');
        [$tenantB, $ownerB] = $this->context('Toko MRP B');
        [, , $fgA, $aA] = $this->factory('IA', $tenantA);
        $bomA = app(MrpService::class)->createBom($tenantA->id, $fgA->id, [['component_variant_id' => $aA->id, 'quantity' => 1]], null);

        // Scoped queries never leak A's BOMs into B, in UI or API.
        TenantContext::setId($tenantB->id);
        $this->assertSame(0, MrpBom::query()->count());
        $this->actingAs($ownerB)->get('/mrp/boms')->assertOk()->assertDontSee($fgA->sku);
        $this->actingAs($ownerB)->getJson('/api/v1/mrp/boms', ['X-Tenant-ID' => $tenantB->id])
            ->assertOk()->assertJsonCount(0, 'data');
        // Cross-tenant binding resolves to 404.
        $this->actingAs($ownerB)->postJson('/api/v1/mrp/orders', [
            'bom_id' => $bomA->id, 'warehouse_id' => 1, 'quantity_planned' => 1,
        ], ['X-Tenant-ID' => $tenantB->id])->assertStatus(404);
    }

    public function test_cross_tenant_bom_reference_is_rejected(): void
    {
        [$tenantA] = $this->context('Toko MRP XA');
        [$tenantB] = $this->context('Toko MRP XB');
        [, , $fgB, $aB] = $this->factory('XB', $tenantB);
        [, , $fgA] = $this->factory('XA', $tenantA);
        TenantContext::setId($tenantB->id);

        try {
            app(MrpService::class)->createBom($tenantB->id, $fgB->id, [['component_variant_id' => $fgA->id, 'quantity' => 1]], null);
            $this->fail('Cross-tenant component must be rejected.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, MrpBom::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    public function test_rbac_member_denied(): void
    {
        [$tenant] = $this->context('Toko MRP RBAC');
        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenant->id])->save();
        TenantContext::setId($tenant->id);
        $this->actingAs($member)->get('/mrp/boms')->assertForbidden();
        $member->givePermissionTo('mrp.view');
        $this->actingAs($member)->get('/mrp/boms')->assertOk();
        $this->actingAs($member)->post('/mrp/boms', [])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        [, $warehouse, $fg, $a] = $this->factory('API', $tenant);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $bom = $this->actingAs($owner)->postJson('/api/v1/mrp/boms', [
            'finished_variant_id' => $fg->id,
            'lines' => [['component_variant_id' => $a->id, 'quantity' => 2]],
        ], $headers)->assertCreated()->assertJsonPath('data.version', 1);
        $bomId = $bom->json('data.id');

        app(StockService::class)->increase($tenant->id, $warehouse->id, $a->id, 20, 10, 'opening', null);
        $order = $this->actingAs($owner)->postJson('/api/v1/mrp/orders', [
            'bom_id' => $bomId, 'warehouse_id' => $warehouse->id, 'quantity_planned' => 5,
        ], $headers)->assertCreated();
        $orderId = $order->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/mrp/orders/{$orderId}/transition", ['action' => 'release'], $headers)->assertOk()->assertJsonPath('data.status', 'released');
        $this->actingAs($owner)->postJson("/api/v1/mrp/orders/{$orderId}/transition", ['action' => 'start'], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/mrp/orders/{$orderId}/produce", ['quantity_good' => 5, 'quantity_scrap' => 0], $headers)->assertOk()->assertJsonPath('data.quantity_produced', '5.000');
        $this->actingAs($owner)->postJson("/api/v1/mrp/orders/{$orderId}/transition", ['action' => 'finish'], $headers)->assertOk()->assertJsonPath('data.status', 'done');
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();
        [, $warehouse, $fg, $a] = $this->factory('WEB', $tenant);

        $this->actingAs($owner)->post('/mrp/boms', [
            'finished_variant_id' => $fg->id,
            'lines' => [['component_variant_id' => $a->id, 'quantity' => 2]],
        ])->assertRedirect();
        $bom = MrpBom::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        app(StockService::class)->increase($tenant->id, $warehouse->id, $a->id, 20, 10, 'opening', null);
        $this->actingAs($owner)->post('/mrp/orders', ['bom_id' => $bom->id, 'warehouse_id' => $warehouse->id, 'quantity_planned' => 5])->assertRedirect();
        $order = MrpWorkOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post("/mrp/orders/{$order->id}/release")->assertRedirect();
        $this->actingAs($owner)->post("/mrp/orders/{$order->id}/start")->assertRedirect();
        $this->actingAs($owner)->post("/mrp/orders/{$order->id}/produce", ['quantity_good' => 5, 'quantity_scrap' => 0])->assertRedirect();
        $this->actingAs($owner)->post("/mrp/orders/{$order->id}/finish")->assertRedirect();
        $this->assertSame('done', $order->refresh()->status);
        $this->actingAs($owner)->get('/mrp/orders')->assertOk()->assertSeeText($order->number);
    }
}
