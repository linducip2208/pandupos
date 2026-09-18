<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFinancialVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_dashboard_metrics_require_reports_permission_and_remain_tenant_scoped(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Dashboard A', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang A', 'code' => 'DA']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Produk A', 'sku' => 'DASH-A']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'DASH-A-1', 'purchase_price' => 10, 'sell_price' => 125]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 1, 10, 'opening', null);
        app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 125]], [['method' => 'cash', 'amount' => 125]], 'dashboard-a');

        $viewer = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $viewer->memberships()->create(['tenant_id' => $tenant->id, 'branch_ids' => [$branch->id]]);

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertOk()->assertDontSee('Omzet bulan ini')->assertDontSee('Pembayaran hari ini')->assertDontSee('Rp 125');
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()->assertSee('Omzet bulan ini')->assertSee('Rp 125');
    }
}
