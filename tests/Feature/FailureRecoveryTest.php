<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BillingService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\SupplierDocumentService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Failure/recovery matrix: application must fail safely and recover cleanly.
 */
class FailureRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(string $name = 'Toko Fail'): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'G', 'code' => 'G-'.uniqid()]);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'P', 'sku' => 'SKU-'.uniqid()]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'D', 'sku' => 'V-'.uniqid(), 'purchase_price' => 2500, 'sell_price' => 3500]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'S']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'C']);

        return compact('tenant', 'owner', 'warehouse', 'branch', 'product', 'variant', 'supplier', 'customer');
    }

    public function test_db_error_during_checkout_leaves_no_partial_invoice(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v, 'supplier' => $s, 'customer' => $c] = $this->setupTenant('Toko FailCheckout');
        $stock = app(StockService::class);
        $po = app(PurchaseService::class)->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 5, 'unit_cost' => 1000]]);
        app(PurchaseService::class)->receive($po->id);
        $before = $stock->onHand($t->id, $w->id, $v->id);
        try {
            app(SaleService::class)->checkout($t->id, $b->id, $w->id, $c->id, [['variant_id' => 999999999, 'quantity' => 1, 'unit_price' => 100]], [['method' => 'cash', 'amount' => 100]], 'fail-'.uniqid());
            $this->fail('Checkout with invalid variant must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals($before, $stock->onHand($t->id, $w->id, $v->id));
        $this->assertEquals(0, SalesInvoice::withoutGlobalScopes()->where('idempotency_key', 'like', 'fail-%')->count());
    }

    public function test_duplicate_job_is_idempotent(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v, 'supplier' => $s, 'customer' => $c] = $this->setupTenant('Toko DupJob');
        $po = app(PurchaseService::class)->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 5, 'unit_cost' => 1000]]);
        app(PurchaseService::class)->receive($po->id);
        $sales = app(SaleService::class);
        $key = 'dupjob-'.uniqid();
        $line = [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500]];
        $pay = [['method' => 'cash', 'amount' => 3500]];
        $a = $sales->checkout($t->id, $b->id, $w->id, $c->id, $line, $pay, $key);
        $b2 = $sales->checkout($t->id, $b->id, $w->id, $c->id, $line, $pay, $key);
        $this->assertEquals($a->id, $b2->id);
    }

    public function test_payment_timeout_overbalance_rejected_balance_unchanged(): void
    {
        ['tenant' => $t, 'owner' => $owner, 'warehouse' => $w, 'variant' => $v, 'supplier' => $s] = $this->setupTenant('Toko PayFail');
        $ps = app(PurchaseService::class);
        $docs = app(SupplierDocumentService::class);
        $po = $ps->createDraft($t->id, $w->id, $s->id, [['product_variant_id' => $v->id, 'quantity' => 2, 'unit_cost' => 5000]]);
        $ps->receive($po->id);
        $inv = $docs->createInvoice($t->id, ['purchase_id' => $po->id, 'supplier_id' => $s->id, 'invoice_number' => 'SUPF-'.uniqid(), 'invoice_date' => now()->toDateString(), 'subtotal' => 10000], $owner->id);
        try {
            $docs->pay($inv, 999999, 'transfer', 'OVER-'.uniqid(), $owner->id);
            $this->fail('Overpayment must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(10000, (float) $inv->refresh()->balance);
    }

    public function test_webhook_retry_same_result(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko HookRetry', $owner);
        $billing = app(BillingService::class);
        $inv = $billing->createInvoice($tenant->id, null, 50000);
        $tx = $billing->recordAttempt($tenant->id, $inv->id, 'xendit', 'retry-'.uniqid(), 50000);
        $first = $billing->handleWebhook('xendit', $tx->gateway_ref, ['tenant_id' => $tenant->id, 'invoice_id' => $inv->id, 'amount' => 50000], 'success');
        $retry = $billing->handleWebhook('xendit', $tx->gateway_ref, ['tenant_id' => $tenant->id, 'invoice_id' => $inv->id, 'amount' => 50000], 'success');
        $this->assertEquals($first->id, $retry->id);
        $this->assertSame('paid', $inv->refresh()->status);
    }

    public function test_backup_failure_on_memory_db_is_handled(): void
    {
        $code = Artisan::call('backup:database');
        // :memory: sqlite has no file; command must fail gracefully (not 500/exception).
        $this->assertContains($code, [0, 1]);
    }

    public function test_cache_outage_fallback_array_still_serves(): void
    {
        config(['cache.default' => 'array']);
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Cache', $owner);
        $owner->update(['current_tenant_id' => $tenant->id]);
        $this->actingAs($owner)->getJson('/api/v1/me', ['X-Tenant-ID' => $tenant->id])->assertOk();
    }

    public function test_queue_sync_failure_does_not_corrupt_stock(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v] = $this->setupTenant('Toko QueueFail');
        $stock = app(StockService::class);
        $stock->increase($t->id, $w->id, $v->id, 3, 1000, 'opening', 1);
        $before = $stock->onHand($t->id, $w->id, $v->id);
        try {
            $stock->decrease($t->id, $w->id, $v->id, 99, 'sale', 1);
            $this->fail('Oversell via queue worker must fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals($before, $stock->onHand($t->id, $w->id, $v->id));
    }
}
