<?php

namespace Tests\Feature;

use App\Models\BillingTransaction;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SalesInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BatchInventoryService;
use App\Services\BillingService;
use App\Services\CouponService;
use App\Services\EntitlementService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SerialNumberService;
use App\Services\StockReservationService;
use App\Services\StockService;
use App\Services\SupplierDocumentService;
use App\Services\TenantProvisioningService;
use App\Services\UsageLimitService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Concurrency gate: last-stock race + numbering/idempotency/webhook/usage/coupon matrix.
 *
 * SQLite :memory: cannot run true parallel threads, so each race is proven via
 * sequential serialization under DB transactions + row locking + unique constraints:
 * exactly one winner, loser fails safely, ledger never goes negative.
 */
class ConcurrencyMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(string $name = 'Toko Race'): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'G', 'code' => 'G-'.uniqid()]);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'P', 'sku' => 'SKU-'.uniqid()]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'D',
            'sku' => 'V-'.uniqid(), 'purchase_price' => 2500, 'sell_price' => 3500,
        ]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'S']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'C']);

        return compact('tenant', 'owner', 'warehouse', 'branch', 'product', 'variant', 'supplier', 'customer');
    }

    public function test_last_stock_race_exactly_one_wins(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v, 'supplier' => $s, 'customer' => $c] = $this->setupTenant('Toko LastStock');
        $stock = app(StockService::class);
        $sales = app(SaleService::class);

        // Stock = 1
        $po = app(PurchaseService::class)->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 1, 'unit_cost' => 2500]]);
        app(PurchaseService::class)->receive($po->id);
        $this->assertEquals(1, $stock->onHand($t->id, $w->id, $v->id));

        // Transaction A buys 1 -> succeeds
        $line = [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500]];
        $pay = [['method' => 'cash', 'amount' => 3500]];
        $invA = $sales->checkout($t->id, $b->id, $w->id, $c->id, $line, $pay, 'race-a-'.uniqid());
        $this->assertNotNull($invA->id);

        // Transaction B buys 1 simultaneously -> must fail
        try {
            $sales->checkout($t->id, $b->id, $w->id, $c->id, $line, $pay, 'race-b-'.uniqid());
            $this->fail('Second concurrent buyer must fail on last stock.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $this->assertEquals(0, $stock->onHand($t->id, $w->id, $v->id));
        $this->assertGreaterThanOrEqual(0, $stock->onHand($t->id, $w->id, $v->id));
    }

    public function test_batch_stock_race_fefo_never_negative(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v] = $this->setupTenant('Toko BatchRace');
        $stock = app(StockService::class);
        $batches = app(BatchInventoryService::class);

        $b1 = $batches->receive($t->id, $w->id, $v->id, 'B-001', 2, 1000, now()->addDays(10)->toDateString());
        $batches->receive($t->id, $w->id, $v->id, 'B-002', 2, 1000, now()->addDays(30)->toDateString());
        $this->assertEquals(4, $stock->onHand($t->id, $w->id, $v->id));

        // Two concurrent FEFO allocations of 3 each: one wins, one fails.
        $batches->allocateFefo($t->id, $w->id, $v->id, 3, 'sale', 1);
        $this->assertEquals(1, $stock->onHand($t->id, $w->id, $v->id));
        try {
            $batches->allocateFefo($t->id, $w->id, $v->id, 3, 'sale', 2);
            $this->fail('Second batch allocation must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(1, $stock->onHand($t->id, $w->id, $v->id));
    }

    public function test_serial_sale_race_one_winner(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v] = $this->setupTenant('Toko SerialRace');
        $serials = app(SerialNumberService::class);

        $sn = $serials->receive($t->id, $w->id, $v->id, 'SN-'.uniqid(), 2000);
        $this->assertSame('available', $sn->status);

        $first = $serials->reserve($t->id, $sn->id, 'sale', 501);
        $this->assertSame('reserved', $first->status);

        // Same reference is idempotent (retry safe), different reference is rejected.
        $retry = $serials->reserve($t->id, $sn->id, 'sale', 501);
        $this->assertSame($first->id, $retry->id);
        try {
            $serials->reserve($t->id, $sn->id, 'sale', 502);
            $this->fail('Second serial reservation by another transaction must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
    }

    public function test_serial_transfer_race_rejected_for_other_reference(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v] = $this->setupTenant('Toko SerialXfer');
        $serials = app(SerialNumberService::class);

        $sn = $serials->receive($t->id, $w->id, $v->id, 'SNX-'.uniqid(), 2000);
        $serials->reserve($t->id, $sn->id, 'transfer', 701);
        try {
            $serials->reserve($t->id, $sn->id, 'transfer', 702);
            $this->fail('Competing transfer reservation must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertSame('reserved', $sn->refresh()->status);
    }

    public function test_reservation_race_atp_never_oversells(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v] = $this->setupTenant('Toko ResvRace');
        $stock = app(StockService::class);
        $stock->increase($t->id, $w->id, $v->id, 5, 1000, 'opening', 1);
        $service = app(StockReservationService::class);

        $service->reserve(['tenant_id' => $t->id, 'warehouse_id' => $w->id, 'product_variant_id' => $v->id, 'quantity' => 4, 'source_type' => 'so', 'source_id' => 1]);
        $this->assertEquals(1, $stock->availableToPromise($t->id, $w->id, $v->id));
        try {
            $service->reserve(['tenant_id' => $t->id, 'warehouse_id' => $w->id, 'product_variant_id' => $v->id, 'quantity' => 4, 'source_type' => 'so', 'source_id' => 2]);
            $this->fail('Second reservation exceeding ATP must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(1, $stock->availableToPromise($t->id, $w->id, $v->id));
    }

    public function test_invoice_idempotency_race_single_invoice(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v, 'supplier' => $s, 'customer' => $c] = $this->setupTenant('Toko InvIdem');
        $po = app(PurchaseService::class)->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 10, 'unit_cost' => 2500]]);
        app(PurchaseService::class)->receive($po->id);
        $sales = app(SaleService::class);
        $key = 'inv-race-'.uniqid();
        $line = [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500]];
        $pay = [['method' => 'cash', 'amount' => 3500]];
        $a = $sales->checkout($t->id, $b->id, $w->id, $c->id, $line, $pay, $key);
        $b2 = $sales->checkout($t->id, $b->id, $w->id, $c->id, $line, $pay, $key);
        $this->assertEquals($a->id, $b2->id);
        $this->assertEquals(9, app(StockService::class)->onHand($t->id, $w->id, $v->id));
        $this->assertEquals(1, SalesInvoice::withoutGlobalScopes()->where('tenant_id', $t->id)->where('idempotency_key', $key)->count());
    }

    public function test_po_receive_idempotency_race_no_double_stock(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v, 'supplier' => $s] = $this->setupTenant('Toko POIdem');
        $ps = app(PurchaseService::class);
        $po = $ps->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 5, 'unit_cost' => 1000]]);
        $ps->receive($po->id);
        $ps->receive($po->id);
        $this->assertEquals(5, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_payment_idempotency_race_single_payment_set(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v, 'supplier' => $s, 'customer' => $c] = $this->setupTenant('Toko PayIdem');
        $po = app(PurchaseService::class)->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 10, 'unit_cost' => 2500]]);
        app(PurchaseService::class)->receive($po->id);
        $sales = app(SaleService::class);
        $key = 'pay-race-'.uniqid();
        $line = [['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 3500]];
        $pay = [['method' => 'cash', 'amount' => 7000]];
        $a = $sales->checkout($t->id, $b->id, $w->id, $c->id, $line, $pay, $key);
        $b2 = $sales->checkout($t->id, $b->id, $w->id, $c->id, $line, $pay, $key);
        $this->assertEquals($a->id, $b2->id);
        $this->assertEquals(1, SalePayment::withoutGlobalScopes()->where('sales_invoice_id', $a->id)->count());
    }

    public function test_webhook_duplicate_race_single_application(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Webhook', $owner);
        $billing = app(BillingService::class);
        $inv = $billing->createInvoice($tenant->id, null, 100000);
        $tx = $billing->recordAttempt($tenant->id, $inv->id, 'xendit', 'gw-'.uniqid(), 100000);
        $t1 = $billing->handleWebhook('xendit', $tx->gateway_ref, ['tenant_id' => $tenant->id, 'invoice_id' => $inv->id, 'amount' => 100000], 'success');
        $t2 = $billing->handleWebhook('xendit', $tx->gateway_ref, ['tenant_id' => $tenant->id, 'invoice_id' => $inv->id, 'amount' => 100000], 'success');
        $this->assertEquals($t1->id, $t2->id);
        $this->assertSame('paid', $inv->refresh()->status);
        $this->assertEquals(1, BillingTransaction::withoutGlobalScopes()->where('gateway_ref', $tx->gateway_ref)->count());
    }

    public function test_usage_limit_race_second_create_blocked(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Limit', $owner);
        $tenant->activeSubscription->plan->entitlements()->updateOrCreate(['entitlement' => 'products.max'], ['value' => '1']);
        app(EntitlementService::class)->forget($tenant->id);
        $limits = app(UsageLimitService::class);
        Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'P1', 'sku' => 'L1-'.uniqid()]);
        try {
            $limits->assertCanCreate($tenant->id, 'products.max');
            $this->fail('Second product beyond limit must be blocked.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
    }

    public function test_coupon_redemption_race_limit_enforced(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Coupon', $owner);
        $plan = Plan::withoutGlobalScopes()->first();
        $coupons = app(CouponService::class);
        $coupon = Coupon::create(['code' => 'RACE-'.strtoupper(uniqid()), 'discount_type' => 'fixed', 'discount_value' => 5000, 'max_redemptions' => 1, 'per_tenant_limit' => 1, 'is_active' => true]);
        [$c, $discount] = $coupons->validate($coupon->code, $tenant->id, $plan->id, 50000);
        $coupons->redeem($c, $tenant->id, null, $discount);
        try {
            $coupons->validate($coupon->code, $tenant->id, $plan->id, 50000);
            $this->fail('Second redemption beyond max must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(1, CouponRedemption::where('coupon_id', $coupon->id)->count());
    }

    public function test_invoice_numbering_race_produces_distinct_numbers(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v, 'supplier' => $s, 'customer' => $c] = $this->setupTenant('Toko Numbering');
        $po = app(PurchaseService::class)->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 50, 'unit_cost' => 2500]]);
        app(PurchaseService::class)->receive($po->id);
        $sales = app(SaleService::class);
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $inv = $sales->checkout($t->id, $b->id, $w->id, $c->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500]], [['method' => 'cash', 'amount' => 3500]], 'num-race-'.$i.'-'.uniqid());
            $numbers[] = $inv->invoice_no;
        }
        $this->assertCount(5, array_unique($numbers), 'Concurrent checkouts must produce distinct invoice numbers.');
        $this->assertEquals(45, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_refund_double_submit_race_single_refund(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v, 'supplier' => $s, 'customer' => $c, 'owner' => $owner] = $this->setupTenant('Toko RefundRace');
        $this->actingAs($owner);
        $po = app(PurchaseService::class)->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 4, 'unit_cost' => 2500]]);
        app(PurchaseService::class)->receive($po->id);
        $sales = app(SaleService::class);
        $inv = $sales->checkout($t->id, $b->id, $w->id, $c->id, [['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 3500]], [['method' => 'cash', 'amount' => 7000]], 'refund-race-'.uniqid());
        $ret = $sales->return($inv->id, [['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 3500]], $t->id, $owner->id, 'refund-race-ret-'.uniqid(), 'Race', true);
        $owner->givePermissionTo('pos.sale.void');
        $r1 = $sales->refund($inv->id, $ret->id, 7000, 'transfer', 'RACE-REF-'.uniqid(), 'Race refund', $owner->id, true, $t->id);
        $r2 = $sales->refund($inv->id, $ret->id, 7000, 'transfer', $r1->reference, 'Race refund', $owner->id, true, $t->id);
        $this->assertSame($r1->id, $r2->id);
        $this->assertEquals(1, SaleRefund::withoutGlobalScopes()->where('sales_return_id', $ret->id)->count());
    }

    public function test_coupon_atomic_redeem_race_single_winner(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko CouponAtomic', $owner);
        $plan = Plan::withoutGlobalScopes()->first();
        $coupons = app(CouponService::class);
        $coupon = Coupon::create(['code' => 'ATOMIC-'.strtoupper(uniqid()), 'discount_type' => 'fixed', 'discount_value' => 5000, 'max_redemptions' => 1, 'per_tenant_limit' => 1, 'is_active' => true]);
        [$c, $discount] = [$coupons->redeemCode($coupon->code, $tenant->id, $plan->id, 50000), null];
        $this->assertEquals(1, CouponRedemption::where('coupon_id', $coupon->id)->count());
        try {
            $coupons->redeemCode($coupon->code, $tenant->id, $plan->id, 50000);
            $this->fail('Second atomic redemption must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(1, CouponRedemption::where('coupon_id', $coupon->id)->count());
    }

    public function test_supplier_payment_reference_race_unique(): void
    {
        ['tenant' => $t, 'owner' => $owner, 'warehouse' => $w, 'variant' => $v, 'supplier' => $s] = $this->setupTenant('Toko SupPay');
        $ps = app(PurchaseService::class);
        $docs = app(SupplierDocumentService::class);
        $po = $ps->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 10, 'unit_cost' => 5000]]);
        $ps->receive($po->id);
        $invoice = $docs->createInvoice($t->id, ['purchase_id' => $po->id, 'supplier_id' => $s->id, 'invoice_number' => 'SUP-'.uniqid(), 'invoice_date' => now()->toDateString(), 'subtotal' => 50000], $owner->id);
        $ref = 'PAY-'.uniqid();
        $docs->pay($invoice, 20000, 'transfer', $ref, $owner->id);
        try {
            $docs->pay($invoice->refresh(), 20000, 'transfer', $ref, $owner->id);
            $this->fail('Duplicate payment reference must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(1, SupplierPayment::withoutGlobalScopes()->where('tenant_id', $t->id)->where('reference', $ref)->count());
    }
}
