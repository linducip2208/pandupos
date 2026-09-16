<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EntitlementService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Services\UsageLimitService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BusinessFlowTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Test', $owner);
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Gudang', 'code' => 'G1']);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Indomie', 'sku' => 'IND-'.uniqid()]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'name' => 'Default', 'sku' => 'V-'.uniqid(), 'purchase_price' => 2500, 'sell_price' => 3500,
        ]);

        return compact('tenant', 'owner', 'warehouse', 'branch', 'product', 'variant');
    }

    public function test_purchase_receipt_increases_stock_and_sale_decreases(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v] = $this->setupTenant();

        $supplier = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $t->id, 'type' => 'supplier', 'name' => 'Supplier A',
        ]);

        /** @var PurchaseService $ps */
        $ps = app(PurchaseService::class);
        $purchase = $ps->createDraft($t->id, $w->id, $supplier->id, [
            ['product_variant_id' => $v->id, 'quantity' => 100, 'unit_cost' => 2500],
        ]);
        // Draft/ordered must NOT increase stock yet.
        $this->assertEquals(0, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        $ps->receive($purchase->id);
        $this->assertEquals(100, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        $customer = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $t->id, 'type' => 'customer', 'name' => 'Budi',
        ]);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $t->id)->first();

        /** @var SaleService $ss */
        $ss = app(SaleService::class);
        $ss->checkout($t->id, $branch->id, $w->id, $customer->id, [
            ['variant_id' => $v->id, 'quantity' => 3, 'unit_price' => 3500],
        ], [['method' => 'cash', 'amount' => 10500]], 'key-'.uniqid());

        $this->assertEquals(97, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_return_restores_stock(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v, 'branch' => $b] = $this->setupTenant();

        app(StockService::class)->increase($t->id, $w->id, $v->id, 50, 2500, 'opening', null);

        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'type' => 'customer', 'name' => 'C']);
        $inv = app(SaleService::class)->checkout($t->id, $b->id, $w->id, $customer->id, [
            ['variant_id' => $v->id, 'quantity' => 10, 'unit_price' => 3500],
        ], [['method' => 'cash', 'amount' => 35000]], 'k-'.uniqid());

        $this->assertEquals(40, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        app(SaleService::class)->return($inv->id, [['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 3500]]);
        $this->assertEquals(42, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_split_payment_must_balance_and_duplicate_key_idempotent(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v, 'branch' => $b] = $this->setupTenant();
        app(StockService::class)->increase($t->id, $w->id, $v->id, 50, 2500, 'opening', null);

        // Unbalanced split must fail.
        try {
            app(SaleService::class)->checkout($t->id, $b->id, $w->id, null, [
                ['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 3500],
            ], [['method' => 'cash', 'amount' => 1000]], 'bad-'.uniqid());
            $this->fail('Expected 422 for unbalanced split.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        $key = 'idem-'.uniqid();
        $first = app(SaleService::class)->checkout($t->id, $b->id, $w->id, null, [
            ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500],
        ], [
            ['method' => 'cash', 'amount' => 2000],
            ['method' => 'qris', 'amount' => 1500],
        ], $key);

        $second = app(SaleService::class)->checkout($t->id, $b->id, $w->id, null, [
            ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500],
        ], [
            ['method' => 'cash', 'amount' => 2000],
            ['method' => 'qris', 'amount' => 1500],
        ], $key);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, SalesInvoice::withoutGlobalScopes()->where('idempotency_key', $key)->count());
    }

    public function test_stock_transfer(): void
    {
        ['tenant' => $t, 'variant' => $v] = $this->setupTenant();
        $w1 = Warehouse::withoutGlobalScopes()->where('tenant_id', $t->id)->first();
        $w2 = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'name' => 'W2', 'code' => 'W2-'.uniqid()]);

        app(StockService::class)->increase($t->id, $w1->id, $v->id, 20, 2500, 'opening', null);
        app(StockService::class)->transfer($t->id, $w1->id, $w2->id, $v->id, 5, 1);

        $this->assertEquals(15, app(StockService::class)->onHand($t->id, $w1->id, $v->id));
        $this->assertEquals(5, app(StockService::class)->onHand($t->id, $w2->id, $v->id));
    }

    public function test_unauthorized_void_blocked(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v, 'branch' => $b] = $this->setupTenant();
        app(StockService::class)->increase($t->id, $w->id, $v->id, 10, 2500, 'opening', null);
        $inv = app(SaleService::class)->checkout($t->id, $b->id, $w->id, null, [
            ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500],
        ], [['method' => 'cash', 'amount' => 3500]], 'v-'.uniqid());

        try {
            app(SaleService::class)->void($inv->id, false);
            $this->fail('Expected 403.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_usage_limit_enforced(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Limit', $owner);

        // Starter: products.max = 1000. Shrink to 1 for test.
        $plan = Plan::where('slug', 'starter')->first();
        $plan->entitlements()->updateOrCreate(['entitlement' => 'products.max'], ['value' => '1']);
        app(EntitlementService::class)->forget($tenant->id);

        // Re-point subscription to starter to be sure.
        Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['plan_id' => $plan->id]);
        app(EntitlementService::class)->forget($tenant->id);

        Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'P1', 'sku' => 'S1-'.uniqid()]);

        try {
            app(UsageLimitService::class)->assertCanCreate($tenant->id, 'products.max');
            $this->fail('Expected 403 usage limit.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }
}
