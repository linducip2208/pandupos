<?php

namespace Tests\Feature;

use App\Contracts\CostingStrategy;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeightedAverageCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_sale_return_transfer_and_purchase_return_preserve_wac(): void
    {
        [$tenant, $branch, $source, $destination, $variant] = $this->context();
        $stock = app(StockService::class);
        $this->assertSame('weighted_average', app(CostingStrategy::class)->name());

        $stock->increase($tenant->id, $source->id, $variant->id, 10, 10, 'purchase_receipt', 1);
        $this->assertEquals(10, $stock->weightedAverageCost($tenant->id, $source->id, $variant->id));
        $stock->increase($tenant->id, $source->id, $variant->id, 10, 20, 'purchase_receipt', 2);
        $this->assertEquals(15, $stock->weightedAverageCost($tenant->id, $source->id, $variant->id));

        $invoice = app(SaleService::class)->checkout(
            $tenant->id, $branch->id, $source->id, null,
            [['variant_id' => $variant->id, 'quantity' => 5, 'unit_price' => 30]],
            [['method' => 'cash', 'amount' => 150]], 'wac-sale-1'
        );
        $saleMovement = StockMovement::withoutGlobalScopes()
            ->where('reference_type', 'sale')->where('reference_id', $invoice->id)->firstOrFail();
        $this->assertEquals(15, $saleMovement->unit_cost);
        $this->assertEquals(15, $stock->weightedAverageCost($tenant->id, $source->id, $variant->id));

        app(SaleService::class)->return($invoice->id, [[
            'variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 30,
        ]], $tenant->id);
        $returnMovement = StockMovement::withoutGlobalScopes()
            ->where('reference_type', 'sale_return')->where('reference_id', $invoice->id)->firstOrFail();
        $this->assertEquals(15, $returnMovement->unit_cost);
        $this->assertEquals(15, $stock->weightedAverageCost($tenant->id, $source->id, $variant->id));

        $stock->transfer($tenant->id, $source->id, $destination->id, $variant->id, 3, 10);
        $this->assertEquals(15, $stock->weightedAverageCost($tenant->id, $source->id, $variant->id));
        $this->assertEquals(15, $stock->weightedAverageCost($tenant->id, $destination->id, $variant->id));

        $stock->decrease(
            $tenant->id, $source->id, $variant->id, 2,
            'purchase_return', 2, null, null, null, 20
        );
        $purchaseReturn = StockMovement::withoutGlobalScopes()
            ->where('reference_type', 'purchase_return')->where('reference_id', 2)->firstOrFail();
        $this->assertEquals(20, $purchaseReturn->unit_cost);
        $this->assertEquals(14.1667, $stock->weightedAverageCost($tenant->id, $source->id, $variant->id));
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $tenant = app(TenantProvisioningService::class)->provision('Cost Tenant', User::factory()->create());
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $source = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Source', 'code' => 'SRC',
        ]);
        $destination = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Destination', 'code' => 'DST',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Costed Item', 'sku' => 'COST',
            'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'name' => 'Default', 'sku' => 'COST-1', 'purchase_price' => 10, 'sell_price' => 30,
        ]);

        return [$tenant, $branch, $source, $destination, $variant];
    }
}
