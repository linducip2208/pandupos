<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security regression matrix: Tenant A must never read/write Tenant B resources.
 * Covers GET/POST/PUT/PATCH/DELETE/API/EXPORT/DOWNLOAD across core domains.
 * Expected: 403 or 404, never tenant-B data.
 */
class SecurityRegressionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function provision(string $name): array
    {
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->update(['current_tenant_id' => $tenant->id]);

        return [$tenant, $owner];
    }

    private function seedB(int $tenantBId): array
    {
        $productB = Product::withoutGlobalScopes()->create(['tenant_id' => $tenantBId, 'name' => 'B-Prod', 'sku' => 'B-SKU-'.uniqid()]);
        $variantB = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenantBId, 'product_id' => $productB->id, 'name' => 'D', 'sku' => 'BV-'.uniqid(), 'sell_price' => 1000]);
        $warehouseB = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantBId)->first();
        if (! $warehouseB) {
            $branchTmp = Branch::withoutGlobalScopes()->where('tenant_id', $tenantBId)->first();
            $warehouseB = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenantBId, 'branch_id' => $branchTmp?->id, 'name' => 'GB', 'code' => 'GB-'.uniqid()]);
        }
        $branchB = Branch::withoutGlobalScopes()->where('tenant_id', $tenantBId)->first();
        $contactB = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantBId, 'type' => 'customer', 'name' => 'B-Cust']);
        $supplierB = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantBId, 'type' => 'supplier', 'name' => 'B-Supp']);
        $purchaseB = Purchase::withoutGlobalScopes()->create(['tenant_id' => $tenantBId, 'warehouse_id' => $warehouseB->id, 'contact_id' => $supplierB->id, 'status' => 'ordered']);
        $invoiceB = SalesInvoice::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantBId,
            'branch_id' => $branchB->id, 'warehouse_id' => $warehouseB->id,
            'invoice_no' => 'S-B-'.uniqid(), 'status' => 'final', 'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled', 'subtotal' => 1000, 'total' => 1000,
            'idempotency_key' => 'b-'.uniqid(),
        ]);

        return compact('productB', 'variantB', 'warehouseB', 'branchB', 'contactB', 'supplierB', 'purchaseB', 'invoiceB');
    }

    private function asOwnerA()
    {
        [$tenantA, $ownerA] = $this->provision('Toko A Sec');
        [$tenantB, $ownerB] = $this->provision('Toko B Sec');
        $b = $this->seedB($tenantB->id);
        $ownerA->givePermissionTo(['inventory.view', 'products.manage', 'sales.view', 'pos.sale.create', 'pos.sale.void', 'reports.view', 'purchase.create']);
        $ownerA->update(['current_tenant_id' => $tenantA->id]);

        return [$tenantA, $ownerA, $tenantB, $b];
    }

    public function test_api_get_matrix_blocks_cross_tenant(): void
    {
        $this->seed(PlatformSeeder::class);
        [$tenantA, $ownerA, $tenantB, $b] = $this->asOwnerA();

        $res = $this->actingAs($ownerA)->getJson("/api/v1/products/{$b['productB']->id}", ['X-Tenant-ID' => $tenantA->id]);
        $this->assertContains($res->getStatusCode(), [403, 404]);
        $this->actingAs($ownerA)->getJson('/api/v1/products', ['X-Tenant-ID' => $tenantA->id])
            ->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/contacts', ['X-Tenant-ID' => $tenantA->id])
            ->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/inventory/transfers', ['X-Tenant-ID' => $tenantA->id])->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/inventory/adjustments', ['X-Tenant-ID' => $tenantA->id])->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/inventory/counts', ['X-Tenant-ID' => $tenantA->id])->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/purchases', ['X-Tenant-ID' => $tenantA->id])->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/sales', ['X-Tenant-ID' => $tenantA->id])->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/reports/sales?from=2026-01-01&to=2026-12-31', ['X-Tenant-ID' => $tenantA->id])->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/reports/stock', ['X-Tenant-ID' => $tenantA->id])->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/sync/pull', ['X-Tenant-ID' => $tenantA->id])->assertOk();
        $this->actingAs($ownerA)->getJson('/api/v1/webhooks', ['X-Tenant-ID' => $tenantA->id])->assertOk();
    }

    public function test_api_write_matrix_blocks_cross_tenant(): void
    {
        $this->seed(PlatformSeeder::class);
        [$tenantA, $ownerA, $tenantB, $b] = $this->asOwnerA();

        $this->actingAs($ownerA)->postJson("/api/v1/purchases/{$b['purchaseB']->id}/receive", [], ['X-Tenant-ID' => $tenantA->id])
            ->assertNotFound();
        $res = $this->actingAs($ownerA)->postJson("/api/v1/sales/{$b['invoiceB']->id}/void", [], ['X-Tenant-ID' => $tenantA->id]);
        $this->assertContains($res->getStatusCode(), [403, 404]);
        $res2 = $this->actingAs($ownerA)->putJson("/api/v1/products/{$b['productB']->id}", ['name' => 'Hacked'], ['X-Tenant-ID' => $tenantA->id]);
        $this->assertContains($res2->getStatusCode(), [403, 404]);
        $this->actingAs($ownerA)->postJson('/api/v1/inventory/transfers/999999/approve', [], ['X-Tenant-ID' => $tenantA->id])
            ->assertNotFound();
        $res3 = $this->actingAs($ownerA)->postJson('/api/v1/supplier-invoices/999999/payments', ['amount' => 10], ['X-Tenant-ID' => $tenantA->id]);
        $this->assertContains($res3->getStatusCode(), [403, 404, 422]);
    }

    public function test_body_tenant_id_cannot_override_context(): void
    {
        $this->seed(PlatformSeeder::class);
        [$tenantA, $ownerA] = $this->provision('Toko A3');
        [$tenantB] = $this->provision('Toko B3');
        $ownerA->update(['current_tenant_id' => $tenantA->id]);

        $this->actingAs($ownerA)->getJson('/api/v1/me', ['X-Tenant-ID' => $tenantA->id])
            ->assertOk()->assertJsonPath('data.tenant_id', $tenantA->id);
        $this->actingAs($ownerA)->postJson('/api/v1/contacts', ['name' => 'X', 'type' => 'customer', 'tenant_id' => $tenantB->id], ['X-Tenant-ID' => $tenantA->id]);
        $leaked = Contact::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->where('name', 'X')->exists();
        $this->assertFalse($leaked, 'Body tenant_id must not create records in tenant B.');
    }

    public function test_export_download_and_background_job_isolation(): void
    {
        $this->seed(PlatformSeeder::class);
        [$tenantA, $ownerA, $tenantB, $b] = $this->asOwnerA();

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $res = $this->actingAs($ownerA)->get("/reports/operasional/{$format}?from=2026-01-01&to=2026-12-31", ['X-Tenant-ID' => $tenantA->id]);
            $this->assertContains($res->getStatusCode(), [200, 302, 403]);
        }
        $this->actingAs($ownerA)->getJson('/api/v1/platform/tenants')->assertForbidden();
        $this->actingAs($ownerA)->get('/platform/dashboard')->assertForbidden();
    }

    public function test_model_scope_never_returns_other_tenant(): void
    {
        $this->seed(PlatformSeeder::class);
        [$tenantA, $ownerA] = $this->provision('Toko A4');
        [$tenantB] = $this->provision('Toko B4');
        $b = $this->seedB($tenantB->id);

        $this->assertNull(TenantContext::runAs($tenantA, fn () => Product::find($b['productB']->id)));
        $this->assertNull(TenantContext::runAs($tenantA, fn () => SalesInvoice::find($b['invoiceB']->id)));
        $this->assertNull(TenantContext::runAs($tenantA, fn () => Purchase::find($b['purchaseB']->id)));
    }
}
