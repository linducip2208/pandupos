<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RepairOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ModuleManager;
use App\Services\RepairService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Repair module: intake → diagnosis → work → delivery with single stock
 * deduction, warranty handling, payment balance, isolation, RBAC and API.
 */
class RepairTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Repair', bool $enableRepair = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableRepair) {
            app(ModuleManager::class)->enable($tenant->id, 'repair');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    /** @return array{Warehouse, part} */
    private function stocked(string $prefix, $tenant): array
    {
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang '.$prefix, 'code' => 'WH-'.uniqid()]);
        $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => $prefix, 'sku' => $prefix, 'product_type' => 'stock', 'track_inventory' => true]);
        $part = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'Default', 'sku' => $prefix.'-V', 'purchase_price' => 200, 'sell_price' => 350]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $part->id, 5, 200, 'opening', null);

        return [$warehouse, $part];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko Repair Gate', false);

        $this->actingAs($owner)->get('/repair')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/repair/orders', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'repair');
        $this->actingAs($owner)->get('/repair')->assertOk()->assertSeeText('Order servis');
    }

    public function test_full_flow_deducts_stock_once_and_settles_balance(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $part] = $this->stocked('LCD', $tenant);
        $svc = app(RepairService::class);
        $this->actingAs($owner);

        $order = $svc->intake($tenant->id, [
            'device_brand' => 'Acme', 'device_model' => 'Phone X',
            'complaint' => 'Layar mati total', 'warehouse_id' => $warehouse->id,
        ], $owner->id);
        $this->assertSame('received', $order->status);

        // Cannot start before diagnosis.
        try {
            $svc->start($order, $owner->id);
            $this->fail('Starting before diagnosis must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->diagnose($order, 'Ganti LCD', 150000, $owner->id);
        $svc->start($order->refresh(), $owner->id);
        $svc->addPart($order->refresh(), $part->id, 1, null, $owner->id);
        $mid = $order->refresh();
        $this->assertSame(150350.0, (float) $mid->total); // labor 150000 + part 350
        // Stock untouched until delivery.
        $this->assertSame(5.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $part->id));

        // Overpayment is refused.
        try {
            $svc->recordPayment($mid, 200000, 'cash', $owner->id);
            $this->fail('Overpayment must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->markReady($mid, $owner->id);
        // Delivery requires a settled balance.
        try {
            $svc->deliver($mid->refresh(), $owner->id);
            $this->fail('Delivery with balance must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $svc->recordPayment($mid->refresh(), 150350, 'cash', $owner->id);
        $done = $svc->deliver($order->refresh(), $owner->id);
        $this->assertSame('delivered', $done->status);
        $this->assertSame(4.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $part->id));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('reference_type', 'repair_deliver')->where('reference_id', $done->id)->count());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'repair.delivered']);
    }

    public function test_part_shortage_and_cancel_rules(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $part] = $this->stocked('BAT', $tenant);
        $svc = app(RepairService::class);

        $order = $svc->intake($tenant->id, ['complaint' => 'Baterai drop', 'warehouse_id' => $warehouse->id], $owner->id);
        // Received orders cancel cleanly.
        $this->assertSame('cancelled', $svc->cancel($order, $owner->id)->status);

        $order2 = $svc->intake($tenant->id, ['complaint' => 'Baterai drop', 'warehouse_id' => $warehouse->id], $owner->id);
        $svc->diagnose($order2, 'Ganti baterai', 50000, $owner->id);
        try {
            $svc->addPart($order2->refresh(), $part->id, 99, null, $owner->id);
            $this->fail('Part beyond stock must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $svc->start($order2->refresh(), $owner->id);
        try {
            $svc->cancel($order2->refresh(), $owner->id);
            $this->fail('Cancelling started work must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_warranty_is_free_but_consumes_stock(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $part] = $this->stocked('WRT', $tenant);
        $svc = app(RepairService::class);

        $order = $svc->intake($tenant->id, ['complaint' => 'Mati garansi', 'warehouse_id' => $warehouse->id, 'warranty' => true], $owner->id);
        $svc->diagnose($order, 'Ganti mainboard', 200000, $owner->id);
        $svc->start($order->refresh(), $owner->id);
        $svc->addPart($order->refresh(), $part->id, 1, null, $owner->id);
        $mid = $order->refresh();
        $this->assertSame(0.0, (float) $mid->total);
        $svc->markReady($mid, $owner->id);
        $done = $svc->deliver($mid->refresh(), $owner->id);
        $this->assertSame('delivered', $done->status);
        $this->assertSame(4.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $part->id));
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko Repair A');
        [$tenantB, $ownerB] = $this->context('Toko Repair B');
        [$warehouseA] = $this->stocked('IA', $tenantA);
        $orderA = app(RepairService::class)->intake($tenantA->id, ['complaint' => 'Rahasia A', 'warehouse_id' => $warehouseA->id], null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, RepairOrder::query()->count());
        $this->actingAs($ownerB)->get('/repair')->assertOk()->assertDontSee('Rahasia A');
        $this->actingAs($ownerB)->post("/repair/orders/{$orderA->id}/deliver")->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/repair')->assertForbidden();
        $member->givePermissionTo('repair.view');
        $this->actingAs($member)->get('/repair')->assertOk();
        $this->actingAs($member)->post('/repair/orders', ['complaint' => 'x'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $part] = $this->stocked('API', $tenant);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $created = $this->actingAs($owner)->postJson('/api/v1/repair/orders', [
            'device_model' => 'Tablet Z', 'complaint' => 'Charger longgar', 'warehouse_id' => $warehouse->id,
        ], $headers)->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/repair/orders/{$id}/transition", ['action' => 'diagnose', 'diagnosis' => 'Ganti konektor', 'labor_cost' => 75000], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/repair/orders/{$id}/transition", ['action' => 'start'], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/repair/orders/{$id}/parts", ['product_variant_id' => $part->id, 'quantity' => 1], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/repair/orders/{$id}/transition", ['action' => 'ready'], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/repair/orders/{$id}/pay", ['amount' => 75350, 'method' => 'cash'], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/repair/orders/{$id}/transition", ['action' => 'deliver'], $headers)->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame(4.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $part->id));
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse] = $this->stocked('WEB', $tenant);

        $this->actingAs($owner)->post('/repair/orders', ['complaint' => 'Web rusak', 'warehouse_id' => $warehouse->id])->assertRedirect();
        $order = RepairOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post("/repair/orders/{$order->id}/diagnose", ['diagnosis' => 'Servis ringan', 'labor_cost' => 25000])->assertRedirect();
        $this->actingAs($owner)->post("/repair/orders/{$order->id}/transition", ['action' => 'start'])->assertRedirect();
        $this->actingAs($owner)->post("/repair/orders/{$order->id}/transition", ['action' => 'ready'])->assertRedirect();
        $this->actingAs($owner)->post("/repair/orders/{$order->id}/pay", ['amount' => 25000, 'method' => 'cash'])->assertRedirect();
        $this->actingAs($owner)->post("/repair/orders/{$order->id}/deliver")->assertRedirect();
        $this->assertSame('delivered', $order->refresh()->status);
        $this->actingAs($owner)->get('/repair')->assertOk()->assertSeeText($order->number);
    }
}
