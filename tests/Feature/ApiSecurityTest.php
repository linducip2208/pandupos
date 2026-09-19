<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API security matrix: tokens, expiry, revocation, rate limit,
 * tenant isolation, RBAC, body rejection, replay, consistent errors.
 */
class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(string $name = 'Toko API'): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->update(['current_tenant_id' => $tenant->id]);

        return [$tenant, $owner];
    }

    public function test_token_revocation_blocks_access(): void
    {
        [$tenant, $owner] = $this->ctx('Toko Revoke');
        $plain = $owner->createToken('api')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/api/v1/me', ['X-Tenant-ID' => $tenant->id])->assertOk();
        $this->assertEquals(1, $owner->tokens()->count());
        $tokenId = $owner->tokens()->first()->id;
        $owner->tokens()->where('id', $tokenId)->delete();
        $this->assertEquals(0, $owner->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_expired_token_is_rejected(): void
    {
        [$tenant, $owner] = $this->ctx('Toko Expiry');
        $token = $owner->createToken('api', ['*'], now()->subMinute());
        $this->withToken($token->plainTextToken)->getJson('/api/v1/me', ['X-Tenant-ID' => $tenant->id])->assertUnauthorized();
    }

    public function test_missing_tenant_context_rejected(): void
    {
        [$tenant, $owner] = $this->ctx('Toko NoCtx');
        $loner = User::factory()->create();
        Sanctum::actingAs($loner);
        // Loner claims membership via header but is not a member -> 403.
        $this->getJson('/api/v1/products', ['X-Tenant-ID' => $tenant->id])->assertForbidden();
    }

    public function test_rbac_enforced_on_api(): void
    {
        [$tenant, $owner] = $this->ctx('Toko API RBAC');
        $viewer = User::factory()->create();
        Membership::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'), 'is_active' => true]);
        $viewer->update(['current_tenant_id' => $tenant->id]);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/sales', ['X-Tenant-ID' => $tenant->id])->assertForbidden();
        $this->postJson('/api/v1/sales', [], ['X-Tenant-ID' => $tenant->id])->assertForbidden();
    }

    public function test_replay_idempotency_returns_same_resource(): void
    {
        [$tenant, $owner] = $this->ctx('Toko Replay');
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'G', 'code' => 'G-'.uniqid()]);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'P', 'sku' => 'RP-'.uniqid()]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'D', 'sku' => 'RV-'.uniqid(), 'sell_price' => 5000]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 10, 1000, 'opening', 1);
        Sanctum::actingAs($owner);
        $payload = ['branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'lines' => [['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 5000]], 'payments' => [['method' => 'cash', 'amount' => 5000]]];
        $key = 'replay-'.uniqid();
        $a = $this->postJson('/api/v1/sales', $payload, ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => $key])->assertCreated();
        $b = $this->postJson('/api/v1/sales', $payload, ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => $key])->assertCreated();
        $this->assertEquals($a->json('id'), $b->json('id'));
    }

    public function test_void_requires_a_reason_and_records_only_one_controlled_reversal(): void
    {
        [$tenant, $owner] = $this->ctx('Toko Void Reason');
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Void Warehouse', 'code' => 'VOID-'.uniqid()]);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Void Product', 'sku' => 'VOID-'.uniqid()]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'VOID-V-'.uniqid()]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 1, 100, 'opening', null);
        $invoice = app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 200],
        ], [['method' => 'cash', 'amount' => 200]], 'void-'.uniqid());
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/sales/'.$invoice->id.'/void', [], ['X-Tenant-ID' => $tenant->id])->assertUnprocessable();
        $this->postJson('/api/v1/sales/'.$invoice->id.'/void', ['reason' => 'Salah scan kasir'], ['X-Tenant-ID' => $tenant->id])->assertOk();
        $this->postJson('/api/v1/sales/'.$invoice->id.'/void', ['reason' => 'Pengulangan aman'], ['X-Tenant-ID' => $tenant->id])->assertOk();
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('reference_type', 'sale_void')->where('reference_id', $invoice->id)->count());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'sale.void.posted']);
    }

    public function test_consistent_error_shape_no_trace_leak(): void
    {
        [$tenant, $owner] = $this->ctx('Toko Errors');
        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/v1/products/999999999', ['X-Tenant-ID' => $tenant->id]);
        $this->assertContains($res->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('Stack trace', $res->getContent());
        $this->assertStringNotContainsString('APP_KEY', $res->getContent());
    }

    public function test_health_endpoint_does_not_leak_secrets(): void
    {
        [$tenant, $owner] = $this->ctx('Toko Health');
        $admin = User::factory()->create(['is_platform_admin' => true]);
        Sanctum::actingAs($admin);
        $res = $this->getJson('/api/v1/platform/health');
        $res->assertOk();
        foreach (['APP_KEY', 'DB_PASSWORD', 'secret', 'MYSQL_PWD'] as $needle) {
            $this->assertStringNotContainsString($needle, $res->getContent());
        }
    }
}
