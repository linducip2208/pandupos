<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API token security matrix: scoped issuance, scope enforcement, expiry,
 * revocation, rate-limit headers and replay-safe checkout.
 */
class ApiTokenScopeTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Token Scope', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'G', 'code' => 'G',
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
        $po = app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 20, 'unit_cost' => 100,
        ]]);
        app(PurchaseService::class)->receive($po->id);

        return [$tenant, $owner, $branch, $warehouse, $customer, $variant];
    }

    private function payload($branch, $warehouse, $customer, $variant): array
    {
        return [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'contact_id' => $customer->id,
            'lines' => [['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 150]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
        ];
    }

    public function test_token_without_scope_is_blocked_but_scoped_token_checks_out(): void
    {
        [$tenant, $owner, $branch, $warehouse, $customer, $variant] = $this->ctx();
        $payload = $this->payload($branch, $warehouse, $customer, $variant);

        $narrow = $owner->createToken('narrow', ['reports:read'], now()->addDay())->plainTextToken;
        $this->withToken($narrow)->postJson('/api/v1/sales', $payload, ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        // Fresh guard resolution per request (production boots a new app per request).
        auth()->forgetGuards();
        $scoped = $owner->createToken('pos', ['sales:write'], now()->addDay())->plainTextToken;
        $this->withToken($scoped)->postJson('/api/v1/sales', $payload, ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'scope-'.uniqid()])->assertCreated();
    }

    public function test_expired_and_revoked_tokens_are_rejected(): void
    {
        [$tenant, $owner, $branch, $warehouse, $customer, $variant] = $this->ctx();
        $payload = $this->payload($branch, $warehouse, $customer, $variant);

        $expired = $owner->createToken('old', ['sales:write'], now()->subMinute())->plainTextToken;
        $this->withToken($expired)->postJson('/api/v1/sales', $payload, ['X-Tenant-ID' => $tenant->id])->assertUnauthorized();

        $token = $owner->createToken('doomed', ['sales:write'], now()->addDay());
        $plain = $token->plainTextToken;
        $this->withToken($plain)->getJson('/api/v1/me', ['X-Tenant-ID' => $tenant->id])->assertOk();
        $owner->tokens()->where('id', $token->accessToken->id)->delete();
        auth()->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/me', ['X-Tenant-ID' => $tenant->id])->assertUnauthorized();
    }

    public function test_token_issuance_ui_validates_scopes_and_revokes(): void
    {
        [$tenant, $owner] = $this->ctx();

        $this->actingAs($owner)->get(route('tokens.index'))->assertOk();
        $this->actingAs($owner)->post(route('tokens.store'), [
            'name' => 'kasir', 'abilities' => ['sales:write'], 'expires_days' => 7,
        ])->assertRedirect()->assertSessionHas('token');
        $this->assertSame(1, $owner->tokens()->count());

        $this->actingAs($owner)->post(route('tokens.store'), [
            'name' => 'evil', 'abilities' => ['platform:admin'], 'expires_days' => 7,
        ])->assertSessionHasErrors('abilities.0');

        $id = $owner->tokens()->first()->id;
        $this->actingAs($owner)->delete(route('tokens.destroy', $id))->assertRedirect();
        $this->assertSame(0, $owner->tokens()->count());
    }

    public function test_api_rate_limit_headers_and_replay_safety(): void
    {
        [$tenant, $owner, $branch, $warehouse, $customer, $variant] = $this->ctx();
        $payload = $this->payload($branch, $warehouse, $customer, $variant);
        $token = $owner->createToken('pos', ['sales:write'], now()->addDay())->plainTextToken;
        $key = 'replay-'.uniqid();

        $first = $this->withToken($token)->postJson('/api/v1/sales', $payload, ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => $key])->assertCreated();
        $first->assertHeader('X-RateLimit-Limit');
        $second = $this->withToken($token)->postJson('/api/v1/sales', $payload, ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => $key])->assertCreated();
        $this->assertSame($first->json('id'), $second->json('id'));
    }
}
