<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockAdjustmentService;
use App\Services\StockCountService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_requires_approval_and_posts_once_with_reason_and_audit(): void
    {
        [$tenant, $owner, $warehouse, $variant] = $this->context();
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 5, 'opening', 1);
        $service = app(StockAdjustmentService::class);
        $adjustment = $service->create($tenant->id, $warehouse->id, 'damage', [[
            'product_variant_id' => $variant->id, 'quantity_change' => -2,
        ]], 'Two damaged during handling', $owner->id);

        $this->assertEquals(10, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $approved = $service->approve($adjustment, $owner->id);
        $this->assertEquals(10, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $posted = $service->post($approved, $owner->id);
        $this->assertSame('posted', $posted->status);
        $this->assertEquals(8, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjustment.posted', 'subject_id' => $adjustment->id]);

        $this->expectException(ValidationException::class);
        $service->post($posted, $owner->id);
    }

    public function test_cycle_count_snapshots_reviews_approves_and_posts_variance(): void
    {
        [$tenant, $owner, $warehouse, $variant] = $this->context();
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 5, 'opening', 1);
        $service = app(StockCountService::class);
        $count = $service->createAndSnapshot($tenant->id, $warehouse->id, 'COUNT-001', null, $owner->id);
        $line = $count->lines->first();

        $this->assertEquals(10, $line->expected_quantity);
        $reviewed = $service->recordCounts($count, [$line->id => 8], $owner->id);
        $this->assertEquals(-2, $reviewed->lines->first()->variance_quantity);
        $this->assertEquals(10, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $approved = $service->approve($reviewed, $owner->id);
        $posted = $service->post($approved, $owner->id);

        $this->assertSame('posted', $posted->status);
        $this->assertEquals(8, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id, 'reference_type' => 'adjustment_out',
            'reference_id' => $count->id, 'quantity' => 2,
        ]);
    }

    public function test_reconcile_is_read_only_without_explicit_fix(): void
    {
        [$tenant, , $warehouse, $variant] = $this->context();
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 10, 5, 'opening', 1);
        $balance = InventoryBalance::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $balance->update(['quantity' => 99]);

        $this->artisan('inventory:reconcile', ['--tenant' => $tenant->id])
            ->expectsOutputToContain('No data changed')
            ->assertExitCode(1);
        $this->assertEquals(99, $balance->fresh()->quantity);

        $this->artisan('inventory:reconcile', ['--tenant' => $tenant->id, '--fix' => true])
            ->expectsOutputToContain('explicit --fix')
            ->assertExitCode(0);
        $this->assertEquals(10, $balance->fresh()->quantity);
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Control Tenant', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'MAIN',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Counted Item', 'sku' => 'COUNT',
            'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'name' => 'Default', 'sku' => 'COUNT-1', 'purchase_price' => 5, 'sell_price' => 9,
        ]);

        return [$tenant, $owner, $warehouse, $variant];
    }
}
