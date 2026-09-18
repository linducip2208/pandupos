<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\StockService;
use App\Services\SupplierDocumentService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchasingSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_receipts_are_cumulative_idempotent_and_cannot_over_receive(): void
    {
        [$tenant, $owner, $warehouse, $supplier, $variant] = $this->context();
        $purchases = app(PurchaseService::class);
        $purchase = $purchases->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 100, 'unit_cost' => 10,
        ]], $owner->id);

        $this->assertSame(0.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        foreach ([30, 40, 30] as $quantity) {
            $purchases->receive($purchase->id, [[
                'product_variant_id' => $variant->id, 'quantity' => $quantity,
            ]], $tenant->id, $owner->id);
        }

        $this->assertSame('received', $purchase->fresh()->status);
        $this->assertSame(100.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame(3, $purchase->goodsReceipts()->count());

        $purchases->receive($purchase->id, null, $tenant->id, $owner->id);
        $this->assertSame(3, $purchase->goodsReceipts()->count());
        $this->assertSame(100.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));

        $this->expectException(ValidationException::class);
        $purchases->receive($purchase->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1,
        ]], $tenant->id, $owner->id);
    }

    public function test_supplier_invoice_payment_and_cross_tenant_references_are_safe(): void
    {
        [$tenant, $owner, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 10,
        ]], $owner->id);
        app(PurchaseService::class)->receive($purchase->id, null, $tenant->id, $owner->id);

        $documents = app(SupplierDocumentService::class);
        $invoiceData = [
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'SUP-SAFE-001',
            'invoice_date' => today()->toDateString(),
            'subtotal' => 100,
        ];
        $invoice = $documents->createInvoice($tenant->id, $invoiceData, $owner->id);
        $documents->pay($invoice, 40, 'bank_transfer', 'SAFE-1', $owner->id);
        $this->assertSame(60.0, (float) $invoice->fresh()->balance);

        try {
            $documents->pay($invoice->fresh(), 61, 'bank_transfer', 'SAFE-2', $owner->id);
            $this->fail('Overpayment must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(60.0, (float) $invoice->fresh()->balance);
        }

        $this->expectException(ValidationException::class);
        $documents->createInvoice($tenant->id, $invoiceData, $owner->id);
    }

    public function test_purchase_and_supplier_references_from_another_tenant_are_rejected(): void
    {
        [$tenant, $owner, $warehouse, $supplier, $variant] = $this->context();
        [$foreignTenant, , $foreignWarehouse, $foreignSupplier] = $this->context();

        $this->expectException(HttpException::class);
        app(PurchaseService::class)->createDraft($tenant->id, $foreignWarehouse->id, $foreignSupplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => 10,
        ]], $owner->id);
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Purchasing Safety', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Safety Warehouse', 'code' => 'SAFE',
        ]);
        $supplier = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Safety Supplier',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Safety Product', 'sku' => 'SAFE-PROD', 'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'SAFE-VAR-'.uniqid(), 'purchase_price' => 10, 'sell_price' => 20,
        ]);

        return [$tenant, $owner, $warehouse, $supplier, $variant];
    }
}
