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

class ReportAccessAndFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_require_server_side_permission_and_reject_foreign_filter_ids(): void
    {
        $this->seed(PlatformSeeder::class);
        $viewer = User::factory()->create();
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Tenant Laporan', $owner);
        $foreignOwner = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Tenant Asing', $foreignOwner);
        $foreignBranch = Branch::withoutGlobalScopes()->where('tenant_id', $foreign->id)->firstOrFail();

        $viewer->memberships()->create(['tenant_id' => $tenant->id, 'branch_ids' => []]);
        $viewer->forceFill(['current_tenant_id' => $tenant->id])->save();

        $this->actingAs($viewer)->get(route('reports.show', 'bisnis'))->assertForbidden();
        $this->actingAs($owner)->get(route('reports.show', ['type' => 'bisnis', 'branch_id' => $foreignBranch->id]))->assertStatus(422);
    }

    public function test_report_and_exports_share_tenant_scoped_branch_and_warehouse_filters(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Tenant Filter', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang Filter', 'code' => 'GF', 'is_active' => true,
        ]);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Produk Filter', 'sku' => 'REPORT-FILTER']);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'REPORT-FILTER-1', 'purchase_price' => 10, 'sell_price' => 25,
        ]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 2, 10, 'opening', null);
        $invoice = app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 25],
        ], [['method' => 'cash', 'amount' => 25]], 'report-filter');

        $query = ['from' => now()->toDateString(), 'to' => now()->toDateString(), 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id];
        $this->actingAs($owner)->get(route('reports.show', ['type' => 'bisnis'] + $query))
            ->assertOk()->assertSee($invoice->invoice_no)->assertSee('Rp 25');
        $this->actingAs($owner)->get(route('reports.csv', ['type' => 'bisnis'] + $query))
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=laporan-bisnis-'.now()->toDateString().'-'.now()->toDateString().'.csv');
        $this->actingAs($owner)->get(route('reports.show', ['type' => 'bisnis', 'warehouse_id' => 999999]))->assertStatus(422);
    }
}
