<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Complete RBAC matrix: every critical business action is denied server-side
 * for a role that lacks its permission (403), and the protected state is
 * unchanged. Permissions are never menu-only.
 */
class RbacCriticalActionsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('RBAC Matrix', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'W', 'code' => 'W',
        ]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'S']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'C']);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'P', 'sku' => 'SKU-'.uniqid(), 'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'D',
            'sku' => 'V-'.uniqid(), 'purchase_price' => 100, 'sell_price' => 150,
        ]);
        $purchase = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 100,
        ]], $owner->id);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 10]], $tenant->id, $owner->id);
        $invoice = app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, $customer->id, [
            ['variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 150],
        ], [['method' => 'cash', 'amount' => 300]], 'rbac-'.uniqid());

        // Operator holds only basic POS creation: every privileged action below must 403.
        $operator = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $operator->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'cashier', 'status' => 'active']);
        $operator->assignRole('cashier');
        foreach (['pos.sale.void', 'purchase.create', 'purchase.approve', 'inventory.adjust', 'inventory.transfer', 'register.manage', 'register.open', 'register.close', 'reports.view', 'sales.view', 'products.manage'] as $perm) {
            $operator->revokePermissionTo($perm);
        }
        $operator->givePermissionTo('pos.sale.create');

        return compact('owner', 'tenant', 'branch', 'warehouse', 'supplier', 'customer', 'product', 'variant', 'purchase', 'invoice', 'operator');
    }

    public function test_privileged_actions_are_denied_for_basic_operator(): void
    {
        $s = $this->fixture();
        $op = $s['operator'];
        $stockBefore = app(StockService::class)->onHand($s['tenant']->id, $s['warehouse']->id, $s['variant']->id);

        // Purchase + supplier payment.
        $this->actingAs($op)->post(route('purchasing.orders.store'), [])->assertForbidden();
        $this->actingAs($op)->post(route('purchasing.returns.store', $s['purchase']), [])->assertForbidden();

        // Inventory control plane.
        $this->actingAs($op)->post(route('inventory.adjustments.store'), [])->assertForbidden();
        $this->actingAs($op)->post(route('inventory.transfers.store'), [])->assertForbidden();
        $this->actingAs($op)->post(route('inventory.counts.store'), [])->assertForbidden();
        $this->actingAs($op)->post(route('inventory.reservations.store'), [])->assertForbidden();

        // POS destructive actions (operator lacks pos.sale.void; returns are allowed via pos.sale.create).
        $this->actingAs($op)->post(route('sales-returns.void.store', $s['invoice']), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($op)->post(route('sales-returns.returns.store', $s['invoice']), [
            'lines' => [['variant_id' => $s['variant']->id, 'quantity' => 1, 'unit_price' => 150]], 'reason' => 'allowed return',
        ])->assertRedirect();

        // Registers, reports, exports, product management.
        $this->actingAs($op)->get(route('registers.index'))->assertForbidden();
        $this->actingAs($op)->get(route('reports.show', 'bisnis'))->assertForbidden();
        $this->actingAs($op)->get(route('reports.csv', 'bisnis'))->assertForbidden();
        $this->actingAs($op)->get(route('reports.xlsx', 'bisnis'))->assertForbidden();
        $this->actingAs($op)->get(route('reports.pdf', 'bisnis'))->assertForbidden();
        $this->actingAs($op)->get(route('product-master.create'))->assertForbidden();

        // Platform + SaaS administration.
        $this->actingAs($op)->get(route('platform.tenants.index'))->assertForbidden();
        $this->actingAs($op)->get(route('platform.plans.index'))->assertForbidden();
        $this->actingAs($op)->get(route('platform.billing.index'))->assertForbidden();

        // Only the explicitly permitted return moved stock (+1 audited restoration).
        $this->assertSame($stockBefore + 1, app(StockService::class)->onHand($s['tenant']->id, $s['warehouse']->id, $s['variant']->id));
        $this->assertSame('final', $s['invoice']->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.return.posted']);
    }

    public function test_api_enforces_the_same_permission_boundaries(): void
    {
        $s = $this->fixture();
        $op = $s['operator'];

        $this->actingAs($op)->postJson('/api/v1/sales/'.$s['invoice']->id.'/void', ['reason' => 'x'], ['X-Tenant-ID' => $s['tenant']->id])->assertForbidden();
        // Returns are permitted by pos.sale.create: prove the granted path records an audited return.
        $this->actingAs($op)->postJson('/api/v1/sales/'.$s['invoice']->id.'/returns', [
            'lines' => [['variant_id' => $s['variant']->id, 'quantity' => 1, 'unit_price' => 150]], 'reason' => 'allowed api return',
        ], ['X-Tenant-ID' => $s['tenant']->id])->assertCreated();
        $this->actingAs($op)->getJson('/api/v1/sales', ['X-Tenant-ID' => $s['tenant']->id])->assertForbidden();
        $this->actingAs($op)->getJson('/api/v1/reports/sales', ['X-Tenant-ID' => $s['tenant']->id])->assertForbidden();
        $this->assertSame('final', $s['invoice']->refresh()->status);
    }

    public function test_granted_permission_allows_the_action(): void
    {
        $s = $this->fixture();
        $s['operator']->givePermissionTo('reports.view');
        $this->actingAs($s['operator'])->get(route('reports.show', 'bisnis'))->assertOk();
        $s['operator']->givePermissionTo('pos.sale.void');
        $this->actingAs($s['operator'])->post(route('sales-returns.void.store', $s['invoice']), ['reason' => 'now allowed'])->assertRedirect();
        $this->assertSame('void', $s['invoice']->refresh()->status);
    }
}
