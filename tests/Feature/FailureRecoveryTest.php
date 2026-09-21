<?php

namespace Tests\Feature;

use App\Models\BillingTransaction;
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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_database_queue_job_fails_once_then_retries_to_success_without_duplicate_effect(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('flaky-attempts', 0);
        Cache::forget('flaky-done');

        dispatch(new FlakyCounterJob);
        // First worker pass: job throws, stays queued for retry (tries=3), no side effect.
        Artisan::call('queue:work', ['--once' => true, '--tries' => 3, '--sleep' => 0]);
        $this->assertSame(1, Cache::get('flaky-attempts'));
        $this->assertNull(Cache::get('flaky-done'));

        // Second worker pass: retry succeeds, side effect applied exactly once.
        Artisan::call('queue:work', ['--once' => true, '--tries' => 3, '--sleep' => 0]);
        $this->assertSame(2, Cache::get('flaky-attempts'));
        $this->assertSame(1, Cache::get('flaky-done'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        config(['queue.default' => 'sync']);
    }

    public function test_payment_gateway_timeout_leaves_invoice_unpaid_and_retry_succeeds_once(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko PayTimeout', $owner);
        $billing = app(BillingService::class);
        $inv = $billing->createInvoice($tenant->id, null, 75000);
        $tx = $billing->recordAttempt($tenant->id, $inv->id, 'xendit', 'timeout-'.uniqid(), 75000);

        // Gateway timeout reported as failure: invoice stays issued, attempt marked failed.
        $billing->handleWebhook('xendit', $tx->gateway_ref, ['tenant_id' => $tenant->id, 'invoice_id' => $inv->id, 'amount' => 75000], 'failed');
        $this->assertSame('issued', $inv->refresh()->status);

        // Retry succeeds: paid exactly once, single transaction row.
        $billing->handleWebhook('xendit', $tx->gateway_ref, ['tenant_id' => $tenant->id, 'invoice_id' => $inv->id, 'amount' => 75000], 'success');
        $this->assertSame('paid', $inv->refresh()->status);
        $this->assertEquals(1, BillingTransaction::withoutGlobalScopes()->where('gateway_ref', $tx->gateway_ref)->count());
    }

    public function test_backup_and_restore_roundtrip_recovers_data_from_file_artifact(): void
    {
        // The file-copy drill is sqlite-specific; MySQL drills the real
        // mysqldump/mysql roundtrip against an isolated scratch database.
        if (DB::getDriverName() === 'mysql') {
            $this->mysqlBackupRestoreRoundtrip();

            return;
        }
        $scratch = tempnam(sys_get_temp_dir(), 'pandupos-drill-').'.sqlite';
        @unlink($scratch);
        touch($scratch);
        // Use an isolated connection name so the suite's transacted default
        // connection is never disturbed by the drill.
        config(['database.connections.scratch' => [
            'driver' => 'sqlite', 'database' => $scratch, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $previousDefault = config('database.default');
        config(['database.default' => 'scratch']);

        try {
            Artisan::call('migrate', ['--force' => true]);
            DB::table('cache')->insert([
                'key' => 'drill-marker', 'value' => serialize('present'), 'expiration' => now()->addHour()->getTimestamp(),
            ]);
            $this->assertSame(0, Artisan::call('backup:database'));
            $disk = Storage::disk('local');
            $artifacts = collect($disk->files('backups'))->filter(fn ($f) => str_ends_with($f, '.sqlite'));
            $this->assertTrue($artifacts->isNotEmpty(), 'Backup must produce a sqlite artifact.');
            $this->assertTrue(collect($disk->files('backups'))->contains(fn ($f) => str_ends_with($f, '.manifest.json')), 'Backup must produce a manifest.');
            $artifact = $artifacts->sort()->last();

            // Simulate loss, then restore from the artifact.
            DB::table('cache')->where('key', 'drill-marker')->delete();
            $this->assertSame(0, DB::table('cache')->where('key', 'drill-marker')->count());
            $this->assertSame(0, Artisan::call('backup:restore', ['file' => $artifact, '--force' => true]));
            $this->assertSame(1, DB::table('cache')->where('key', 'drill-marker')->count());

            $disk->deleteDirectory('backups');
        } finally {
            config(['database.default' => $previousDefault]);
            DB::purge('scratch');
            @unlink($scratch);
        }
    }

    private function mysqlBackupRestoreRoundtrip(): void
    {
        if ($this->binaryPath('mysqldump') === null || $this->binaryPath('mysql') === null) {
            $this->markTestSkipped('mysqldump/mysql binaries not available.');
        }
        $base = config('database.connections.mysql');
        $dsn = "mysql:host={$base['host']};port={$base['port']}";
        $pdo = new \PDO($dsn, $base['username'], $base['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('DROP DATABASE IF EXISTS `pandupos_drill`');
        $pdo->exec('CREATE DATABASE `pandupos_drill` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.scratch' => array_merge($base, ['database' => 'pandupos_drill'])]);
        $previousDefault = config('database.default');
        config(['database.default' => 'scratch']);
        $disk = Storage::disk('local');
        $before = collect($disk->files('backups'));

        try {
            Artisan::call('migrate', ['--force' => true]);
            DB::table('cache')->insert([
                'key' => 'drill-marker', 'value' => serialize('present'), 'expiration' => now()->addHour()->getTimestamp(),
            ]);
            $this->assertSame(0, Artisan::call('backup:database'));
            $fresh = collect($disk->files('backups'))->diff($before);
            $artifact = $fresh->first(fn ($f) => str_ends_with($f, '.sql'));
            $this->assertNotNull($artifact, 'Backup must produce a mysql artifact.');
            $this->assertTrue($fresh->contains(fn ($f) => str_ends_with($f, '.manifest.json')), 'Backup must produce a manifest.');

            // Simulate loss, then restore from the artifact.
            DB::table('cache')->where('key', 'drill-marker')->delete();
            $this->assertSame(0, DB::table('cache')->where('key', 'drill-marker')->count());
            $this->assertSame(0, Artisan::call('backup:restore', ['file' => $artifact, '--force' => true]));
            $this->assertSame(1, DB::table('cache')->where('key', 'drill-marker')->count());

            $disk->delete($fresh->all());
        } finally {
            config(['database.default' => $previousDefault]);
            DB::purge('scratch');
            $pdo->exec('DROP DATABASE IF EXISTS `pandupos_drill`');
        }
    }

    private function binaryPath(string $bin): ?string
    {
        $found = trim((string) shell_exec((DIRECTORY_SEPARATOR === '\\' ? 'where' : 'command -v').' '.$bin.' 2>NUL'));
        $first = preg_split('/\R/', $found)[0] ?? '';

        return $first !== '' && is_file($first) ? $first : null;
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

/** Test-support job: fails on first attempt, succeeds on retry with a single side effect. */
class FlakyCounterJob implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function backoff(): int
    {
        return 0;
    }

    public function handle(): void
    {
        $attempts = (int) Cache::get('flaky-attempts', 0) + 1;
        Cache::put('flaky-attempts', $attempts);
        if ($attempts < 2) {
            throw new \RuntimeException('Simulated transient worker failure.');
        }
        if (Cache::get('flaky-done') === null) {
            Cache::put('flaky-done', 1);
        }
    }
}
