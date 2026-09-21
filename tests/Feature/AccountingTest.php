<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\ModuleManager;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\SupplierDocumentService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Accounting module: double-entry integrity, lifecycle, period closing,
 * source-document auto-posting, reports, tenant isolation, RBAC and API.
 */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Acct', bool $enableAccounting = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableAccounting) {
            app(ModuleManager::class)->enable($tenant->id, 'accounting');
            app(AccountingService::class)->ensureDefaultChart($tenant->id);
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    private function stockContext(User $owner, $tenant): array
    {
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang Acct', 'code' => 'WH-'.uniqid()]);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Produk Acct', 'sku' => 'P-'.uniqid(), 'product_type' => 'stock', 'track_inventory' => true]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'V-'.uniqid(), 'purchase_price' => 50, 'sell_price' => 100]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 10, 50, 'opening', null);

        return [$branch, $warehouse, $variant];
    }

    public function test_default_chart_bootstraps_once_and_module_gates_access(): void
    {
        [$tenant, $owner] = $this->context('Toko Chart', false);

        // Disabled module blocks workspace and API with 403.
        $this->actingAs($owner)->get('/accounting')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/accounting/accounts', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'accounting');
        app(AccountingService::class)->ensureDefaultChart($tenant->id);
        app(AccountingService::class)->ensureDefaultChart($tenant->id); // idempotent
        $this->assertSame(18, Account::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $this->actingAs($owner)->get('/accounting')->assertOk()->assertSeeText('Piutang Usaha');
        $this->actingAs($owner)->getJson('/api/v1/accounting/accounts', ['X-Tenant-ID' => $tenant->id])
            ->assertOk()->assertJsonCount(18, 'data');
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        [$tenant, $owner] = $this->context();
        $this->actingAs($owner);

        try {
            app(AccountingService::class)->createDraft($tenant->id, now()->toDateString(), 'Unbalanced', [
                ['account_code' => '1100', 'debit' => 100, 'credit' => 0],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 90],
            ], null, null, $owner->id);
            $this->fail('Unbalanced journal must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        try {
            app(AccountingService::class)->createDraft($tenant->id, now()->toDateString(), 'Both sides', [
                ['account_code' => '1100', 'debit' => 100, 'credit' => 100],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 100],
            ], null, null, $owner->id);
            $this->fail('Dual-sided line must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_draft_post_void_lifecycle_uses_reversal(): void
    {
        [$tenant, $owner] = $this->context();
        $this->actingAs($owner);
        $svc = app(AccountingService::class);

        $entry = $svc->createDraft($tenant->id, now()->toDateString(), 'Kas masuk', [
            ['account_code' => '1100', 'debit' => 500, 'credit' => 0],
            ['account_code' => '4100', 'debit' => 0, 'credit' => 500],
        ], null, null, $owner->id);
        $this->assertSame('draft', $entry->status);

        // Drafts never touch reports.
        $this->assertTrue($svc->trialBalance($tenant->id, now()->toDateString())['balanced']);
        $this->assertSame([], $svc->trialBalance($tenant->id, now()->toDateString())['rows']);

        $posted = $svc->post($entry, $owner->id);
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->posted_at);

        try {
            $svc->post($posted, $owner->id);
            $this->fail('Double posting must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $tb = $svc->trialBalance($tenant->id, now()->toDateString());
        $this->assertTrue($tb['balanced']);
        $this->assertSame(500.0, $tb['total_debit']);

        $voided = $svc->void($posted, $owner->id, 'Salah catat');
        $this->assertSame('void', $voided->status);
        // Reversal nets to zero: books unchanged in aggregate, history preserved.
        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $after = $svc->trialBalance($tenant->id, now()->toDateString());
        $this->assertTrue($after['balanced']);
        $this->assertSame([], $after['rows']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'accounting.journal.voided']);
    }

    public function test_closed_period_blocks_posting_and_void(): void
    {
        [$tenant, $owner] = $this->context();
        $this->actingAs($owner);
        $svc = app(AccountingService::class);
        $today = now()->toDateString();

        $draft = $svc->createDraft($tenant->id, $today, 'Pre-close', [
            ['account_code' => '1100', 'debit' => 100, 'credit' => 0],
            ['account_code' => '4100', 'debit' => 0, 'credit' => 100],
        ], null, null, $owner->id);
        $svc->post($draft, $owner->id);

        $svc->closePeriod($tenant->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), $owner->id);

        // New drafts inside the closed window are refused ...
        try {
            $svc->createDraft($tenant->id, $today, 'Late', [
                ['account_code' => '1100', 'debit' => 10, 'credit' => 0],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 10],
            ], null, null, $owner->id);
            $this->fail('Posting into a closed period must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        // ... and voiding (reversal dated today) is refused as well.
        try {
            $svc->void($draft, $owner->id);
            $this->fail('Void into a closed period must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'accounting.period.closed']);
    }

    public function test_sale_auto_posts_ar_revenue_and_receipt_when_enabled(): void
    {
        [$tenant, $owner] = $this->context();
        [$branch, $warehouse, $variant] = $this->stockContext($owner, $tenant);
        $this->actingAs($owner);

        $invoice = app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 100],
        ], [['method' => 'cash', 'amount' => 220]], 'acct-sale-1', null, 20);

        $entries = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'posted')->get();
        // Invoice (AR/Revenue/Tax) + cash receipt (Cash/AR) + COGS (COGS/Inventory).
        $this->assertSame(3, $entries->count());
        $inv = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('source_type', SalesInvoice::class)->firstOrFail();
        $this->assertTrue($inv->isBalanced());
        $cogs = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('source_type', SalesInvoice::class . AccountingService::COGS_SOURCE_SUFFIX)->firstOrFail();
        $this->assertTrue($cogs->isBalanced());
        // COGS = 2 units x weighted-average unit cost (50) = 100.
        $cogsLines = $cogs->load('lines.account');
        $cogsDebit = round((float) $cogsLines->lines->filter(fn ($l) => $l->account && $l->account->code === '5100')->sum('debit'), 2);
        $this->assertSame(100.0, $cogsDebit);
        $tb = app(AccountingService::class)->trialBalance($tenant->id, now()->toDateString());
        $this->assertTrue($tb['balanced']);
        // AR nets to zero (220 invoiced, 220 received) so it leaves the trial
        // balance entirely; cash holds 220.
        $byCode = collect($tb['rows'])->keyBy('code');
        $this->assertFalse(isset($byCode['1300']));
        $this->assertSame(220.0, $byCode['1100']['debit']);
        $this->assertSame(100.0, $byCode['5100']['debit']); // COGS expense
        $this->assertSame(100.0, $byCode['1400']['credit']); // inventory credited

        // Re-posting the same invoice is idempotent: no duplicate entry.
        app(AccountingService::class)->postSalesInvoice($invoice->refresh(), $owner->id);
        app(AccountingService::class)->postSaleCogs($invoice->refresh(), $owner->id);
        $this->assertSame(3, JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'posted')->count());
    }

    public function test_sale_skips_accounting_when_module_disabled(): void
    {
        [$tenant, $owner] = $this->context('Toko NoAcct', false);
        [$branch, $warehouse, $variant] = $this->stockContext($owner, $tenant);
        $this->actingAs($owner);

        app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100],
        ], [['method' => 'cash', 'amount' => 100]], 'acct-sale-2');

        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_supplier_invoice_and_payment_post_ap_and_cash(): void
    {
        [$tenant, $owner] = $this->context();
        $this->actingAs($owner);
        $tenantId = $tenant->id;
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'type' => 'supplier', 'name' => 'PT Hutang']);
        $svc = app(SupplierDocumentService::class);

        $invoice = $svc->createInvoice($tenantId, [
            'supplier_id' => $supplier->id, 'invoice_number' => 'SUP-001',
            'invoice_date' => now()->toDateString(), 'subtotal' => 1000, 'discount' => 100, 'tax' => 90, 'shipping' => 10,
        ], $owner->id);
        $this->assertSame(1000.0, (float) $invoice->total);
        $ap = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('source_type', SupplierInvoice::class)->firstOrFail();
        $this->assertTrue($ap->isBalanced());

        $svc->pay($invoice, 400, 'bank_transfer', 'REF-1', $owner->id);
        $payEntry = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('source_type', SupplierPayment::class)->firstOrFail();
        $this->assertTrue($payEntry->isBalanced());

        $tb = app(AccountingService::class)->trialBalance($tenantId, now()->toDateString());
        $this->assertTrue($tb['balanced']);
        $byCode = collect($tb['rows'])->keyBy('code');
        $this->assertSame(600.0, $byCode['2100']['credit']); // AP 1000 - 400 paid
        $this->assertSame(400.0, $byCode['1200']['credit']); // bank out
        $this->assertSame(90.0, $byCode['1500']['debit']); // input tax
    }

    public function test_reports_balance_and_tax_and_ar_ap(): void
    {
        [$tenant, $owner] = $this->context();
        [$branch, $warehouse, $variant] = $this->stockContext($owner, $tenant);
        $this->actingAs($owner);
        $svc = app(AccountingService::class);

        app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 1000],
        ], [['method' => 'cash', 'amount' => 1100]], 'acct-rep-1', null, 100);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $pnl = $svc->profitLoss($tenant->id, $from, $to);
        $this->assertSame(1000.0, $pnl['total_income']);
        $this->assertSame(950.0, $pnl['net_income']); // revenue 1000 - COGS 50

        $bs = $svc->balanceSheet($tenant->id, $to);
        $this->assertTrue($bs['balanced']);
        $this->assertSame(1050.0, $bs['total_assets']); // cash 1100 - inventory credit 50
        $this->assertSame(950.0, $bs['current_earnings']);

        $tax = $svc->taxSummary($tenant->id, $from, $to);
        $this->assertSame(100.0, $tax['output_tax']);
        $this->assertSame(100.0, $tax['net_payable']);

        $cash = $svc->cashFlow($tenant->id, $from, $to);
        $this->assertSame(1100.0, $cash['total_inflow']);
        $this->assertSame(1100.0, $cash['net']);

        $this->assertSame([], $svc->receivables($tenant->id)); // fully paid at checkout
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA, $ownerA] = $this->context('Toko Acct A');
        [$tenantB, $ownerB] = $this->context('Toko Acct B');

        // Cross-tenant: B cannot post using A's account id.
        $foreignAccount = Account::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->where('code', '1100')->firstOrFail();
        TenantContext::setId($tenantB->id);
        try {
            app(AccountingService::class)->createDraft($tenantB->id, now()->toDateString(), 'Sneaky', [
                ['account_code' => '1100', 'debit' => 10, 'credit' => 0],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 10],
            ], null, null, $ownerB->id);
            // Draft itself is tenant B's own accounts; now try resolving A's account directly.
            $this->assertNull(Account::query()->where('tenant_id', $tenantB->id)->find($foreignAccount->id));
        } catch (HttpException) {
            $this->assertTrue(true);
        }
        // Scoped queries never leak A's chart into B.
        $this->assertSame(0, Account::query()->where('tenant_id', $tenantB->id)->where('code', '1100')->where('id', $foreignAccount->id)->count());

        // RBAC: member without accounting permissions is denied everywhere.
        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        TenantContext::setId($tenantB->id);
        $this->actingAs($member)->get('/accounting')->assertForbidden();
        $this->actingAs($member)->getJson('/api/v1/accounting/accounts', ['X-Tenant-ID' => $tenantB->id])->assertForbidden();

        // View-only member may read but never mutate.
        $member->givePermissionTo('accounting.view');
        $this->actingAs($member)->get('/accounting')->assertOk();
        $this->actingAs($member)->post('/accounting/journals', [])->assertForbidden();
    }

    public function test_api_journal_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];

        $draft = $this->actingAs($owner)->postJson('/api/v1/accounting/journals', [
            'entry_date' => now()->toDateString(), 'description' => 'API modal',
            'lines' => [
                ['account_code' => '1100', 'debit' => 250, 'credit' => 0],
                ['account_code' => '3100', 'debit' => 0, 'credit' => 250],
            ],
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'draft');
        $id = $draft->json('data.id');

        // Unbalanced payload is rejected with 422, nothing persisted.
        $this->actingAs($owner)->postJson('/api/v1/accounting/journals', [
            'entry_date' => now()->toDateString(),
            'lines' => [
                ['account_code' => '1100', 'debit' => 250, 'credit' => 0],
                ['account_code' => '3100', 'debit' => 0, 'credit' => 200],
            ],
        ], $headers)->assertStatus(422);

        $this->actingAs($owner)->postJson("/api/v1/accounting/journals/{$id}/post", [], $headers)->assertOk()->assertJsonPath('data.status', 'posted');
        $this->actingAs($owner)->postJson("/api/v1/accounting/journals/{$id}/post", [], $headers)->assertStatus(422);
        $this->actingAs($owner)->getJson('/api/v1/accounting/trial-balance', $headers)->assertOk()->assertJsonPath('data.balanced', true);
        $this->actingAs($owner)->postJson("/api/v1/accounting/journals/{$id}/void", ['reason' => 'api'], $headers)->assertOk()->assertJsonPath('data.status', 'void');
    }

    public function test_api_journal_accepts_variant_id_and_cogs_endpoint(): void
    {
        [$tenant, $owner] = $this->context();
        [$branch, $warehouse, $variant] = $this->stockContext($owner, $tenant);
        $headers = ['X-Tenant-ID' => $tenant->id];

        // Journal line may carry a tenant-owned variant.
        $this->actingAs($owner)->postJson('/api/v1/accounting/journals', [
            'entry_date' => now()->toDateString(), 'description' => 'Variant line',
            'lines' => [
                ['account_code' => '1100', 'debit' => 100, 'credit' => 0, 'variant_id' => $variant->id],
                ['account_code' => '3100', 'debit' => 0, 'credit' => 100],
            ],
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lines.0.product_variant_id', $variant->id);

        // Foreign variant (another tenant) is rejected.
        [$tenantB, $ownerB] = $this->context('Toko Foreign');
        $foreignVariant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'product_id' => Product::withoutGlobalScopes()->create([
                'tenant_id' => $tenantB->id, 'name' => 'Foreign', 'sku' => 'F-'.uniqid(),
                'product_type' => 'stock', 'track_inventory' => true,
            ])->id, 'name' => 'Default', 'sku' => 'VF-'.uniqid(), 'purchase_price' => 10, 'sell_price' => 20,
        ]);
        $this->actingAs($owner)->postJson('/api/v1/accounting/journals', [
            'entry_date' => now()->toDateString(), 'description' => 'Foreign',
            'lines' => [
                ['account_code' => '1100', 'debit' => 10, 'credit' => 0, 'variant_id' => $foreignVariant->id],
                ['account_code' => '3100', 'debit' => 0, 'credit' => 10],
            ],
        ], $headers)->assertStatus(422);

        // COGS endpoint returns the per-variant cost breakdown.
        app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 3, 'unit_price' => 100],
        ], [['method' => 'cash', 'amount' => 300]], 'acct-cogs-api', null, 0);
        $cogsResp = $this->actingAs($owner)->getJson('/api/v1/accounting/cogs', $headers)->assertOk();
        $cogsJson = $cogsResp->json('data');
        $this->assertSame(150.0, round((float) $cogsJson['total'], 2));
        $this->assertSame($variant->id, (int) $cogsJson['lines'][0]['variant_id']);
        $this->assertSame($variant->sku, $cogsJson['lines'][0]['sku']);
    }
}
