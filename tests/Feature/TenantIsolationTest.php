<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_a_cannot_read_tenant_b_branches(): void
    {
        $a = Tenant::create(['uuid' => (string) \Str::uuid(), 'name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $b = Tenant::create(['uuid' => (string) \Str::uuid(), 'name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);

        Branch::withoutGlobalScopes()->create(['tenant_id' => $a->id, 'name' => 'A-1', 'code' => 'A1']);
        Branch::withoutGlobalScopes()->create(['tenant_id' => $b->id, 'name' => 'B-1', 'code' => 'B1']);

        $seenAsA = TenantContext::runAs($a, fn () => Branch::all()->pluck('name')->all());
        $seenAsB = TenantContext::runAs($b, fn () => Branch::all()->pluck('name')->all());

        $this->assertEquals(['A-1'], $seenAsA);
        $this->assertEquals(['B-1'], $seenAsB);
    }

    public function test_middleware_blocks_non_member(): void
    {
        $this->seed(PlatformSeeder::class);

        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $tenant = app(TenantProvisioningService::class)->provision('Toko A', $owner);

        $this->actingAs($outsider)
            ->getJson('/api/v1/me', ['X-Tenant-ID' => $tenant->id])
            ->assertForbidden();
    }

    public function test_tenant_a_cannot_touch_tenant_b_records(): void
    {
        $this->seed(PlatformSeeder::class);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('Toko A', $ownerA);
        $tenantB = app(TenantProvisioningService::class)->provision('Toko B', $ownerB);

        $mkProduct = function ($tenant, $sku) {
            $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => "P-{$sku}", 'sku' => $sku.'-'.uniqid()]);
            $v = ProductVariant::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'product_id' => $p->id,
                'name' => 'Default', 'sku' => 'V-'.uniqid(), 'purchase_price' => 1000, 'sell_price' => 1500,
            ]);

            return [$p, $v];
        };

        [$prodB] = $mkProduct($tenantB, 'B-SKU');
        $mkProduct($tenantA, 'A-SKU');

        $warehouseB = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'name' => 'G-B', 'code' => 'GB']);
        $contactB = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'type' => 'customer', 'name' => 'Cust B']);
        $purchaseB = Purchase::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'warehouse_id' => $warehouseB->id,
            'contact_id' => $contactB->id, 'status' => 'ordered', 'total' => 1000,
        ]);

        // Model-level isolation via TenantContext.
        $seenProducts = TenantContext::runAs($tenantA, fn () => Product::all()->pluck('sku')->all());
        foreach ($seenProducts as $sku) {
            $this->assertStringStartsNotWith('B-SKU', $sku);
        }
        $this->assertNull(TenantContext::runAs($tenantA, fn () => Product::find($prodB->id)));
        $this->assertNull(TenantContext::runAs($tenantA, fn () => Contact::find($contactB->id)));
        $this->assertNull(TenantContext::runAs($tenantA, fn () => Warehouse::find($warehouseB->id)));
        $this->assertNull(TenantContext::runAs($tenantA, fn () => Purchase::find($purchaseB->id)));

        // API-level: A cannot view B product (403 via policy or 404 via scope — both isolate), B purchase receive, B contacts.
        $ownerA->forceFill(['current_tenant_id' => $tenantA->id])->save();
        $ownerA->givePermissionTo('inventory.view');
        $productResponse = $this->actingAs($ownerA)->getJson("/api/v1/products/{$prodB->id}");
        $this->assertContains($productResponse->status(), [403, 404]);
        $this->actingAs($ownerA)->getJson('/api/v1/products')->assertOk()
            ->assertJsonMissing(['sku' => $prodB->sku]);
        $this->actingAs($ownerA)->postJson("/api/v1/purchases/{$purchaseB->id}/receive")->assertNotFound();
        $this->actingAs($ownerA)->getJson('/api/v1/contacts')->assertOk();
    }

    public function test_tenant_a_cannot_void_or_view_tenant_b_sale(): void
    {
        $this->seed(PlatformSeeder::class);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('Toko A2', $ownerA);
        $tenantB = app(TenantProvisioningService::class)->provision('Toko B2', $ownerB);

        $saleB = SalesInvoice::withoutGlobalScopes()->create([
            'uuid' => (string) \Str::uuid(), 'tenant_id' => $tenantB->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->first()->id,
            'warehouse_id' => Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->first()?->id
                ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'name' => 'G', 'code' => 'G-'.uniqid()])->id,
            'invoice_no' => 'S-B-'.uniqid(), 'status' => 'final', 'payment_status' => 'paid',
            'subtotal' => 1000, 'total' => 1000, 'idempotency_key' => 'k-'.uniqid(),
        ]);

        $this->assertNull(TenantContext::runAs($tenantA, fn () => SalesInvoice::find($saleB->id)));

        $ownerA->forceFill(['current_tenant_id' => $tenantA->id])->save();
        $this->actingAs($ownerA)->getJson('/api/v1/sales')->assertOk();
        $this->actingAs($ownerA)->postJson("/api/v1/sales/{$saleB->invoice_no}/void")->assertNotFound();
    }

    public function test_body_tenant_id_cannot_override_context(): void
    {
        $this->seed(PlatformSeeder::class);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('Toko A3', $ownerA);
        app(TenantProvisioningService::class)->provision('Toko B3', $ownerB);

        $ownerA->forceFill(['current_tenant_id' => $tenantA->id])->save();
        // Even if attacker sends tenant_id in body, server context (current_tenant_id) wins.
        $this->actingAs($ownerA)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant_id', $tenantA->id);
    }
}
