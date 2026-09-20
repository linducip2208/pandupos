<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EcommerceOrder;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WoConnection;
use App\Models\WoSyncLog;
use App\Services\ModuleManager;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Services\WooCommerce\FakeWooClient;
use App\Services\WooSyncService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WooCommerce connector: encrypted credentials, product/inventory push,
 * idempotent order/customer pull, signed webhooks, isolation, RBAC.
 */
class WooCommerceTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Woo', bool $enableWoo = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableWoo) {
            app(ModuleManager::class)->enable($tenant->id, 'ecommerce');
            app(ModuleManager::class)->enable($tenant->id, 'woocommerce');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    private function connection($tenant, $owner): WoConnection
    {
        return app(WooSyncService::class)->createConnection($tenant->id, [
            'name' => 'Toko Live', 'store_url' => 'https://toko.example',
            'consumer_key' => 'ck_test', 'consumer_secret' => 'shhh-secret',
        ], $owner?->id);
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

    public function test_module_gates_and_dependency(): void
    {
        [$tenant, $owner] = $this->context('Toko Woo Gate', false);

        $this->actingAs($owner)->get('/woo')->assertForbidden();
        try {
            app(ModuleManager::class)->enable($tenant->id, 'woocommerce');
            $this->fail('WooCommerce without ecommerce must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ecommerce', $e->getMessage());
        }
        app(ModuleManager::class)->enable($tenant->id, 'ecommerce');
        app(ModuleManager::class)->enable($tenant->id, 'woocommerce');
        $this->actingAs($owner)->get('/woo')->assertOk()->assertSeeText('Koneksi');
    }

    public function test_credentials_are_encrypted_and_push_links_products(): void
    {
        [$tenant, $owner] = $this->context();
        [, $variant] = $this->stocked('WOO', $tenant);
        $conn = $this->connection($tenant, $owner);

        $raw = WoConnection::withoutGlobalScopes()->find($conn->id);
        $this->assertStringNotContainsString('shhh-secret', $raw->getAttributes()['consumer_secret']);
        $this->assertStringNotContainsString('ck_test', $raw->getAttributes()['consumer_key']);

        $fake = new FakeWooClient;
        $link = app(WooSyncService::class)->pushProduct($conn, $variant->id, $fake, $owner->id);
        $this->assertNotNull($link->woo_product_id);
        $this->assertSame('WOO-V', $fake->products[$link->woo_product_id]['sku']);
        // Second push updates in place: no duplicate remote product.
        app(WooSyncService::class)->pushProduct($conn, $variant->id, $fake, $owner->id);
        $this->assertCount(1, $fake->products);

        // Inventory push sends on-hand levels for linked variants.
        $this->assertSame(1, app(WooSyncService::class)->pushInventory($conn, $fake, $owner->id));
        $this->assertSame(10, $fake->products[$link->woo_product_id]['stock_quantity']);
    }

    public function test_pull_imports_orders_idempotently_and_rejects_unmapped_sku(): void
    {
        [$tenant, $owner] = $this->context();
        [, $variant] = $this->stocked('SYNC', $tenant);
        $conn = $this->connection($tenant, $owner);
        $fake = new FakeWooClient;
        $fake->seedOrder([
            'id' => 9001,
            'billing' => ['email' => 'woo@example.com', 'first_name' => 'Woo', 'last_name' => 'Buyer', 'phone' => '0811', 'address_1' => 'Jl. Woo', 'city' => 'Jakarta'],
            'line_items' => [['sku' => 'SYNC-V', 'quantity' => 2, 'price' => 100]],
        ]);
        $fake->seedOrder([
            'id' => 9002,
            'billing' => ['email' => 'bad@example.com', 'first_name' => 'Bad', 'last_name' => 'Buyer'],
            'line_items' => [['sku' => 'NOPE-V', 'quantity' => 1, 'price' => 10]],
        ]);

        $this->assertSame(1, app(WooSyncService::class)->pullOrders($conn, $fake, $owner->id));
        $order = EcommerceOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('number', 'WOO-9001')->firstOrFail();
        $this->assertSame(200.0, (float) $order->total);
        // Replay imports nothing new; failures retry (no success log exists).
        $this->assertSame(0, app(WooSyncService::class)->pullOrders($conn, $fake, $owner->id));
        $this->assertSame(1, EcommerceOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(2, WoSyncLog::withoutGlobalScopes()->where('entity', 'order')->where('status', 'failed')->count());
    }

    public function test_pull_customers_upserts_by_email(): void
    {
        [$tenant, $owner] = $this->context();
        $conn = $this->connection($tenant, $owner);
        $fake = new FakeWooClient;
        $fake->customers = [1 => ['id' => 1, 'email' => 'Cust@Example.com', 'first_name' => 'C', 'last_name' => 'U'], 2 => ['id' => 2, 'email' => 'no-email', 'first_name' => 'X', 'last_name' => 'Y']];

        $this->assertSame(1, app(WooSyncService::class)->pullCustomers($conn, $fake, $owner->id));
        $this->assertDatabaseHas('contacts', ['tenant_id' => $tenant->id, 'email' => 'cust@example.com']);
    }

    public function test_webhook_signature_and_replay_safety(): void
    {
        [$tenant, $owner] = $this->context();
        [, $variant] = $this->stocked('WH', $tenant);
        $conn = $this->connection($tenant, $owner);
        $payload = json_encode(['id' => 7001, 'billing' => ['email' => 'hook@example.com', 'first_name' => 'H', 'last_name' => 'K'], 'line_items' => [['sku' => 'WH-V', 'quantity' => 1, 'price' => 100]]]);
        $good = base64_encode(hash_hmac('sha256', $payload, 'shhh-secret', true));

        // Bad signature: 401, nothing imported.
        $this->postJson("/api/v1/woocommerce/webhook/{$conn->id}", json_decode($payload, true), ['X-WC-Webhook-Signature' => 'bogus', 'X-WC-Webhook-Topic' => 'order.created'])->assertUnauthorized();
        $this->assertSame(0, EcommerceOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $headers = ['X-WC-Webhook-Signature' => $good, 'X-WC-Webhook-Topic' => 'order.created'];
        // postJson re-encodes; sign stability requires identical bytes, so post raw:
        $this->call('POST', "/api/v1/woocommerce/webhook/{$conn->id}", [], [], [], array_merge($this->transformHeadersToServerVars($headers), ['CONTENT_TYPE' => 'application/json']), $payload)->assertOk();
        $this->assertSame(1, EcommerceOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        // Replay: accepted but imports nothing new.
        $this->call('POST', "/api/v1/woocommerce/webhook/{$conn->id}", [], [], [], array_merge($this->transformHeadersToServerVars($headers), ['CONTENT_TYPE' => 'application/json']), $payload)->assertOk()->assertJson(['imported' => false]);
        $this->assertSame(1, EcommerceOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko Woo A');
        [$tenantB, $ownerB] = $this->context('Toko Woo B');
        $connA = $this->connection($tenantA, null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, WoConnection::query()->count());
        $this->actingAs($ownerB)->get('/woo')->assertOk()->assertDontSee('Toko Live');
        $this->actingAs($ownerB)->post("/woo/connections/{$connA->id}/sync", ['job' => 'orders'])->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/woo')->assertForbidden();
        $member->givePermissionTo('woocommerce.view');
        $this->actingAs($member)->get('/woo')->assertOk();
        $this->actingAs($member)->post('/woo/connections', ['name' => 'X'])->assertForbidden();
    }

    public function test_workspace_connection_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/woo/connections', [
            'name' => 'Web Store', 'store_url' => 'https://web.example',
            'consumer_key' => 'ck_web', 'consumer_secret' => 'cs_web',
        ])->assertRedirect();
        $conn = WoConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertStringNotContainsString('cs_web', $conn->getAttributes()['consumer_secret']);
        $this->assertSame('web.example', parse_url($conn->store_url, PHP_URL_HOST));
        $this->actingAs($owner)->get('/woo')->assertOk()->assertSeeText('Web Store');
        // Sync secrets never appear in the sync log.
        $this->assertSame(0, WoSyncLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('message', 'like', '%cs_web%')->count());
    }
}
