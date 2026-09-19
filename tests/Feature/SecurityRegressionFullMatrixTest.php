<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
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
 * Full endpoint matrix: every critical resource is probed cross-tenant
 * (tenant B actor against tenant A records/routes) and by an unauthorized
 * role. Cross-tenant must 403/404 and never leak A's data; unauthorized
 * roles must 403 on guarded actions.
 */
class SecurityRegressionFullMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function setupA(): array
    {
        $this->seed(PlatformSeeder::class);
        $ownerA = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('Tenant Alpha Matrix', $ownerA);
        $ownerA->forceFill(['current_tenant_id' => $tenantA->id])->save();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'branch_id' => $branch->id, 'name' => 'Gudang A', 'code' => 'GA',
        ]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantA->id, 'type' => 'supplier', 'name' => 'SUP-ALPHA-SECRET']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantA->id, 'type' => 'customer', 'name' => 'CUST-ALPHA-SECRET']);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'name' => 'PROD-ALPHA-SECRET', 'sku' => 'ALPHA-'.uniqid(),
            'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'ALPHA-V-'.uniqid(), 'purchase_price' => 100, 'sell_price' => 150,
        ]);
        $purchase = app(PurchaseService::class)->createDraft($tenantA->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 100,
        ]], $ownerA->id);
        app(PurchaseService::class)->receive($purchase->id, [['product_variant_id' => $variant->id, 'quantity' => 10]], $tenantA->id, $ownerA->id);
        $invoice = app(SaleService::class)->checkout($tenantA->id, $branch->id, $warehouse->id, $customer->id, [
            ['variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 150],
        ], [['method' => 'cash', 'amount' => 300]], 'matrix-'.uniqid());

        $cashier = User::factory()->create(['current_tenant_id' => $tenantA->id]);
        $cashier->memberships()->create(['tenant_id' => $tenantA->id, 'role' => 'cashier', 'status' => 'active']);
        $cashier->assignRole('cashier');

        $ownerB = User::factory()->create();
        $tenantB = app(TenantProvisioningService::class)->provision('Tenant Beta Matrix', $ownerB);
        $ownerB->forceFill(['current_tenant_id' => $tenantB->id])->save();

        return compact('ownerA', 'tenantA', 'branch', 'warehouse', 'supplier', 'customer', 'product', 'variant', 'purchase', 'invoice', 'cashier', 'ownerB', 'tenantB');
    }

    private function assertBlocked($response, string $secret): void
    {
        $this->assertContains($response->status(), [403, 404]);
        $response->assertDontSee($secret);
    }

    public function test_cross_tenant_cannot_read_write_core_resources(): void
    {
        $s = $this->setupA();
        $b = $s['ownerB'];

        // Product master.
        $this->assertBlocked($this->actingAs($b)->get(route('product-master.show', $s['product'])), 'PROD-ALPHA-SECRET');
        $this->assertBlocked($this->actingAs($b)->get(route('product-master.edit', $s['product'])), 'PROD-ALPHA-SECRET');

        // Purchasing documents.
        $this->assertBlocked($this->actingAs($b)->get(route('purchasing.orders.print', $s['purchase'])), 'SUP-ALPHA-SECRET');
        $this->assertBlocked($this->actingAs($b)->get(route('purchasing.returns.create', $s['purchase'])), 'Kembalikan');

        // Sales returns workspace + invoice-bound actions resolve under A's tenant scope only.
        $this->actingAs($b)->post(route('sales-returns.returns.store', $s['invoice']), [
            'lines' => [['variant_id' => $s['variant']->id, 'quantity' => 1]], 'reason' => 'x',
        ])->assertNotFound();
        $this->actingAs($b)->post(route('sales-returns.void.store', $s['invoice']), ['reason' => 'x'])->assertNotFound();

        // Reports and exports render only the actor's own tenant scope: never A's rows.
        foreach (['bisnis', 'keuangan', 'operasional'] as $type) {
            $this->actingAs($b)->get(route('reports.show', $type))->assertOk()->assertDontSee('PROD-ALPHA-SECRET');
            $this->actingAs($b)->get(route('reports.csv', $type))->assertOk()->assertDontSee('PROD-ALPHA-SECRET');
        }

        // Dashboard carries no foreign aggregates.
        $this->actingAs($b)->get(route('dashboard'))->assertOk()->assertDontSee('PROD-ALPHA-SECRET');

        // Stock untouched by all blocked attempts.
        $this->assertSame(8.0, app(StockService::class)->onHand($s['tenantA']->id, $s['warehouse']->id, $s['variant']->id));
    }

    public function test_cross_tenant_api_never_leaks_records(): void
    {
        $s = $this->setupA();
        $b = $s['ownerB'];

        // Server-side current_tenant wins over the header: B's session stays in B's
        // scope even with a foreign header, so A's records never leak.
        $res = $this->actingAs($b)->getJson('/api/v1/products', ['X-Tenant-ID' => $s['tenantA']->id]);
        $res->assertOk()->assertDontSee('PROD-ALPHA-SECRET');

        // Header-only resolution (no current tenant) enforces membership: foreign header → 403.
        $loner = User::factory()->create(['current_tenant_id' => null]);
        $this->actingAs($loner)->getJson('/api/v1/products', ['X-Tenant-ID' => $s['tenantA']->id])->assertForbidden();
        $this->actingAs($loner)->getJson('/api/v1/sales', ['X-Tenant-ID' => $s['tenantA']->id])->assertForbidden();

        // Body tenant override is rejected on write paths.
        $this->actingAs($b)->postJson('/api/v1/sales/'.$s['invoice']->id.'/returns', [
            'lines' => [['variant_id' => $s['variant']->id, 'quantity' => 1]],
            'reason' => 'smuggle', 'tenant_id' => $s['tenantA']->id,
        ], ['X-Tenant-ID' => $s['tenantB']->id])->assertStatus(422);
    }

    public function test_rbac_blocks_unauthorized_roles_on_critical_actions(): void
    {
        $s = $this->setupA();
        $cashier = $s['cashier'];
        $this->assertFalse($cashier->can('pos.sale.void'));

        // Cashier without void permission cannot void via web or API.
        $this->actingAs($cashier)->post(route('sales-returns.void.store', $s['invoice']), ['reason' => 'coba'])->assertForbidden();
        $this->actingAs($cashier)->postJson('/api/v1/sales/'.$s['invoice']->id.'/void', ['reason' => 'coba'], ['X-Tenant-ID' => $s['tenantA']->id])->assertForbidden();

        // Cashier cannot approve/post purchase returns.
        $this->actingAs($cashier)->post(route('purchasing.returns.store', $s['purchase']), [
            'lines' => [['purchase_line_id' => $s['purchase']->lines()->firstOrFail()->id, 'quantity' => 1]],
            'reason' => 'coba', 'settlement_type' => 'supplier_credit',
        ])->assertForbidden();

        // Cashier cannot touch platform admin.
        $this->actingAs($cashier)->get(route('platform.tenants.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('platform.audit.index'))->assertForbidden();

        // State unchanged.
        $this->assertSame('final', $s['invoice']->refresh()->status);
        $this->assertSame(8.0, app(StockService::class)->onHand($s['tenantA']->id, $s['warehouse']->id, $s['variant']->id));
    }

    public function test_platform_admin_routes_require_admin_and_audit(): void
    {
        $s = $this->setupA();
        $s['ownerA']->forceFill(['is_platform_admin' => false])->save();
        $this->actingAs($s['ownerA'])->get(route('platform.tenants.index'))->assertForbidden();
        $this->actingAs($s['ownerB'])->post(route('platform.tenants.suspend', $s['tenantA']))->assertForbidden();
        $this->assertSame('trial', $s['tenantA']->refresh()->status);
    }
}
