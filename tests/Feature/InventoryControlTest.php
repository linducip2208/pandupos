<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryReconciliationService;
use App\Services\SerialNumberService;
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
        $approver = User::factory()->create();
        $adjustment = $service->create($tenant->id, $warehouse->id, 'damage', [[
            'product_variant_id' => $variant->id, 'quantity_change' => -2,
        ]], 'Two damaged during handling', $owner->id);

        $this->assertEquals(10, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $service->submit($adjustment, $owner->id);
        $this->expectException(ValidationException::class);
        $service->approve($adjustment, $owner->id);
    }

    public function test_adjustment_requires_a_different_approver_and_posts_once_with_reason_and_audit(): void
    {
        [$tenant, $owner, $warehouse, $variant] = $this->context();
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 5, 'opening', 1);
        $service = app(StockAdjustmentService::class);
        $approver = User::factory()->create();
        $adjustment = $service->create($tenant->id, $warehouse->id, 'damage', [[
            'product_variant_id' => $variant->id, 'quantity_change' => -2,
        ]], 'Two damaged during handling', $owner->id);

        $reviewed = $service->submit($adjustment, $owner->id);
        $approved = $service->approve($reviewed, $approver->id);
        $this->assertEquals(10, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $posted = $service->post($approved, $approver->id);
        $this->assertSame('posted', $posted->status);
        $this->assertEquals(8, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjustment.posted', 'subject_id' => $adjustment->id]);

        $this->expectException(ValidationException::class);
        $service->post($posted, $approver->id);
    }

    public function test_multi_line_adjustment_preserves_batch_location_and_serial_invariants(): void
    {
        [$tenant, $owner, $warehouse, $variant] = $this->context();
        $approver = User::factory()->create();
        $stock = app(StockService::class);
        $location = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'A-01', 'is_active' => true,
        ]);
        $batch = InventoryBatch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'batch_number' => 'ADJ-LOT', 'expires_at' => today()->addMonth(),
        ]);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 4, 5, 'opening', 1, $batch->id, null, $location->id);
        $serial = app(SerialNumberService::class)->receive($tenant->id, $warehouse->id, $variant->id, 'ADJ-SERIAL', 5, $batch->id);

        $service = app(StockAdjustmentService::class);
        $adjustment = $service->create($tenant->id, $warehouse->id, 'damage', [
            ['product_variant_id' => $variant->id, 'inventory_batch_id' => $batch->id, 'warehouse_location_id' => $location->id, 'quantity_change' => -2],
            ['product_variant_id' => $variant->id, 'inventory_batch_id' => $batch->id, 'warehouse_location_id' => $location->id, 'quantity_change' => 5, 'unit_cost' => 5],
            ['product_variant_id' => $variant->id, 'inventory_batch_id' => $batch->id, 'serial_number_id' => $serial->id, 'quantity_change' => -1],
        ], 'Multi-line controlled stock correction', $owner->id);

        $approved = $service->approve($service->submit($adjustment, $owner->id), $approver->id);
        $posted = $service->post($approved, $approver->id);

        $this->assertSame('posted', $posted->status);
        $this->assertSame('damaged', $serial->refresh()->status);
        $this->assertSame(7.0, $stock->onHandAtLocation($tenant->id, $warehouse->id, $variant->id, $location->id));
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'adjustment_out', 'reference_id' => $adjustment->id, 'serial_number_id' => $serial->id]);
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
        $this->expectException(ValidationException::class);
        $service->approve($reviewed, $owner->id);
    }

    public function test_cycle_count_requires_a_different_approver_before_posting_variance(): void
    {
        [$tenant, $owner, $warehouse, $variant] = $this->context();
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 5, 'opening', 1);
        $service = app(StockCountService::class);
        $approver = User::factory()->create();
        $count = $service->createAndSnapshot($tenant->id, $warehouse->id, 'COUNT-002', null, $owner->id);
        $line = $count->lines->first();
        $reviewed = $service->recordCounts($count, [$line->id => 8], $owner->id);

        $approved = $service->approve($reviewed, $approver->id);
        $posted = $service->post($approved, $approver->id);

        $this->assertSame('posted', $posted->status);
        $this->assertEquals(8, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id, 'reference_type' => 'adjustment_out',
            'reference_id' => $count->id, 'quantity' => 2,
        ]);
    }

    public function test_cycle_count_snapshots_batch_serial_and_rack_bin_without_double_posting(): void
    {
        [$tenant, $owner, $warehouse, $variant] = $this->context();
        $approver = User::factory()->create();
        $location = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'COUNT-A-01', 'is_active' => true,
        ]);
        $batch = InventoryBatch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'batch_number' => 'COUNT-LOT', 'expires_at' => today()->addMonth(),
        ]);
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 5, 'opening', 1, $batch->id, null, $location->id);
        $serial = app(SerialNumberService::class)->receive($tenant->id, $warehouse->id, $variant->id, 'COUNT-SERIAL', 5, $batch->id);

        $service = app(StockCountService::class);
        $locationCount = $service->createAndSnapshot($tenant->id, $warehouse->id, 'LOC-COUNT', null, $owner->id, $location->id);
        $locationLine = $locationCount->lines->sole();
        $this->assertSame($batch->id, $locationLine->inventory_batch_id);
        $this->assertEquals(10, $locationLine->expected_quantity);
        $postedLocation = $service->post(
            $service->approve($service->recordCounts($locationCount, [$locationLine->id => 8], $owner->id), $approver->id),
            $approver->id,
        );
        $this->assertSame('posted', $postedLocation->status);
        $this->assertSame(8.0, $stock->onHandAtLocation($tenant->id, $warehouse->id, $variant->id, $location->id));

        $warehouseCount = $service->createAndSnapshot($tenant->id, $warehouse->id, 'SERIAL-COUNT', null, $owner->id);
        $serialLine = $warehouseCount->lines->firstWhere('serial_number_id', $serial->id);
        $this->assertNotNull($serialLine);
        $quantities = $warehouseCount->lines->mapWithKeys(fn ($line) => [$line->id => $line->serial_number_id === $serial->id ? 0 : $line->expected_quantity])->all();
        $postedWarehouse = $service->post(
            $service->approve($service->recordCounts($warehouseCount, $quantities, $owner->id), $approver->id),
            $approver->id,
        );
        $this->assertSame('posted', $postedWarehouse->status);
        $this->assertSame('damaged', $serial->refresh()->status);
        $this->expectException(ValidationException::class);
        $service->post($postedWarehouse, $approver->id);
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

    public function test_reconciliation_reports_batch_serial_location_and_reservation_anomalies_without_mutation(): void
    {
        [$tenant, , $warehouse, $variant] = $this->context();
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 5, 'opening', 1);
        $batch = InventoryBatch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'batch_number' => 'RECON-LOT',
        ]);
        $location = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'RECON-A-01', 'is_active' => true,
        ]);
        $serial = app(SerialNumberService::class)->receive($tenant->id, $warehouse->id, $variant->id, 'RECON-SERIAL', 5);
        $serial->update(['status' => 'sold']);
        StockMovement::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'warehouse_location_id' => $location->id,
            'product_variant_id' => $variant->id, 'inventory_batch_id' => $batch->id,
            'reference_type' => 'reconciliation_fixture', 'reference_id' => 1, 'movement_type' => 'out',
            'quantity' => 1, 'unit_cost' => 5, 'occurred_at' => now(),
        ]);
        StockReservation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => 20, 'source_type' => 'reconciliation_fixture', 'status' => 'active',
        ]);

        $movementCount = StockMovement::withoutGlobalScopes()->count();
        $report = app(InventoryReconciliationService::class)->inspect($tenant->id);

        $this->assertSame('FAIL', $report['status']);
        $this->assertSame($movementCount, StockMovement::withoutGlobalScopes()->count());
        $this->assertContains('ledger_balance_mismatch', collect($report['anomalies'])->pluck('code')->all());
        $this->assertContains('negative_batch_balance', collect($report['anomalies'])->pluck('code')->all());
        $this->assertContains('negative_location_balance', collect($report['anomalies'])->pluck('code')->all());
        $this->assertContains('serial_ledger_state_mismatch', collect($report['anomalies'])->pluck('code')->all());
        $this->assertContains('reservation_exceeds_on_hand', collect($report['anomalies'])->pluck('code')->all());
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
