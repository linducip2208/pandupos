<?php

namespace Tests\Feature;

use App\Livewire\PosKasir;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Services\UnitConversionService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class UnitConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_carton_purchase_and_piece_sale_use_base_stock_quantity(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Konversi', $owner);
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Gudang Utama',
                'code' => 'MAIN',
            ]);
        $branch = $tenant->branches()->withoutGlobalScopes()->firstOrFail();

        $piece = Unit::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Piece', 'short_name' => 'pcs',
        ]);
        $carton = Unit::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Carton', 'short_name' => 'ctn',
        ]);
        $conversions = app(UnitConversionService::class);
        $conversions->define($tenant->id, $carton->id, $piece->id, 24);

        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Air Mineral',
            'sku' => 'AIR-001',
            'unit_id' => $piece->id,
            'product_type' => 'stock',
            'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'name' => '600 ml',
            'sku' => 'AIR-600',
            'barcode' => '899000000001',
            'purchase_price' => 2000,
            'sell_price' => 3000,
        ]);
        $supplier = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Supplier',
        ]);

        $baseQuantity = $conversions->convert($tenant->id, 2, $carton->id, $piece->id);
        $purchase = app(PurchaseService::class)->createDraft(
            $tenant->id,
            $warehouse->id,
            $supplier->id,
            [['product_variant_id' => $variant->id, 'quantity' => $baseQuantity, 'unit_cost' => 2000]]
        );
        app(PurchaseService::class)->receive($purchase->id, null, $tenant->id);

        app(SaleService::class)->checkout(
            $tenant->id,
            $branch->id,
            $warehouse->id,
            null,
            [['variant_id' => $variant->id, 'quantity' => 5, 'unit_price' => 3000]],
            [['method' => 'cash', 'amount' => 15000]],
            'unit-conversion-sale'
        );

        $this->assertSame(48.0, $baseQuantity);
        $this->assertEquals(43.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame(2.0, $conversions->convert($tenant->id, 48, $piece->id, $carton->id));
    }

    public function test_conversion_can_follow_a_chain_and_cannot_cross_tenants(): void
    {
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Tenant A', $owner);
        $other = app(TenantProvisioningService::class)->provision('Tenant B', User::factory()->create());

        $piece = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Piece', 'short_name' => 'pcs']);
        $pack = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Pack', 'short_name' => 'pak']);
        $carton = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Carton', 'short_name' => 'ctn']);
        $foreignUnit = Unit::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Foreign', 'short_name' => 'f']);

        $service = app(UnitConversionService::class);
        $service->define($tenant->id, $carton->id, $pack->id, 6);
        $service->define($tenant->id, $pack->id, $piece->id, 4);

        $this->assertSame(72.0, $service->convert($tenant->id, 3, $carton->id, $piece->id));

        $this->expectException(ValidationException::class);
        $service->define($tenant->id, $carton->id, $foreignUnit->id, 2);
    }

    public function test_tenant_ui_manages_conversion_and_purchase_selector_posts_base_quantity(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Unit UI', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $owner->givePermissionTo(['purchase.create', 'sales.view', 'sales.create']);
        $branch = $tenant->branches()->withoutGlobalScopes()->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang', 'code' => 'UNIT',
        ]);
        $piece = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Piece', 'short_name' => 'pcs']);
        $carton = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Carton', 'short_name' => 'ctn']);

        $this->actingAs($owner)->post(route('product-master.unit-conversions.store'), [
            'from_unit_id' => $carton->id, 'to_unit_id' => $piece->id, 'factor' => 24,
        ])->assertRedirect();
        $conversion = UnitConversion::firstOrFail();
        $this->actingAs($owner)->get(route('product-master.index'))->assertOk()->assertSeeText('Inverse otomatis')->assertSeeText('24 pcs');

        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Air', 'product_type' => 'stock', 'unit_id' => $piece->id, 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'AIR', 'purchase_price' => 1000, 'sell_price' => 2000,
        ]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Supplier']);
        $this->actingAs($owner)->post(route('purchasing.orders.store'), [
            'warehouse_id' => $warehouse->id, 'contact_id' => $supplier->id, 'product_variant_id' => $variant->id,
            'quantity' => 2, 'unit_id' => $carton->id, 'unit_cost' => 24000,
        ])->assertRedirect();
        $this->assertDatabaseHas('purchase_lines', ['product_variant_id' => $variant->id, 'quantity' => 48, 'unit_cost' => 1000]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.unit_conversion.saved', 'subject_id' => $conversion->id]);

        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Customer']);
        $this->actingAs($owner)->get(route('sales-orders.index'))->assertOk()->assertSeeText('Harga/satuan');
        $this->actingAs($owner)->post(route('sales-orders.store'), [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'contact_id' => $customer->id,
            'product_variant_id' => $variant->id, 'quantity' => 2, 'unit_id' => $carton->id,
            'unit_price' => 48000, 'order_date' => today()->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('sales_order_lines', ['product_variant_id' => $variant->id, 'quantity' => 48, 'unit_price' => 2000]);

        TenantContext::set($tenant);
        Livewire::actingAs($owner)->test(PosKasir::class)
            ->call('addToCart', $variant->id, $product->name, 2000, $piece->id, 'pcs')
            ->call('changeUnit', 0, $carton->id)
            ->assertSet('cart.0.unit_name', 'ctn')
            ->assertSet('cart.0.factor', 24.0)
            ->assertSet('cart.0.price', 48000.0);
    }
}
