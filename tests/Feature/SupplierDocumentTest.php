<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\PurchaseService;
use App\Services\StockService;
use App\Services\SupplierDocumentService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_invoice_is_separate_from_receipt_and_tracks_exact_payments(): void
    {
        [$tenant, $owner, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, null, $tenant->id, $owner->id);

        $service = app(SupplierDocumentService::class);
        $invoice = $service->createInvoice($tenant->id, [
            'purchase_id' => $purchase->id, 'supplier_id' => $supplier->id,
            'invoice_number' => 'SUP-001', 'invoice_date' => '2026-09-17', 'due_date' => '2026-10-17',
            'subtotal' => 1000, 'discount' => 50, 'tax' => 105, 'shipping' => 25,
        ], $owner->id);

        $this->assertEquals(1080, $invoice->total);
        $this->assertEquals(1080, $invoice->balance);
        $service->pay($invoice, 400, 'bank_transfer', 'TRX-1', $owner->id);
        $this->assertEquals(400, $invoice->fresh()->paid);
        $this->assertEquals(680, $invoice->fresh()->balance);
        $this->assertSame('partial', $invoice->fresh()->status);
        $service->pay($invoice->fresh(), 680, 'bank_transfer', 'TRX-2', $owner->id);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals(0, $invoice->fresh()->balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase.supplier_payment.created']);

        $this->expectException(ValidationException::class);
        $service->pay($invoice->fresh(), 1, 'cash', null, $owner->id);
    }

    public function test_purchase_return_cannot_exceed_received_minus_prior_returns(): void
    {
        [$tenant, $owner, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 6,
        ]], $tenant->id, $owner->id);
        $line = $purchase->lines()->firstOrFail();
        $service = app(SupplierDocumentService::class);
        $return = $service->createReturn($purchase, [[
            'purchase_line_id' => $line->id, 'quantity' => 2,
        ]], 'Dua unit cacat', 'supplier_credit', $owner->id);

        $this->assertEquals(200, $return->total);
        $this->assertEquals(4, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id, 'reference_type' => 'purchase_return',
            'reference_id' => $return->id, 'quantity' => 2, 'unit_cost' => 100,
        ]);

        $this->expectException(ValidationException::class);
        $service->createReturn($purchase, [[
            'purchase_line_id' => $line->id, 'quantity' => 5,
        ]], 'Melebihi sisa diterima', 'supplier_credit', $owner->id);
    }

    public function test_purchase_return_preserves_batch_and_rack_trace_and_rejects_foreign_trace(): void
    {
        [$tenant, $owner, $warehouse, $supplier, $variant] = $this->context();
        $location = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'RET-A-01', 'is_active' => true,
        ]);
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 5, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 5, 'batch_number' => 'RET-BATCH-1',
            'warehouse_location_id' => $location->id,
        ]], $tenant->id, $owner->id);
        $line = $purchase->lines()->firstOrFail();
        $batch = InventoryBatch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('batch_number', 'RET-BATCH-1')->firstOrFail();

        $return = app(SupplierDocumentService::class)->createReturn($purchase, [[
            'purchase_line_id' => $line->id, 'quantity' => 2, 'inventory_batch_id' => $batch->id,
            'warehouse_location_id' => $location->id,
        ]], 'Barang rusak dari batch penerimaan', 'supplier_credit', $owner->id);

        $this->assertDatabaseHas('purchase_return_lines', [
            'purchase_return_id' => $return->id, 'inventory_batch_id' => $batch->id,
            'warehouse_location_id' => $location->id, 'quantity' => 2,
        ]);
        $this->assertSame(3.0, app(StockService::class)->onHandByBatch($tenant->id, $warehouse->id, $variant->id, $batch->id));
        $this->assertSame(3.0, app(StockService::class)->onHandAtLocation($tenant->id, $warehouse->id, $variant->id, $location->id));

        $foreignBatch = InventoryBatch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'batch_number' => 'FOREIGN-PROVENANCE', 'purchase_id' => null,
        ]);
        $this->expectException(ValidationException::class);
        app(SupplierDocumentService::class)->createReturn($purchase, [[
            'purchase_line_id' => $line->id, 'quantity' => 1, 'inventory_batch_id' => $foreignBatch->id,
        ]], 'Batch bukan asal GRN', 'supplier_credit', $owner->id);
    }

    public function test_purchase_return_rejects_duplicate_line_quantities_in_one_request(): void
    {
        [$tenant, $owner, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 3, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 3]], $tenant->id, $owner->id);
        $line = $purchase->lines()->firstOrFail();

        $this->expectException(ValidationException::class);
        app(SupplierDocumentService::class)->createReturn($purchase, [
            ['purchase_line_id' => $line->id, 'quantity' => 2],
            ['purchase_line_id' => $line->id, 'quantity' => 2],
        ], 'Tidak boleh melebihi penerimaan dalam satu request', 'supplier_credit', $owner->id);
    }

    public function test_payment_reference_cannot_be_reused_within_a_tenant(): void
    {
        [$tenant, $owner, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 100,
        ]]);
        $service = app(SupplierDocumentService::class);
        $first = $service->createInvoice($tenant->id, [
            'purchase_id' => $purchase->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'REF-001',
            'invoice_date' => '2026-09-18', 'subtotal' => 100,
        ], $owner->id);
        $second = $service->createInvoice($tenant->id, [
            'purchase_id' => $purchase->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'REF-002',
            'invoice_date' => '2026-09-18', 'subtotal' => 100,
        ], $owner->id);

        $service->pay($first, 100, 'bank_transfer', 'BANK-REF-001', $owner->id);

        $this->expectException(ValidationException::class);
        $service->pay($second, 100, 'bank_transfer', 'BANK-REF-001', $owner->id);
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Supplier Docs', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Utama', 'code' => 'MAIN',
        ]);
        $supplier = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Pemasok',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Produk', 'sku' => 'PROD', 'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'PROD-1', 'purchase_price' => 100, 'sell_price' => 150,
        ]);

        return [$tenant, $owner, $warehouse, $supplier, $variant];
    }
}
