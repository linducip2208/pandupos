<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\SerialNumberService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdvancedStockTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_destination_stock_changes_only_as_partial_receipts_are_posted(): void
    {
        [$tenant, $owner, $source, $destination, $variant] = $this->context();
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $source->id, $variant->id, 10, 8, 'opening', 1);
        $service = app(StockTransferService::class);
        $transfer = $service->createDraft($tenant->id, $source->id, $destination->id, [
            ['product_variant_id' => $variant->id, 'quantity' => 10],
        ], null, $owner->id);

        $service->approve($transfer, User::factory()->create()->id);
        $this->assertEquals(10, $stock->onHand($tenant->id, $source->id, $variant->id));
        $this->assertEquals(0, $stock->onHand($tenant->id, $destination->id, $variant->id));

        $shipped = $service->ship($transfer, $owner->id);
        $this->assertSame('shipped', $shipped->status);
        $this->assertEquals(0, $stock->onHand($tenant->id, $source->id, $variant->id));
        $this->assertEquals(0, $stock->onHand($tenant->id, $destination->id, $variant->id));
        $inTransit = $service->markInTransit($shipped, $owner->id);

        $line = $inTransit->lines->first();
        $partial = $service->receive($inTransit, [$line->id => 3], $owner->id);
        $this->assertSame('partial_received', $partial->status);
        $this->assertEquals(3, $stock->onHand($tenant->id, $destination->id, $variant->id));

        try {
            $service->receive($partial, [$line->id => 8], $owner->id);
            $this->fail('Receipt greater than remaining quantity must be rejected.');
        } catch (ValidationException) {
            $this->assertEquals(3, $stock->onHand($tenant->id, $destination->id, $variant->id));
        }

        $received = $service->receive($partial->fresh(), [$line->id => 7], $owner->id);
        $this->assertSame('received', $received->status);
        $this->assertEquals(10, $stock->onHand($tenant->id, $destination->id, $variant->id));
        $this->assertEquals(8, $stock->weightedAverageCost($tenant->id, $destination->id, $variant->id));
    }

    public function test_only_unshipped_transfer_can_be_cancelled(): void
    {
        [$tenant, $owner, $source, $destination, $variant] = $this->context();
        app(StockService::class)->increase($tenant->id, $source->id, $variant->id, 2, 8, 'opening', 1);
        $service = app(StockTransferService::class);
        $draft = $service->createDraft($tenant->id, $source->id, $destination->id, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ]);
        $this->assertSame('cancelled', $service->cancel($draft, $owner->id)->status);

        $second = $service->createDraft($tenant->id, $source->id, $destination->id, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ]);
        $service->approve($second, User::factory()->create()->id);
        $shipped = $service->ship($second, $owner->id);
        $this->expectException(ValidationException::class);
        $service->cancel($shipped, $owner->id);
    }

    public function test_requester_cannot_approve_own_multi_line_transfer(): void
    {
        [$tenant, $owner, $source, $destination, $variant] = $this->context();
        $secondVariant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $variant->product_id,
            'name' => 'Second', 'sku' => 'TRF-2', 'purchase_price' => 9, 'sell_price' => 13,
        ]);
        $transfer = app(StockTransferService::class)->createDraft($tenant->id, $source->id, $destination->id, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
            ['product_variant_id' => $secondVariant->id, 'quantity' => 2],
        ], 'Two item request', $owner->id);

        $this->assertSame($owner->id, $transfer->requested_by);
        $this->assertCount(2, $transfer->lines);
        $this->expectException(ValidationException::class);
        app(StockTransferService::class)->approve($transfer, $owner->id);
    }

    public function test_transfer_preserves_batch_provenance_without_destination_stock_before_receipt(): void
    {
        [$tenant, $owner, $source, $destination, $variant] = $this->context();
        $batch = InventoryBatch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $source->id, 'product_variant_id' => $variant->id,
            'batch_number' => 'LOT-TRANSFER', 'manufactured_at' => today()->subDay(), 'expires_at' => today()->addMonth(),
        ]);
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $source->id, $variant->id, 5, 8, 'purchase_receipt', 1, $batch->id);
        $service = app(StockTransferService::class);
        $transfer = $service->createDraft($tenant->id, $source->id, $destination->id, [[
            'product_variant_id' => $variant->id, 'inventory_batch_id' => $batch->id, 'quantity' => 5,
        ]], null, $owner->id);

        $service->approve($transfer, User::factory()->create()->id);
        $service->ship($transfer, $owner->id);
        $this->assertSame(0.0, $stock->onHand($tenant->id, $destination->id, $variant->id));
        $received = $service->receive($transfer->fresh('lines'), [$transfer->lines->first()->id => 5], $owner->id);
        $line = $received->lines->first();
        $destinationBatch = InventoryBatch::withoutGlobalScopes()->findOrFail($line->destination_inventory_batch_id);

        $this->assertSame($batch->batch_number, $destinationBatch->batch_number);
        $this->assertSame($batch->expires_at->toDateString(), $destinationBatch->expires_at->toDateString());
        $this->assertSame(5.0, $stock->onHandByBatch($tenant->id, $destination->id, $variant->id, $destinationBatch->id));
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'transfer_in', 'reference_id' => $transfer->id, 'inventory_batch_id' => $destinationBatch->id,
        ]);
    }

    public function test_serialized_transfer_moves_each_serial_exactly_once_after_receipt(): void
    {
        [$tenant, $owner, $source, $destination, $variant] = $this->context();
        $serial = app(SerialNumberService::class)->receive($tenant->id, $source->id, $variant->id, 'TRANSFER-IMEI', 8);
        $service = app(StockTransferService::class);
        $transfer = $service->createDraft($tenant->id, $source->id, $destination->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'serial_number_ids' => [$serial->id],
        ]], null, $owner->id);
        $service->approve($transfer, User::factory()->create()->id);
        $service->ship($transfer, $owner->id);
        $this->assertSame('transferred', $serial->refresh()->status);
        $this->assertSame(0.0, app(StockService::class)->onHand($tenant->id, $destination->id, $variant->id));

        $line = $transfer->fresh('lines')->lines->first();
        $service->receive($transfer->fresh('lines'), [$line->id => 1], $owner->id);
        $this->assertSame('available', $serial->refresh()->status);
        $this->assertSame($destination->id, $serial->warehouse_id);
        $this->assertSame(1.0, app(StockService::class)->onHand($tenant->id, $destination->id, $variant->id));
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'transfer_in', 'reference_id' => $transfer->id, 'serial_number_id' => $serial->id,
        ]);
    }

    public function test_non_serial_transfer_preserves_source_and_destination_rack_bin_ledger_trace(): void
    {
        [$tenant, $owner, $source, $destination, $variant] = $this->context();
        $sourceLocation = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $source->id, 'code' => 'SRC-A-01', 'is_active' => true,
        ]);
        $destinationLocation = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $destination->id, 'code' => 'DST-B-02', 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $source->id, $variant->id, 4, 8, 'opening', 1, null, null, $sourceLocation->id);

        $service = app(StockTransferService::class);
        $transfer = $service->createDraft($tenant->id, $source->id, $destination->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 4,
            'source_warehouse_location_id' => $sourceLocation->id,
            'destination_warehouse_location_id' => $destinationLocation->id,
        ]], null, $owner->id);

        $service->approve($transfer, User::factory()->create()->id);
        $service->ship($transfer, $owner->id);
        $line = $transfer->fresh('lines')->lines->first();
        $service->receive($transfer->fresh('lines'), [$line->id => 4], $owner->id);

        $this->assertSame(0.0, $stock->onHandAtLocation($tenant->id, $source->id, $variant->id, $sourceLocation->id));
        $this->assertSame(4.0, $stock->onHandAtLocation($tenant->id, $destination->id, $variant->id, $destinationLocation->id));
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'transfer_in', 'reference_id' => $transfer->id,
            'warehouse_location_id' => $destinationLocation->id,
        ]);
    }

    public function test_serialized_transfer_preserves_rack_bin_trace_and_rejects_wrong_source_location(): void
    {
        [$tenant, $owner, $source, $destination, $variant] = $this->context();
        $sourceLocation = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $source->id, 'code' => 'SER-SRC', 'is_active' => true,
        ]);
        $wrongLocation = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $source->id, 'code' => 'SER-WRONG', 'is_active' => true,
        ]);
        $destinationLocation = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $destination->id, 'code' => 'SER-DST', 'is_active' => true,
        ]);
        $serial = app(SerialNumberService::class)->receive($tenant->id, $source->id, $variant->id, 'TRANSFER-LOC-SERIAL', 8, null, null, $sourceLocation->id);
        $service = app(StockTransferService::class);
        $invalid = $service->createDraft($tenant->id, $source->id, $destination->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'serial_number_ids' => [$serial->id],
            'source_warehouse_location_id' => $wrongLocation->id, 'destination_warehouse_location_id' => $destinationLocation->id,
        ]], null, $owner->id);
        $service->approve($invalid, User::factory()->create()->id);
        $this->expectException(ValidationException::class);
        $service->ship($invalid, $owner->id);
    }

    public function test_serialized_transfer_receives_into_selected_destination_rack_bin(): void
    {
        [$tenant, $owner, $source, $destination, $variant] = $this->context();
        $sourceLocation = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $source->id, 'code' => 'SER-OK-SRC', 'is_active' => true,
        ]);
        $destinationLocation = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $destination->id, 'code' => 'SER-OK-DST', 'is_active' => true,
        ]);
        $serial = app(SerialNumberService::class)->receive($tenant->id, $source->id, $variant->id, 'TRANSFER-OK-SERIAL', 8, null, null, $sourceLocation->id);
        $service = app(StockTransferService::class);
        $transfer = $service->createDraft($tenant->id, $source->id, $destination->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'serial_number_ids' => [$serial->id],
            'source_warehouse_location_id' => $sourceLocation->id, 'destination_warehouse_location_id' => $destinationLocation->id,
        ]], null, $owner->id);
        $service->approve($transfer, User::factory()->create()->id);
        $service->ship($transfer, $owner->id);
        $line = $transfer->fresh('lines')->lines->first();
        $service->receive($transfer->fresh('lines'), [$line->id => 1], $owner->id);

        $this->assertSame($destinationLocation->id, $serial->refresh()->warehouse_location_id);
        $this->assertSame(1.0, app(StockService::class)->onHandAtLocation($tenant->id, $destination->id, $variant->id, $destinationLocation->id));
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Transfer Tenant', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $source = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Source', 'code' => 'SRC',
        ]);
        $destination = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Destination', 'code' => 'DST',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Transfer Item', 'sku' => 'TRF',
            'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'name' => 'Default', 'sku' => 'TRF-1', 'purchase_price' => 8, 'sell_price' => 12,
        ]);

        return [$tenant, $owner, $source, $destination, $variant];
    }
}
