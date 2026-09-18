<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
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
