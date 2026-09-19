<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EntitlementService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Services\UsageLimitService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UsageLimitTest extends TestCase
{
    use RefreshDatabase;

    private function provisionedTenant(string $name)
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();

        return [$tenant, $owner];
    }

    private function enforce(string $key, int $tenantId): void
    {
        $tenant = Tenant::withoutGlobalScopes()->with('activeSubscription.plan')->find($tenantId);
        $tenant->activeSubscription->plan->entitlements()->updateOrCreate(['entitlement' => $key], ['value' => '0']);
        app(EntitlementService::class)->forget($tenantId);
    }

    public function test_snapshot_has_percent_and_enforcement(): void
    {
        [$tenant, $owner] = $this->provisionedTenant('Toko Usage');
        $usage = app(UsageLimitService::class);

        $snap = $usage->snapshot($tenant->id);
        $this->assertArrayHasKey('users', $snap);
        $this->assertArrayHasKey('customers', $snap);
        $this->assertArrayHasKey('suppliers', $snap);
        $this->assertArrayHasKey('invoices', $snap);
        $this->assertArrayHasKey('current', $snap['users']);
        $this->assertArrayHasKey('limit', $snap['users']);
        $this->assertArrayHasKey('percent', $snap['users']);

        // Starter allows 5 users; force exceed by lowering entitlement directly.
        $this->enforce('users.max', $tenant->id);

        $this->expectException(HttpException::class);
        $usage->assertCanCreate($tenant->id, 'users.max');
    }

    public function test_customers_suppliers_and_both_are_enforced_at_the_contact_write_path(): void
    {
        [$tenant, $owner] = $this->provisionedTenant('Toko Meter');
        $this->actingAs($owner);

        $this->enforce('customers.max', $tenant->id);
        $this->postJson('/api/v1/contacts', ['type' => 'supplier', 'name' => 'A Supplier'], ['X-Tenant-ID' => $tenant->id])
            ->assertCreated();
        $this->assertDatabaseHas('contacts', ['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'A Supplier']);
        $this->postJson('/api/v1/contacts', ['type' => 'customer', 'name' => 'A Customer'], ['X-Tenant-ID' => $tenant->id])
            ->assertForbidden();

        $this->enforce('suppliers.max', $tenant->id);
        $this->postJson('/api/v1/contacts', ['type' => 'supplier', 'name' => 'Another Supplier'], ['X-Tenant-ID' => $tenant->id])
            ->assertForbidden();

        // A "both" contact must clear every meter it consumes.
        $this->postJson('/api/v1/contacts', ['type' => 'both', 'name' => 'Dual'], ['X-Tenant-ID' => $tenant->id])
            ->assertForbidden();
    }

    public function test_invoice_monthly_quota_is_enforced_at_checkout(): void
    {
        [$tenant, $owner] = $this->provisionedTenant('Toko InvMeter');
        $this->actingAs($owner);
        $branch = Branch::where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang Meter', 'code' => 'GDGM-'.substr(uniqid(), -5), 'is_active' => true]);
        $variant = ProductVariant::where('tenant_id', $tenant->id)->first();
        if (! $variant) {
            $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Toko Meter Item', 'sku' => 'INVM-'.uniqid()]);
            $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'V', 'price' => 10000, 'cost' => 5000, 'sku' => null]);
        }
        $this->seed(PlatformSeeder::class);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 5, 5000, 'opening', null);
        app(EntitlementService::class)->forget($tenant->id);
        $svc = app(SaleService::class);
        $svc->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 10000],
        ], [['method' => 'cash', 'amount' => 10000]], 'inv-meter-'.uniqid());

        // Force the monthly cap to 0 and clear the entitlement cache.
        $this->enforce('invoices.monthly', $tenant->id);
        $count = app(UsageLimitService::class)->count($tenant->id, 'invoices.monthly');
        $this->assertGreaterThanOrEqual(1, $count);

        try {
            $svc->checkout($tenant->id, $branch->id, $warehouse->id, null, [
                ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 10000],
            ], [['method' => 'cash', 'amount' => 10000]], 'inv-meter-blocked-'.uniqid());
            $this->fail('Second invoice beyond monthly quota must be blocked.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_contact_limit_race_second_create_blocked(): void
    {
        [$tenant, $owner] = $this->provisionedTenant('Toko MeterRace');
        $owner->refresh();
        Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Slot 1']);
        $this->enforce('customers.max', $tenant->id);

        $this->actingAs($owner)->postJson('/api/v1/contacts', ['type' => 'customer', 'name' => 'Slot 2'], ['X-Tenant-ID' => $tenant->id])
            ->assertForbidden();
    }
}
