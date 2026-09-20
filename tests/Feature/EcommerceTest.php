<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EcommerceService;
use App\Services\ModuleManager;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Ecommerce module: published catalog, carts, checkout, single-deduction
 * payment, shipment lifecycle, public shop by slug, isolation, RBAC, API.
 */
class EcommerceTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Online', bool $enableEcommerce = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        $tenant->refresh();
        if ($enableEcommerce) {
            app(ModuleManager::class)->enable($tenant->id, 'ecommerce');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    /** @return array{Warehouse, variant} */
    private function stocked(string $sku, $tenant, int $qty = 10): array
    {
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang '.$sku, 'code' => 'WH-'.uniqid()]);
        $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => $sku, 'sku' => $sku, 'product_type' => 'stock', 'is_online' => true, 'track_inventory' => true]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'Default', 'sku' => $sku.'-V', 'purchase_price' => 50, 'sell_price' => 100]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, $qty, 50, 'opening', null);

        return [$warehouse, $variant];
    }

    public function test_module_gates_and_catalog_visibility(): void
    {
        [$tenant, $owner] = $this->context('Toko Shop Gate', false);
        [$warehouse, $variant] = $this->stocked('GATE', $tenant);

        $this->actingAs($owner)->get('/ecommerce')->assertForbidden();
        $this->get('/shop/'.$tenant->slug)->assertNotFound();

        app(ModuleManager::class)->enable($tenant->id, 'ecommerce');
        // Offline products stay hidden; published ones appear with prices.
        $this->get('/shop/'.$tenant->slug)->assertOk()->assertSeeText('GATE');
        $variant->product->update(['is_online' => false]);
        $this->get('/shop/'.$tenant->slug)->assertOk()->assertDontSee('GATE-V');
        $this->actingAs($owner)->get('/ecommerce')->assertOk();
    }

    public function test_cart_checkout_pay_ship_deliver_flow(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $variant] = $this->stocked('FLOW', $tenant);
        $svc = app(EcommerceService::class);
        $email = 'buyer@example.com';

        $cart = $svc->addToCart($tenant->id, $email, $variant->id, 2);
        $this->assertSame(200.0, $cart['total']);
        // Offline products cannot enter the cart (visibility is per product).
        $offlineProduct = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Offline', 'sku' => 'OFF', 'product_type' => 'stock', 'is_online' => false, 'track_inventory' => true]);
        $hidden = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $offlineProduct->id, 'name' => 'Hidden', 'sku' => 'FLOW-H', 'purchase_price' => 1, 'sell_price' => 1]);
        try {
            $svc->addToCart($tenant->id, $email, $hidden->id, 1);
            $this->fail('Offline variant must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $order = $svc->checkout($tenant->id, [
            'email' => $email, 'recipient' => 'Pembeli', 'phone' => '0811', 'address' => 'Jl. Mawar 1',
            'shipping_method' => 'express',
        ]);
        $this->assertSame('pending', $order->status);
        $this->assertSame(25200.0, (float) $order->total); // 200 + 25000 express shipping
        // Stock reserved? No — validated at checkout, deducted at payment.
        $this->assertSame(10.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));

        // Wrong amount refused; exact payment deducts once.
        try {
            $svc->markPaid($order, 200, 'transfer', $owner->id);
            $this->fail('Underpayment must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $svc->markPaid($order->refresh(), 25200, 'transfer', $owner->id);
        $this->assertSame(8.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));

        $shipped = $svc->ship($order->refresh(), 'RESI-1', $owner->id);
        $this->assertSame('shipped', $shipped->status);
        $this->assertSame('RESI-1', $shipped->tracking_number);
        $delivered = $svc->deliver($order->refresh(), $owner->id);
        $this->assertSame('delivered', $delivered->status);
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('reference_type', 'ecommerce_sale')->where('reference_id', $delivered->id)->count());
    }

    public function test_pending_orders_cancel_and_paid_do_not(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $variant] = $this->stocked('CX', $tenant);
        $svc = app(EcommerceService::class);
        $svc->addToCart($tenant->id, 'c@example.com', $variant->id, 1);
        $order = $svc->checkout($tenant->id, ['email' => 'c@example.com', 'recipient' => 'C', 'phone' => '1', 'address' => 'A']);

        $this->assertSame('cancelled', $svc->cancel($order, $owner->id)->status);

        $svc->addToCart($tenant->id, 'd@example.com', $variant->id, 1);
        $order2 = $svc->checkout($tenant->id, ['email' => 'd@example.com', 'recipient' => 'D', 'phone' => '1', 'address' => 'A']);
        $svc->markPaid($order2, (float) $order2->total, 'transfer', $owner->id);
        try {
            $svc->cancel($order2->refresh(), $owner->id);
            $this->fail('Cancelling a paid order must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko Shop A');
        [$tenantB, $ownerB] = $this->context('Toko Shop B');
        [$warehouseA, $variantA] = $this->stocked('IA', $tenantA);
        app(EcommerceService::class)->addToCart($tenantA->id, 'a@example.com', $variantA->id, 1);
        $orderA = app(EcommerceService::class)->checkout($tenantA->id, ['email' => 'a@example.com', 'recipient' => 'A', 'phone' => '1', 'address' => 'A']);

        // B's admin surface never sees A's order; slug shops are separate.
        TenantContext::setId($tenantB->id);
        $this->actingAs($ownerB)->get('/ecommerce')->assertOk()->assertDontSee($orderA->number);
        $this->actingAs($ownerB)->post("/ecommerce/orders/{$orderA->id}/transition", ['action' => 'cancel'])->assertNotFound();
        $this->get('/shop/'.$tenantB->slug)->assertOk()->assertDontSee('IA-V');

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/ecommerce')->assertForbidden();
        $member->givePermissionTo('ecommerce.view');
        $this->actingAs($member)->get('/ecommerce')->assertOk();
        // An existing B order proves the manage gate (not the binding) denies.
        TenantContext::setId($tenantB->id);
        [$warehouseB, $variantB] = $this->stocked('IB', $tenantB);
        app(EcommerceService::class)->addToCart($tenantB->id, 'b@example.com', $variantB->id, 1);
        $orderB = app(EcommerceService::class)->checkout($tenantB->id, ['email' => 'b@example.com', 'recipient' => 'B', 'phone' => '1', 'address' => 'B']);
        $this->actingAs($member)->post("/ecommerce/orders/{$orderB->id}/transition", ['action' => 'cancel'])->assertForbidden();
    }

    public function test_public_shop_api_flow(): void
    {
        [$tenant] = $this->context();
        [$warehouse, $variant] = $this->stocked('API', $tenant);

        $this->getJson('/api/v1/shop/'.$tenant->slug.'/catalog')->assertOk()->assertJsonPath('data.0.sku', 'API');
        $this->postJson('/api/v1/shop/'.$tenant->slug.'/cart', ['email' => 'api@example.com', 'variant_id' => $variant->id, 'quantity' => 3])
            ->assertCreated()->assertJsonPath('data.total', 300);
        $created = $this->postJson('/api/v1/shop/'.$tenant->slug.'/checkout', [
            'email' => 'api@example.com', 'recipient' => 'API Buyer', 'phone' => '0811', 'address' => 'Jl. API',
        ])->assertCreated();
        $number = $created->json('data.number');
        $this->getJson('/api/v1/shop/'.$tenant->slug.'/track?number='.$number.'&email=api@example.com')->assertOk()->assertJsonPath('data.status', 'pending');
        // Suspended tenants disappear from the storefront.
        $tenant->update(['status' => 'suspended']);
        $this->getJson('/api/v1/shop/'.$tenant->slug.'/catalog')->assertNotFound();
    }

    public function test_admin_api_and_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $variant] = $this->stocked('ADM', $tenant);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $svc = app(EcommerceService::class);
        $svc->addToCart($tenant->id, 'adm@example.com', $variant->id, 1);
        $order = $svc->checkout($tenant->id, ['email' => 'adm@example.com', 'recipient' => 'Adm', 'phone' => '1', 'address' => 'A']);

        $this->actingAs($owner)->getJson('/api/v1/ecommerce/orders', $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/ecommerce/orders/{$order->id}/transition", ['action' => 'pay', 'amount' => (float) $order->total, 'method' => 'transfer'], $headers)->assertOk()->assertJsonPath('data.status', 'paid');

        $this->actingAs($owner)->get('/ecommerce')->assertOk()->assertSeeText($order->number);
        $this->actingAs($owner)->post("/ecommerce/orders/{$order->id}/transition", ['action' => 'ship', 'tracking_number' => 'R1'])->assertRedirect();
        $this->actingAs($owner)->post("/ecommerce/orders/{$order->id}/transition", ['action' => 'deliver'])->assertRedirect();
        $this->assertSame('delivered', $order->refresh()->status);
    }
}
