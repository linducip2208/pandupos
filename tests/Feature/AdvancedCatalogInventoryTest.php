<?php

namespace Tests\Feature;

use App\Models\BarcodeProfile;
use App\Models\Branch;
use App\Models\BundleItem;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BarcodeParserService;
use App\Services\PriceResolverService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedCatalogInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function tenantContext(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Advanced', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'MAIN',
        ]);

        return compact('owner', 'tenant', 'branch', 'warehouse');
    }

    private function variant(int $tenantId, string $name, string $sku, float $price = 100): ProductVariant
    {
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'name' => $name, 'sku' => $sku,
            'product_type' => 'stock', 'track_inventory' => true,
        ]);

        return ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => $sku, 'barcode' => $sku, 'purchase_price' => 50, 'sell_price' => $price,
        ]);
    }

    public function test_weighing_barcode_parser_uses_tenant_profile(): void
    {
        ['tenant' => $tenant] = $this->tenantContext();
        $variant = $this->variant($tenant->id, 'Apel', '12345', 30000);
        BarcodeProfile::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Scale weight',
            'prefix' => '20',
            'total_length' => 13,
            'item_start' => 2,
            'item_length' => 5,
            'value_start' => 7,
            'value_length' => 5,
            'value_type' => 'weight',
            'decimal_places' => 3,
        ]);

        $parsed = app(BarcodeParserService::class)->parse($tenant->id, '2012345002500');

        $this->assertSame('weighing_weight', $parsed['type']);
        $this->assertSame($variant->id, $parsed['variant_id']);
        $this->assertSame(0.25, $parsed['quantity']);
    }

    public function test_price_resolver_applies_priority_scope_date_and_quantity(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->tenantContext();
        $variant = $this->variant($tenant->id, 'Produk Harga', 'PRICE-1', 120);
        $group = CustomerGroup::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'VIP',
        ]);

        $retail = PriceList::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Retail', 'scope' => 'retail', 'priority' => 10,
        ]);
        $retail->items()->create(['product_variant_id' => $variant->id, 'price' => 100, 'minimum_quantity' => 0]);
        $branchList = PriceList::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Cabang', 'scope' => 'branch',
            'branch_id' => $branch->id, 'priority' => 20,
        ]);
        $branchList->items()->create(['product_variant_id' => $variant->id, 'price' => 80, 'minimum_quantity' => 0]);
        $vip = PriceList::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'VIP', 'scope' => 'customer_group',
            'customer_group_id' => $group->id, 'priority' => 30,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        $vip->items()->create(['product_variant_id' => $variant->id, 'price' => 70, 'minimum_quantity' => 10]);

        $resolver = app(PriceResolverService::class);
        $this->assertSame(100.0, $resolver->resolve($tenant->id, $variant->id));
        $this->assertSame(80.0, $resolver->resolve($tenant->id, $variant->id, 1, $branch->id));
        $this->assertSame(70.0, $resolver->resolve($tenant->id, $variant->id, 10, null, $group->id));
    }

    public function test_bundle_sale_return_and_void_mutate_only_component_stock(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'warehouse' => $warehouse] = $this->tenantContext();
        $burger = $this->variant($tenant->id, 'Burger', 'BURGER');
        $fries = $this->variant($tenant->id, 'Fries', 'FRIES');
        $drink = $this->variant($tenant->id, 'Drink', 'DRINK');
        foreach ([$burger, $fries, $drink] as $component) {
            app(StockService::class)->increase($tenant->id, $warehouse->id, $component->id, 10, 50, 'opening', null);
        }

        $bundleProduct = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Paket A', 'sku' => 'PAKET-A',
            'product_type' => 'bundle', 'track_inventory' => true,
        ]);
        $bundle = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $bundleProduct->id, 'name' => 'Default',
            'sku' => 'PAKET-A', 'purchase_price' => 0, 'sell_price' => 250,
        ]);
        foreach ([$burger, $fries, $drink] as $component) {
            BundleItem::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'bundle_product_id' => $bundleProduct->id,
                'component_variant_id' => $component->id,
                'quantity' => 1,
            ]);
        }

        $sale = app(SaleService::class)->checkout(
            $tenant->id,
            $branch->id,
            $warehouse->id,
            null,
            [['variant_id' => $bundle->id, 'quantity' => 2, 'unit_price' => 250]],
            [['method' => 'cash', 'amount' => 500]],
            'bundle-sale'
        );
        $this->assertEquals(8, app(StockService::class)->onHand($tenant->id, $warehouse->id, $burger->id));
        $this->assertEquals(0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $bundle->id));

        app(SaleService::class)->return($sale->id, [
            ['variant_id' => $bundle->id, 'quantity' => 1, 'unit_price' => 250],
        ], $tenant->id);
        $this->assertEquals(9, app(StockService::class)->onHand($tenant->id, $warehouse->id, $burger->id));

        app(SaleService::class)->void($sale->id, true, $tenant->id);
        foreach ([$burger, $fries, $drink] as $component) {
            $this->assertEquals(10, app(StockService::class)->onHand($tenant->id, $warehouse->id, $component->id));
        }
    }
}
