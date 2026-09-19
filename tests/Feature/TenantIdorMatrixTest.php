<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerialNumber;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\SupplierDocumentService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Complete tenant-isolation / IDOR matrix: tenant B probes tenant A's records
 * across every critical resource (web + API + export + files + SaaS). Every
 * probe must 403/404 and never expose A's data; stock and money are unchanged.
 */
class TenantIdorMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(PlatformSeeder::class);
        $ownerA = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('IDOR Alpha', $ownerA);
        $ownerA->forceFill(['current_tenant_id' => $tenantA->id])->save();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'branch_id' => $branch->id, 'name' => 'WA', 'code' => 'WA',
        ]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantA->id, 'type' => 'supplier', 'name' => 'SUP-IDOR-SECRET']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantA->id, 'type' => 'customer', 'name' => 'CUST-IDOR-SECRET']);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'name' => 'PROD-IDOR-SECRET', 'sku' => 'IDOR-'.uniqid(),
            'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'IDOR-V-'.uniqid(), 'purchase_price' => 100, 'sell_price' => 150,
        ]);
        $location = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'warehouse_id' => $warehouse->id, 'code' => 'IDOR-RACK', 'is_active' => true,
        ]);
        $purchase = app(PurchaseService::class)->createDraft($tenantA->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 8, 'unit_cost' => 100,
        ]], $ownerA->id);
        app(PurchaseService::class)->receive($purchase->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 2, 'batch_number' => 'IDOR-BATCH',
            'serial_numbers' => ['IDOR-SER-1', 'IDOR-SER-2'],
        ]], $tenantA->id, $ownerA->id);
        $batch = InventoryBatch::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->where('batch_number', 'IDOR-BATCH')->firstOrFail();
        $serial = SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->where('serial_number', 'IDOR-SER-1')->firstOrFail();
        $docs = app(SupplierDocumentService::class);
        $supplierInvoice = $docs->createInvoice($tenantA->id, [
            'purchase_id' => $purchase->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'IDOR-SUP-1',
            'invoice_date' => today()->toDateString(), 'subtotal' => 800,
        ], $ownerA->id);
        $invoice = app(SaleService::class)->checkout($tenantA->id, $branch->id, $warehouse->id, $customer->id, [
            ['variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 150],
        ], [['method' => 'cash', 'amount' => 300]], 'idor-'.uniqid());
        $salesReturn = app(SaleService::class)->return($invoice->id, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 150],
        ], $tenantA->id, $ownerA->id, 'idor-ret-'.uniqid(), 'IDOR retur', true);

        $ownerB = User::factory()->create();
        $tenantB = app(TenantProvisioningService::class)->provision('IDOR Beta', $ownerB);
        $ownerB->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $ownerB->givePermissionTo(['purchase.create', 'purchase.approve', 'pos.sale.create', 'pos.sale.void', 'sales.view', 'reports.view', 'inventory.adjust', 'inventory.transfer', 'products.manage', 'inventory.view']);

        return compact('ownerA', 'tenantA', 'branch', 'warehouse', 'supplier', 'customer', 'product', 'variant', 'location', 'purchase', 'batch', 'serial', 'supplierInvoice', 'invoice', 'salesReturn', 'ownerB', 'tenantB');
    }

    private function assertBlocked($response, string $secret = 'IDOR-SECRET'): void
    {
        $this->assertContains($response->status(), [403, 404]);
        $response->assertDontSee($secret);
    }

    /** Tenant-scoped index pages render 200 with the actor's own data: assert no foreign leak. */
    private function assertScopedIndex($response, string $secret = 'IDOR-SECRET'): void
    {
        $response->assertOk()->assertDontSee($secret);
    }

    /**
     * Cross-tenant writes are neutralized either by tenant-scope 404/403 or by
     * validation rejection (302/422) — in all cases without state change,
     * which the callers verify via stock and status assertions.
     */
    private function assertRejectedWrite($response): void
    {
        $this->assertContains($response->status(), [302, 403, 404, 422]);
        if ($response->status() === 302) {
            $response->assertSessionHasErrors();
        }
    }

    public function test_cross_tenant_web_reads_are_blocked(): void
    {
        $s = $this->fixture();
        $b = $s['ownerB'];

        $this->assertBlocked($this->actingAs($b)->get(route('product-master.show', $s['product'])), 'PROD-IDOR-SECRET');
        $this->assertBlocked($this->actingAs($b)->get(route('product-master.edit', $s['product'])), 'PROD-IDOR-SECRET');
        $this->assertBlocked($this->actingAs($b)->get(route('purchasing.orders.print', $s['purchase'])), 'SUP-IDOR-SECRET');
        $this->assertBlocked($this->actingAs($b)->get(route('purchasing.orders.edit', $s['purchase'])), 'SUP-IDOR-SECRET');
        $this->assertBlocked($this->actingAs($b)->get(route('purchasing.returns.create', $s['purchase'])));
        $this->assertBlocked($this->actingAs($b)->get(route('purchasing.invoices.print', $s['supplierInvoice'])), 'IDOR-SUP-1');
        $this->assertScopedIndex($this->actingAs($b)->get(route('sales-documents.index')));
        $this->assertScopedIndex($this->actingAs($b)->get(route('sales-returns.index')));
        $this->assertScopedIndex($this->actingAs($b)->get(route('inventory.index')));
        $this->assertScopedIndex($this->actingAs($b)->get(route('batches.index')), 'IDOR-BATCH');
        $this->assertScopedIndex($this->actingAs($b)->get(route('serials.index')), 'IDOR-SER-1');
        $this->assertScopedIndex($this->actingAs($b)->get(route('registers.index')));
    }

    public function test_cross_tenant_web_writes_are_blocked_without_state_change(): void
    {
        $s = $this->fixture();
        $b = $s['ownerB'];
        $stockBefore = app(StockService::class)->onHand($s['tenantA']->id, $s['warehouse']->id, $s['variant']->id);

        $this->actingAs($b)->post(route('purchasing.orders.receive', $s['purchase']), ['lines' => [$s['variant']->id => 1]])->assertNotFound();
        $this->actingAs($b)->post(route('purchasing.returns.store', $s['purchase']), [
            'lines' => [['purchase_line_id' => $s['purchase']->lines()->firstOrFail()->id, 'quantity' => 1]],
            'reason' => 'smuggle', 'settlement_type' => 'supplier_credit',
        ])->assertNotFound();
        $this->actingAs($b)->post(route('purchasing.payments.store', $s['supplierInvoice']), ['amount' => 10, 'method' => 'cash'])->assertNotFound();
        $this->actingAs($b)->post(route('sales-returns.returns.store', $s['invoice']), [
            'lines' => [['variant_id' => $s['variant']->id, 'quantity' => 1]], 'reason' => 'smuggle',
        ])->assertNotFound();
        $this->actingAs($b)->post(route('sales-returns.refunds.store', $s['salesReturn']), [
            'amount' => 10, 'method' => 'cash', 'reason' => 'smuggle',
        ])->assertNotFound();
        $this->actingAs($b)->post(route('sales-returns.void.store', $s['invoice']), ['reason' => 'smuggle'])->assertNotFound();
        $this->assertRejectedWrite($this->actingAs($b)->post(route('inventory.adjustments.store'), [
            'warehouse_id' => $s['warehouse']->id, 'reason' => 'damage', 'notes' => 'smuggle',
            'lines' => [['product_variant_id' => $s['variant']->id, 'quantity_change' => 1]],
        ]));
        $this->assertRejectedWrite($this->actingAs($b)->post(route('inventory.transfers.store'), [
            'from_warehouse_id' => $s['warehouse']->id, 'to_warehouse_id' => $s['warehouse']->id,
            'lines' => [['product_variant_id' => $s['variant']->id, 'quantity' => 1]],
        ]));

        $this->assertSame($stockBefore, app(StockService::class)->onHand($s['tenantA']->id, $s['warehouse']->id, $s['variant']->id));
        $this->assertSame('final', $s['invoice']->refresh()->status);
    }

    public function test_cross_tenant_api_and_exports_are_blocked(): void
    {
        $s = $this->fixture();
        $b = $s['ownerB'];
        $tokenB = $b->createToken('b', ['*'], now()->addDay())->plainTextToken;

        foreach (['bisnis', 'keuangan', 'operasional'] as $type) {
            $this->assertScopedIndex($this->actingAs($b)->get(route('reports.show', $type)), 'PROD-IDOR-SECRET');
            $this->assertScopedIndex($this->actingAs($b)->get(route('reports.csv', $type)), 'PROD-IDOR-SECRET');
        }

        auth()->forgetGuards();
        $this->withToken($tokenB)->getJson('/api/v1/inventory/serials', ['X-Tenant-ID' => $s['tenantB']->id])
            ->assertOk()->assertDontSee('IDOR-SER-1');
        auth()->forgetGuards();
        $this->withToken($tokenB)->getJson('/api/v1/inventory/expiry', ['X-Tenant-ID' => $s['tenantB']->id])
            ->assertOk()->assertDontSee('IDOR-BATCH');
        auth()->forgetGuards();
        $this->withToken($tokenB)->postJson('/api/v1/sales/'.$s['invoice']->id.'/void', ['reason' => 'smuggle'], ['X-Tenant-ID' => $s['tenantB']->id])
            ->assertStatus(404);
    }

    public function test_cross_tenant_serial_batch_and_saas_records_are_blocked(): void
    {
        $s = $this->fixture();
        $b = $s['ownerB'];

        $this->assertRejectedWrite($this->actingAs($b)->post(route('serials.reserve'), ['serial_number_id' => $s['serial']->id, 'reference_type' => 'sale', 'reference_id' => 1]));
        $this->actingAs($b)->post(route('serials.release', $s['serial']))->assertNotFound();
        $this->assertRejectedWrite($this->actingAs($b)->post(route('batches.receive'), [
            'warehouse_id' => $s['warehouse']->id, 'product_variant_id' => $s['variant']->id, 'batch_number' => 'X', 'quantity' => 1,
        ]));

        // SaaS control plane is platform-gated: tenant owners cannot reach it.
        $this->actingAs($b)->get(route('platform.tenants.show', $s['tenantA']))->assertForbidden();
        $this->actingAs($b)->get(route('platform.subscriptions.index'))->assertForbidden();
        $this->actingAs($b)->get(route('platform.billing.index'))->assertForbidden();
        $this->actingAs($b)->get(route('platform.coupons.index'))->assertForbidden();
        $this->assertSame('available', $s['serial']->refresh()->status);
    }
}
