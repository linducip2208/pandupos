<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryWorkspaceUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_tenant_user_can_open_workspace_and_create_governed_adjustment(): void
    {
        [$user, $tenant, $warehouse, $variant] = $this->context('Workspace A');
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 10, 5000, 'opening', 1);

        $this->actingAs($user)->get(route('inventory.index'))
            ->assertOk()->assertSee('Kontrol Persediaan')->assertSee('Cycle count');

        $this->post(route('inventory.locations.store'), [
            'warehouse_id' => $warehouse->id, 'code' => 'Z1-R1-B1', 'zone' => 'Z1', 'rack' => 'R1', 'bin' => 'B1',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('warehouse_locations', ['tenant_id' => $tenant->id, 'code' => 'Z1-R1-B1']);

        $this->post(route('inventory.adjustments.store'), [
            'warehouse_id' => $warehouse->id, 'reason' => 'damage', 'notes' => 'Kemasan rusak saat penanganan',
            'product_variant_id' => $variant->id, 'quantity_change' => -2,
        ])->assertRedirect()->assertSessionHas('status');

        $adjustment = StockAdjustment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->post(route('inventory.adjustments.action', [$adjustment, 'approve']))->assertRedirect();
        $this->post(route('inventory.adjustments.action', [$adjustment, 'post']))->assertRedirect();
        $this->assertEquals(8, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
    }

    public function test_workspace_requires_permission_and_cross_tenant_record_is_not_exposed(): void
    {
        [$userA] = $this->context('Workspace B');
        [$userB, , $warehouseB, $variantB] = $this->context('Workspace C');
        $reservationB = StockReservation::withoutGlobalScopes()->create([
            'tenant_id' => $userB->current_tenant_id, 'warehouse_id' => $warehouseB->id,
            'product_variant_id' => $variantB->id, 'quantity' => 1, 'source_type' => 'test', 'status' => 'active',
        ]);

        $userA->revokePermissionTo('inventory.view');
        $this->actingAs($userA)->get(route('inventory.index'))->assertForbidden();
        $userA->givePermissionTo('inventory.view');
        $this->post(route('inventory.reservations.release', $reservationB))->assertNotFound();
    }

    private function context(string $name): array
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $user);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->givePermissionTo(['inventory.view', 'inventory.adjust', 'inventory.transfer']);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'name' => 'Gudang '.$name, 'code' => 'WH-'.uniqid(),
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Produk '.$name, 'sku' => 'P-'.uniqid(),
            'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'V-'.uniqid(), 'purchase_price' => 5000, 'sell_price' => 8000,
        ]);

        return [$user, $tenant, $warehouse, $variant];
    }
}
