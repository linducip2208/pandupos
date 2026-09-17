<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BatchInventoryService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SerialNumberService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BatchExpirySerialTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $tenant = app(TenantProvisioningService::class)->provision('Batch Tenant', User::factory()->create());
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'MAIN',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Obat', 'sku' => 'OBAT',
            'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'name' => 'Default', 'sku' => 'OBAT-1', 'purchase_price' => 10, 'sell_price' => 20,
        ]);

        return compact('tenant', 'branch', 'warehouse', 'variant');
    }

    public function test_fefo_uses_earliest_non_expired_batch_and_blocks_expired_stock(): void
    {
        ['tenant' => $tenant, 'warehouse' => $warehouse, 'variant' => $variant] = $this->context();
        $service = app(BatchInventoryService::class);
        $expired = $service->receive(
            $tenant->id, $warehouse->id, $variant->id, 'EXP', 10, 10, null, today()->subDay()->toDateString()
        );
        $first = $service->receive(
            $tenant->id, $warehouse->id, $variant->id, 'FIRST', 4, 10, null, today()->addDays(7)->toDateString()
        );
        $later = $service->receive(
            $tenant->id, $warehouse->id, $variant->id, 'LATER', 5, 10, null, today()->addDays(30)->toDateString()
        );

        $allocations = $service->allocateFefo($tenant->id, $warehouse->id, $variant->id, 6, 'sale', 100);
        $this->assertSame([
            ['batch_id' => $first->id, 'quantity' => 4.0],
            ['batch_id' => $later->id, 'quantity' => 2.0],
        ], $allocations);
        $this->assertEquals(10, app(StockService::class)->onHandByBatch($tenant->id, $warehouse->id, $variant->id, $expired->id));

        $this->expectException(ValidationException::class);
        $service->allocateFefo($tenant->id, $warehouse->id, $variant->id, 4, 'sale', 101);
    }

    public function test_serial_number_cannot_be_sold_twice_and_has_controlled_lifecycle(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'warehouse' => $warehouse, 'variant' => $variant] = $this->context();
        $invoice = SalesInvoice::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'final',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'subtotal' => 20,
            'total' => 20,
        ]);
        $serials = app(SerialNumberService::class);
        $serial = $serials->receive($tenant->id, $warehouse->id, $variant->id, 'IMEI-001', 10);
        $serials->sell($tenant->id, $serial->id, $invoice->id);
        $this->assertSame('sold', $serial->refresh()->status);

        try {
            $serials->sell($tenant->id, $serial->id, $invoice->id);
            $this->fail('Duplicate serial sale must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('sold', $serial->refresh()->status);
        }

        $serials->transition($tenant->id, $serial->id, 'returned');
        $serials->transition($tenant->id, $serial->id, 'available');
        $this->assertSame('available', $serial->refresh()->status);
    }

    public function test_sale_uses_fefo_for_batch_stock_and_rejects_expired_selected_batch(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'warehouse' => $warehouse, 'variant' => $variant] = $this->context();
        $batches = app(BatchInventoryService::class);
        $expired = $batches->receive($tenant->id, $warehouse->id, $variant->id, 'EXPIRED', 2, 10, null, today()->subDay()->toDateString());
        $first = $batches->receive($tenant->id, $warehouse->id, $variant->id, 'FIRST', 2, 10, null, today()->addDay()->toDateString());
        $second = $batches->receive($tenant->id, $warehouse->id, $variant->id, 'SECOND', 2, 10, null, today()->addDays(7)->toDateString());

        $invoice = app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 3, 'unit_price' => 20],
        ], [['method' => 'cash', 'amount' => 60]], 'batch-fefo-sale');

        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'sale', 'reference_id' => $invoice->id, 'inventory_batch_id' => $first->id, 'quantity' => 2]);
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'sale', 'reference_id' => $invoice->id, 'inventory_batch_id' => $second->id, 'quantity' => 1]);
        app(SaleService::class)->return($invoice->id, [['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 20]], $tenant->id);
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'sale_return', 'reference_id' => $invoice->id, 'inventory_batch_id' => $first->id, 'quantity' => 1]);
        app(SaleService::class)->void($invoice->id, true, $tenant->id);
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'sale_void', 'reference_id' => $invoice->id, 'inventory_batch_id' => $second->id, 'quantity' => 1]);
        $this->expectException(ValidationException::class);
        app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 20, 'inventory_batch_id' => $expired->id],
        ], [['method' => 'cash', 'amount' => 20]], 'batch-expired-sale');
    }

    public function test_goods_receipt_creates_a_provenanced_batch_and_links_the_ledger_and_receipt_line(): void
    {
        ['tenant' => $tenant, 'warehouse' => $warehouse, 'variant' => $variant] = $this->context();
        $supplier = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Supplier Lot',
        ]);
        $purchases = app(PurchaseService::class);
        $purchase = $purchases->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 5, 'unit_cost' => 10,
        ]]);

        $purchases->receive($purchase->id, [[
            'product_variant_id' => $variant->id,
            'quantity' => 5,
            'batch_number' => 'GRN-LOT-001',
            'manufactured_at' => today()->subDays(3)->toDateString(),
            'expires_at' => today()->addMonths(6)->toDateString(),
        ]], $tenant->id);

        $batch = InventoryBatch::withoutGlobalScopes()->where('batch_number', 'GRN-LOT-001')->firstOrFail();
        $this->assertSame($purchase->id, $batch->purchase_id);
        $this->assertSame($supplier->id, $batch->supplier_id);
        $this->assertDatabaseHas('goods_receipt_lines', ['inventory_batch_id' => $batch->id, 'quantity' => 5]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_batch_id' => $batch->id,
            'reference_type' => 'purchase_receipt',
            'movement_type' => 'in',
            'quantity' => 5,
        ]);
    }

    public function test_goods_receipt_rejects_a_batch_from_another_tenant(): void
    {
        ['tenant' => $tenant, 'warehouse' => $warehouse, 'variant' => $variant] = $this->context();
        $supplier = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Supplier Scoped',
        ]);
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => 10,
        ]]);
        ['tenant' => $otherTenant, 'warehouse' => $otherWarehouse, 'variant' => $otherVariant] = $this->context();
        $foreignBatch = app(BatchInventoryService::class)->receive(
            $otherTenant->id, $otherWarehouse->id, $otherVariant->id, 'FOREIGN-LOT', 1, 10,
        );

        $this->expectException(ValidationException::class);
        app(PurchaseService::class)->receive($purchase->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'inventory_batch_id' => $foreignBatch->id,
        ]], $tenant->id);
    }

    public function test_serial_checkout_return_and_void_keep_serial_status_and_ledger_in_lockstep(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'warehouse' => $warehouse, 'variant' => $variant] = $this->context();
        $serials = app(SerialNumberService::class);
        $returned = $serials->receive($tenant->id, $warehouse->id, $variant->id, 'IMEI-RETURN', 10);
        $voided = $serials->receive($tenant->id, $warehouse->id, $variant->id, 'IMEI-VOID', 10);
        $sales = app(SaleService::class);

        $returnInvoice = $sales->checkout($tenant->id, $branch->id, $warehouse->id, null, [[
            'variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 20, 'serial_number_ids' => [$returned->id],
        ]], [['method' => 'cash', 'amount' => 20]], 'serial-return');
        $this->assertSame('sold', $returned->refresh()->status);
        $sales->return($returnInvoice->id, [['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 20]], $tenant->id);
        $this->assertSame('returned', $returned->refresh()->status);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'sale_return', 'serial_number_id' => $returned->id, 'movement_type' => 'in',
        ]);

        $voidInvoice = $sales->checkout($tenant->id, $branch->id, $warehouse->id, null, [[
            'variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 20, 'serial_number_ids' => [$voided->id],
        ]], [['method' => 'cash', 'amount' => 20]], 'serial-void');
        $sales->void($voidInvoice->id, true, $tenant->id);
        $this->assertSame('available', $voided->refresh()->status);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'sale_void', 'serial_number_id' => $voided->id, 'movement_type' => 'in',
        ]);
    }
}
