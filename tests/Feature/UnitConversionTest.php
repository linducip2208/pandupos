<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Services\UnitConversionService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
}
