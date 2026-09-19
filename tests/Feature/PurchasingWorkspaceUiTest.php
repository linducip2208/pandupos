<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\PurchaseService;
use App\Services\SupplierDocumentService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingWorkspaceUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_open_workspace_and_create_purchase_order(): void
    {
        [$user, $tenant, $warehouse, $supplier, $variant] = $this->context();

        $this->actingAs($user)->get(route('purchasing.index'))
            ->assertOk()->assertSee('Purchasing Workspace')->assertSeeText('Purchase order & goods receipt');
        $this->post(route('purchasing.orders.store'), [
            'warehouse_id' => $warehouse->id, 'contact_id' => $supplier->id,
            'product_variant_id' => $variant->id, 'quantity' => 5, 'unit_id' => $variant->product->unit_id, 'unit_cost' => 100,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('purchases', ['tenant_id' => $tenant->id, 'contact_id' => $supplier->id, 'total' => 500]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'purchase.order.created']);
    }

    public function test_requester_can_manage_a_multiline_draft_without_creating_stock(): void
    {
        [$user, $tenant, $warehouse, $supplier, $variant] = $this->context();
        $second = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $variant->product_id, 'name' => 'Second',
            'sku' => 'SKU-2', 'purchase_price' => 50, 'sell_price' => 75,
        ]);

        $this->actingAs($user)->get(route('purchasing.orders.create'))
            ->assertOk()->assertSeeText('Purchase order multi-baris');
        $this->post(route('purchasing.orders.store'), [
            'warehouse_id' => $warehouse->id, 'contact_id' => $supplier->id, 'save_as' => 'draft',
            'lines' => [
                ['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_id' => $variant->product->unit_id, 'unit_cost' => 100],
                ['product_variant_id' => $second->id, 'quantity' => 3, 'unit_id' => $variant->product->unit_id, 'unit_cost' => 50],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $purchase = Purchase::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
        $this->assertSame('draft', $purchase->status);
        $this->assertSame(2, $purchase->lines()->count());
        $this->assertDatabaseMissing('stock_movements', ['tenant_id' => $tenant->id, 'reference_type' => 'purchase_receipt']);

        $this->actingAs($user)->put(route('purchasing.orders.update', $purchase), [
            'warehouse_id' => $warehouse->id, 'contact_id' => $supplier->id,
            'lines' => [['product_variant_id' => $second->id, 'quantity' => 4, 'unit_id' => $variant->product->unit_id, 'unit_cost' => 55]],
        ])->assertRedirect(route('purchasing.index'));
        $purchase->refresh();
        $this->assertSame(1, $purchase->lines()->count());
        $this->assertSame('220.00', $purchase->total);

        $this->actingAs($user)->post(route('purchasing.orders.submit', $purchase))->assertRedirect();
        $this->assertSame('ordered', $purchase->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'purchase.order.submitted']);
    }

    public function test_only_requester_in_tenant_can_change_or_cancel_unreceived_draft(): void
    {
        [$user, $tenant, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => 100,
        ]], $user->id, true);
        $other = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $other->givePermissionTo('purchase.create');

        $this->actingAs($other)->post(route('purchasing.orders.cancel', $purchase))->assertForbidden();
        $this->actingAs($user)->post(route('purchasing.orders.cancel', $purchase))->assertRedirect();
        $this->assertSame('cancelled', $purchase->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'purchase.order.cancelled']);

        $foreignUser = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Foreign PO', $foreignUser);
        $foreignBranch = Branch::withoutGlobalScopes()->where('tenant_id', $foreign->id)->firstOrFail();
        $foreignWarehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id, 'branch_id' => $foreignBranch->id, 'name' => 'Foreign Warehouse', 'code' => 'F-WH',
        ]);
        $foreignSupplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'type' => 'supplier', 'name' => 'Foreign Supplier']);
        $foreignUnit = Unit::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'Foreign Piece', 'short_name' => 'fpcs']);
        $foreignProduct = Product::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'Foreign Product', 'sku' => 'FOREIGN-P', 'unit_id' => $foreignUnit->id]);
        $foreignVariant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id, 'product_id' => $foreignProduct->id, 'name' => 'Foreign', 'sku' => 'FOREIGN-SKU',
        ]);
        $foreignPurchase = app(PurchaseService::class)->createDraft($foreign->id, $foreignWarehouse->id, $foreignSupplier->id, [[
            'product_variant_id' => $foreignVariant->id, 'quantity' => 1, 'unit_cost' => 1,
        ]], $foreignUser->id, true);

        $this->actingAs($user)->get(route('purchasing.orders.edit', $foreignPurchase))->assertNotFound();
    }

    public function test_workspace_is_hidden_without_purchasing_permission(): void
    {
        [$user] = $this->context();
        $user->revokePermissionTo(['purchase.create', 'purchase.approve']);

        $this->actingAs($user)->get(route('purchasing.index'))->assertForbidden();
    }

    public function test_authorized_tenant_can_print_supplier_invoice_but_not_another_tenants_document(): void
    {
        [$user, $tenant, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => 100,
        ]], $user->id);
        $invoice = app(SupplierDocumentService::class)->createInvoice($tenant->id, [
            'purchase_id' => $purchase->id, 'supplier_id' => $supplier->id,
            'invoice_number' => 'PRINT-001', 'invoice_date' => today()->toDateString(), 'subtotal' => 100,
        ], $user->id);

        $this->actingAs($user)->get(route('purchasing.invoices.print', $invoice))
            ->assertOk()->assertSeeText('Invoice Pemasok')->assertSeeText('PRINT-001');

        $foreignUser = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Foreign Print', $foreignUser);
        $foreignSupplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'type' => 'supplier', 'name' => 'Foreign Supplier']);
        $foreignInvoice = app(SupplierDocumentService::class)->createInvoice($foreign->id, [
            'supplier_id' => $foreignSupplier->id,
            'invoice_number' => 'FOREIGN-PRINT', 'invoice_date' => today()->toDateString(), 'subtotal' => 100,
        ], $foreignUser->id);

        $this->actingAs($user)->get(route('purchasing.invoices.print', $foreignInvoice))->assertNotFound();
    }

    public function test_authorized_tenant_can_print_purchase_order_but_not_foreign_purchase_order(): void
    {
        [$user, $tenant, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 100,
        ]], $user->id);

        $this->actingAs($user)->get(route('purchasing.orders.print', $purchase))
            ->assertOk()->assertSeeText('Purchase Order')->assertSeeText('PO #'.$purchase->id);

        $foreignUser = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Foreign PO Print', $foreignUser);
        $foreignBranch = Branch::withoutGlobalScopes()->where('tenant_id', $foreign->id)->firstOrFail();
        $foreignWarehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'branch_id' => $foreignBranch->id, 'name' => 'Foreign Warehouse', 'code' => 'F-PR']);
        $foreignSupplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'type' => 'supplier', 'name' => 'Foreign Supplier']);
        $foreignUnit = Unit::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'Foreign Unit', 'short_name' => 'fu']);
        $foreignProduct = Product::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'Foreign Product', 'sku' => 'F-PRODUCT', 'unit_id' => $foreignUnit->id]);
        $foreignVariant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'product_id' => $foreignProduct->id, 'name' => 'Foreign Variant', 'sku' => 'F-VARIANT']);
        $foreignPurchase = app(PurchaseService::class)->createDraft($foreign->id, $foreignWarehouse->id, $foreignSupplier->id, [[
            'product_variant_id' => $foreignVariant->id, 'quantity' => 1, 'unit_cost' => 1,
        ]], $foreignUser->id);

        $this->actingAs($user)->get(route('purchasing.orders.print', $foreignPurchase))->assertNotFound();
    }

    public function test_supplier_payment_requires_approval_permission_and_rejects_foreign_invoice(): void
    {
        [$user, $tenant, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => 100,
        ]], $user->id);
        $invoice = app(SupplierDocumentService::class)->createInvoice($tenant->id, [
            'purchase_id' => $purchase->id, 'supplier_id' => $supplier->id,
            'invoice_number' => 'PAY-LOCAL', 'invoice_date' => today()->toDateString(), 'subtotal' => 100,
        ], $user->id);

        $user->revokePermissionTo('purchase.approve');
        $this->actingAs($user)->post(route('purchasing.payments.store', $invoice), [
            'amount' => 100, 'method' => 'cash',
        ])->assertForbidden();
        $user->givePermissionTo('purchase.approve');

        $foreignUser = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Foreign Payment', $foreignUser);
        $foreignSupplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'type' => 'supplier', 'name' => 'Foreign Supplier']);
        $foreignInvoice = app(SupplierDocumentService::class)->createInvoice($foreign->id, [
            'supplier_id' => $foreignSupplier->id,
            'invoice_number' => 'PAY-FOREIGN', 'invoice_date' => today()->toDateString(), 'subtotal' => 100,
        ], $foreignUser->id);

        $this->actingAs($user)->post(route('purchasing.payments.store', $foreignInvoice), [
            'amount' => 100, 'method' => 'cash',
        ])->assertNotFound();
    }

    public function test_authorized_tenant_can_post_partial_receipt_with_batch_location_and_serials(): void
    {
        [$user, $tenant, $warehouse, $supplier, $variant] = $this->context();
        $location = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'RCV-A-01', 'is_active' => true,
        ]);
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 3, 'unit_cost' => 100,
        ]], $user->id);

        $this->actingAs($user)->post(route('purchasing.orders.receive', $purchase), [
            'lines' => [$variant->id => 2],
            'batch_numbers' => [$variant->id => 'UI-GRN-BATCH'],
            'manufactured_at' => [$variant->id => today()->subDay()->toDateString()],
            'expires_at' => [$variant->id => today()->addYear()->toDateString()],
            'serial_numbers' => [$variant->id => 'UI-GRN-001, UI-GRN-002'],
            'warehouse_location_id' => $location->id,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('goods_receipt_lines', ['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 2, 'warehouse_location_id' => $location->id]);
        $this->assertDatabaseHas('inventory_batches', ['tenant_id' => $tenant->id, 'batch_number' => 'UI-GRN-BATCH', 'purchase_id' => $purchase->id]);
        $this->assertDatabaseHas('serial_numbers', ['tenant_id' => $tenant->id, 'serial_number' => 'UI-GRN-001', 'purchase_id' => $purchase->id]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'purchase.goods_receipt.posted']);
    }

    public function test_authorized_tenant_can_open_multiline_purchase_return_workspace_but_not_foreign_purchase(): void
    {
        [$user, $tenant, $warehouse, $supplier, $variant] = $this->context();
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 100,
        ]], $user->id);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 2]], $tenant->id, $user->id);

        $this->actingAs($user)->get(route('purchasing.returns.create', $purchase))
            ->assertOk()->assertSeeText('Kembalikan barang yang telah diterima')->assertSee('lines[0][purchase_line_id]', false);

        $foreignUser = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Foreign return', $foreignUser);
        $foreignBranch = Branch::withoutGlobalScopes()->where('tenant_id', $foreign->id)->firstOrFail();
        $foreignWarehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'branch_id' => $foreignBranch->id, 'name' => 'Foreign Warehouse', 'code' => 'F-RET']);
        $foreignSupplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'type' => 'supplier', 'name' => 'Foreign Supplier']);
        $foreignProduct = Product::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'Foreign Product', 'sku' => 'F-RET-P']);
        $foreignVariant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'product_id' => $foreignProduct->id, 'name' => 'Default', 'sku' => 'F-RET-V']);
        $foreignPurchase = app(PurchaseService::class)->createDraft($foreign->id, $foreignWarehouse->id, $foreignSupplier->id, [[
            'product_variant_id' => $foreignVariant->id, 'quantity' => 1, 'unit_cost' => 1,
        ]], $foreignUser->id);
        app(PurchaseService::class)->receive($foreignPurchase->id, [['product_variant_id' => $foreignVariant->id, 'quantity' => 1]], $foreign->id, $foreignUser->id);

        $this->actingAs($user)->get(route('purchasing.returns.create', $foreignPurchase))->assertNotFound();
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Purchasing UI', $user);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->givePermissionTo(['purchase.create', 'purchase.approve']);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang', 'code' => 'WH',
        ]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Pemasok']);
        $unit = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Piece', 'short_name' => 'pcs']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Produk', 'sku' => 'SKU', 'unit_id' => $unit->id]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'SKU-1', 'purchase_price' => 100, 'sell_price' => 150,
        ]);

        return [$user, $tenant, $warehouse, $supplier, $variant];
    }
}
