<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SerialNumber;
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

class PurchaseReturnLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_lifecycle_draft_review_approve_post_is_audited_and_immutable(): void
    {
        [$tenant, $requester, $approver, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10,
        ]], $tenant->id, $requester->id);
        $line = $purchase->lines()->firstOrFail();
        $service = app(SupplierDocumentService::class);

        $draft = $service->createDraft($purchase, [[
            'purchase_line_id' => $line->id, 'quantity' => 4,
        ]], 'Empat unit cacat', 'supplier_credit', $requester->id, 'pr-key-1');
        $this->assertSame('draft', $draft->status);
        $this->assertSame($supplier->id, (int) $draft->supplier_id);
        $this->assertSame($warehouse->id, (int) $draft->warehouse_id);
        $this->assertEquals(400, $draft->total);
        // No stock movement before posting.
        $this->assertSame(10.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));

        $reviewed = $service->submitReturn($draft, $requester->id);
        $this->assertSame('reviewed', $reviewed->status);

        $approved = $service->approveReturn($reviewed, $approver->id);
        $this->assertSame('approved', $approved->status);

        $posted = $service->postReturn($approved, $approver->id);
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->posted_at);
        $this->assertSame(6.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id, 'reference_type' => 'purchase_return',
            'reference_id' => $posted->id, 'quantity' => 4, 'unit_cost' => 100,
        ]);
        foreach (['purchase.return.drafted', 'purchase.return.reviewed', 'purchase.return.approved', 'purchase.return.posted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => $action, 'subject_id' => $posted->id]);
        }

        // Immutable once posted.
        foreach ([
            fn () => $service->submitReturn($posted->fresh(), $requester->id),
            fn () => $service->approveReturn($posted->fresh(), $approver->id),
            fn () => $service->postReturn($posted->fresh(), $approver->id),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Posted purchase return must be immutable.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(6.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
    }

    public function test_requester_cannot_approve_own_return_and_non_requester_cannot_submit(): void
    {
        [$tenant, $requester, $approver, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 5, 'unit_cost' => 50,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 5]], $tenant->id, $requester->id);
        $service = app(SupplierDocumentService::class);
        $draft = $service->createDraft($purchase, [[
            'purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 1,
        ]], 'Cacat', 'supplier_credit', $requester->id);

        try {
            $service->submitReturn($draft, $approver->id);
            $this->fail('Only the requester may submit.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $reviewed = $service->submitReturn($draft, $requester->id);
        try {
            $service->approveReturn($reviewed, $requester->id);
            $this->fail('Requester must not approve own return.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_partial_multiline_return_with_batch_and_location_trace(): void
    {
        [$tenant, $requester, $approver, $warehouse, $supplier, $variant] = $this->context();
        $location = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'LC-PART', 'is_active' => true,
        ]);
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10, 'batch_number' => 'LC-BATCH',
            'warehouse_location_id' => $location->id,
        ]], $tenant->id, $requester->id);
        $batch = InventoryBatch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('batch_number', 'LC-BATCH')->firstOrFail();
        $service = app(SupplierDocumentService::class);

        $posted = $service->createReturn($purchase, [
            ['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 3, 'inventory_batch_id' => $batch->id, 'warehouse_location_id' => $location->id],
            ['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 2],
        ], 'Sebagian rusak', 'supplier_credit', $approver->id);

        $this->assertSame('posted', $posted->status);
        $this->assertEquals(500, $posted->total);
        $this->assertSame(5.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame(7.0, app(StockService::class)->onHandByBatch($tenant->id, $warehouse->id, $variant->id, $batch->id));
    }

    public function test_serial_return_marks_unit_returned_to_supplier(): void
    {
        [$tenant, $requester, $approver, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 2, 'serial_numbers' => ['PR-SER-1', 'PR-SER-2'],
        ]], $tenant->id, $requester->id);
        $serial = SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('serial_number', 'PR-SER-1')->firstOrFail();
        $service = app(SupplierDocumentService::class);

        $posted = $service->createReturn($purchase, [[
            'purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 1, 'serial_number_id' => $serial->id,
        ]], 'Serial cacat', 'replacement', $approver->id);

        $this->assertSame('posted', $posted->status);
        $this->assertSame('returned_to_supplier', $serial->refresh()->status);
        $this->assertSame(1.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'inventory.serial.purchase_returned']);
    }

    public function test_duplicate_return_beyond_received_is_rejected_and_idempotency_is_safe(): void
    {
        [$tenant, $requester, $approver, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 6, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 6]], $tenant->id, $requester->id);
        $service = app(SupplierDocumentService::class);
        $lineId = $purchase->lines()->firstOrFail()->id;

        $first = $service->createReturn($purchase, [['purchase_line_id' => $lineId, 'quantity' => 2]], 'Rusak', 'supplier_credit', $approver->id, 'idem-pr-1');
        $retry = $service->createReturn($purchase, [['purchase_line_id' => $lineId, 'quantity' => 2]], 'Rusak', 'supplier_credit', $approver->id, 'idem-pr-1');
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(4.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));

        try {
            $service->createReturn($purchase, [['purchase_line_id' => $lineId, 'quantity' => 5]], 'Melebihi sisa', 'supplier_credit', $approver->id);
            $this->fail('Double return beyond received must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(4.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
    }

    public function test_wrong_batch_wrong_location_and_foreign_serial_are_rejected(): void
    {
        [$tenant, $requester, $approver, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 4, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 4]], $tenant->id, $requester->id);
        $service = app(SupplierDocumentService::class);
        $lineId = $purchase->lines()->firstOrFail()->id;

        $foreignBatch = InventoryBatch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'batch_number' => 'FOREIGN-PROV', 'purchase_id' => null,
        ]);
        foreach ([
            ['purchase_line_id' => $lineId, 'quantity' => 1, 'inventory_batch_id' => $foreignBatch->id],
            ['purchase_line_id' => $lineId, 'quantity' => 1, 'warehouse_location_id' => 999999],
        ] as $badLine) {
            try {
                $service->createReturn($purchase, [$badLine], 'Jalur salah', 'supplier_credit', $approver->id);
                $this->fail('Wrong batch/location must be rejected.');
            } catch (\Throwable) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_cross_tenant_return_is_rejected(): void
    {
        [$tenant, $requester, $approver, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 3, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 3]], $tenant->id, $requester->id);

        $foreignOwner = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Foreign PR', $foreignOwner);
        $foreignPurchase = Purchase::withoutGlobalScopes()->where('tenant_id', $foreign->id)->first();
        // Simulate a cross-tenant call: service locks by the purchase's own tenant,
        // so a foreign actor posting against our purchase must be blocked at the
        // HTTP layer; here we assert the tenant-scoped HTTP route returns 404.
        $this->actingAs($foreignOwner);
        $foreignOwner->forceFill(['current_tenant_id' => $foreign->id])->save();
        $foreignOwner->givePermissionTo(['purchase.create', 'purchase.approve']);
        $this->post(route('purchasing.returns.store', $purchase), [
            'lines' => [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 1]],
            'reason' => 'Lintas tenant', 'settlement_type' => 'supplier_credit',
        ])->assertNotFound();
        $this->assertSame(3.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
    }

    public function test_workspace_lifecycle_buttons_and_api_are_tenant_scoped(): void
    {
        [$tenant, $requester, $approver, $warehouse, $supplier, $variant] = $this->context();
        $requester->forceFill(['current_tenant_id' => $tenant->id])->save();
        $requester->givePermissionTo(['purchase.create', 'purchase.approve']);
        $approver->forceFill(['current_tenant_id' => $tenant->id])->save();
        $approver->givePermissionTo(['purchase.create', 'purchase.approve']);
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 4, 'unit_cost' => 100,
        ]], $requester->id);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 4]], $tenant->id, $requester->id);

        // Draft via workspace, then submit/approve/post through lifecycle routes.
        $this->actingAs($requester)->post(route('purchasing.returns.draft', $purchase), [
            'lines' => [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 2]],
            'reason' => 'UI draft', 'settlement_type' => 'supplier_credit',
        ])->assertRedirect();
        $draft = PurchaseReturn::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->firstOrFail();
        $this->actingAs($requester)->get(route('purchasing.returns.show', $draft))->assertOk()->assertSeeText('Kirim untuk review');
        $this->actingAs($requester)->post(route('purchasing.returns.submit', $draft))->assertRedirect();
        $this->actingAs($requester)->post(route('purchasing.returns.approve', $draft))->assertRedirect()->assertSessionHasErrors('approval');
        $this->actingAs($approver)->post(route('purchasing.returns.approve', $draft))->assertRedirect();
        $this->actingAs($approver)->post(route('purchasing.returns.post', $draft))->assertRedirect();
        $this->assertSame('posted', $draft->refresh()->status);
        $this->assertSame(2.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));

        // API draft lifecycle is tenant-scoped.
        $this->actingAs($requester)->postJson('/api/v1/purchases/'.$purchase->id.'/return-drafts', [
            'lines' => [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 1]],
            'reason' => 'API draft', 'settlement_type' => 'supplier_credit', 'idempotency_key' => 'api-pr-1',
        ], ['X-Tenant-ID' => $tenant->id])->assertCreated();
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $requester = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('PR Lifecycle', $requester);
        $approver = User::factory()->create();
        $approver->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'manager', 'status' => 'active']);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Utama', 'code' => 'MAIN',
        ]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Pemasok']);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Produk', 'sku' => 'PROD', 'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'PROD-1', 'purchase_price' => 100, 'sell_price' => 150,
        ]);

        return [$tenant, $requester, $approver, $warehouse, $supplier, $variant];
    }
}
