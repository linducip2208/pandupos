<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BatchInventoryService;
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
}
