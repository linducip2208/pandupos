<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cheque;
use App\Models\JournalEntry;
use App\Models\Membership;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\ModuleManager;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cheque module: receipt/issue, deposit batches, clearance, bounce with
 * redeposit, cancellation, reconciliation, isolation, RBAC and API.
 */
class ChequeTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Cek', bool $enableCheque = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableCheque) {
            app(ModuleManager::class)->enable($tenant->id, 'cheque');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    private function receive($tenant, $owner, array $over = []): Cheque
    {
        return app(ChequeService::class)->receive($tenant->id, array_merge([
            'bank_name' => 'BCA', 'cheque_no' => 'CQ-'.uniqid(), 'amount' => 1000000,
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ], $over), $owner?->id);
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko Cek Gate', false);

        $this->actingAs($owner)->get('/cheques')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/cheques', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'cheque');
        $this->actingAs($owner)->get('/cheques')->assertOk()->assertSeeText('Daftar cek');
    }

    public function test_validation_and_duplicate_numbers(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(ChequeService::class);
        $this->receive($tenant, $owner, ['bank_name' => 'BCA', 'cheque_no' => 'DUP-1']);

        foreach ([
            ['bank_name' => 'BCA', 'cheque_no' => 'DUP-1', 'amount' => 100, 'issue_date' => '2026-01-01', 'due_date' => '2026-02-01'],
            ['bank_name' => 'BCA', 'cheque_no' => 'NEG', 'amount' => -5, 'issue_date' => '2026-01-01', 'due_date' => '2026-02-01'],
            ['bank_name' => 'BCA', 'cheque_no' => 'INV', 'amount' => 100, 'issue_date' => '2026-02-01', 'due_date' => '2026-01-01'],
        ] as $bad) {
            try {
                $svc->receive($tenant->id, $bad, $owner->id);
                $this->fail('Invalid cheque must be rejected: '.json_encode($bad));
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        // Same number at a different bank is a different instrument.
        $other = $svc->receive($tenant->id, ['bank_name' => 'Mandiri', 'cheque_no' => 'DUP-1', 'amount' => 100, 'issue_date' => '2026-01-01', 'due_date' => '2026-02-01'], $owner->id);
        $this->assertSame('received', $other->status);
    }

    public function test_deposit_clear_bounce_redeposit_and_cancel_rules(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(ChequeService::class);
        $a = $this->receive($tenant, $owner, ['cheque_no' => 'A-1']);
        $b = $svc->issue($tenant->id, ['bank_name' => 'BCA', 'cheque_no' => 'B-1', 'amount' => 500000, 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString()], $owner->id);
        $this->assertSame('issued', $b->status);

        // Mixed-bank batch refused.
        $c = $this->receive($tenant, $owner, ['bank_name' => 'Mandiri', 'cheque_no' => 'C-1']);
        try {
            $svc->deposit($tenant->id, [$a->id, $c->id], 'BCA', now()->toDateString(), $owner->id);
            $this->fail('Mixed-bank deposit must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $deposit = $svc->deposit($tenant->id, [$a->id, $b->id], 'BCA', now()->toDateString(), $owner->id);
        $this->assertSame('deposited', $a->refresh()->status);
        // Cancel after deposit refused.
        try {
            $svc->cancel($a->refresh(), $owner->id);
            $this->fail('Cancelling a deposited cheque must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->clear($a->refresh(), $owner->id);
        $this->assertSame('cleared', $a->refresh()->status);
        // Bounce requires a reason.
        try {
            $svc->bounce($b->refresh(), '', $owner->id);
            $this->fail('Reason-less bounce must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $svc->bounce($b->refresh(), 'Dana tidak cukup', $owner->id);
        $this->assertSame('bounced', $b->refresh()->status);
        // Deposit settled (nothing left deposited) ...
        $this->assertSame('cleared', $deposit->refresh()->status);
        // ... and the bounced cheque redeposits cleanly.
        $deposit2 = $svc->deposit($tenant->id, [$b->refresh()->id], 'BCA', now()->toDateString(), $owner->id);
        $svc->clear($b->refresh(), $owner->id);
        $this->assertSame('cleared', $deposit2->refresh()->status);
    }

    public function test_clear_posts_to_accounting_when_enabled(): void
    {
        [$tenant, $owner] = $this->context();
        app(ModuleManager::class)->enable($tenant->id, 'accounting');
        $svc = app(ChequeService::class);
        $cheque = $this->receive($tenant, $owner, ['cheque_no' => 'ACCT-1', 'amount' => 2000000]);
        $svc->deposit($tenant->id, [$cheque->id], 'BCA', now()->toDateString(), $owner->id);
        $svc->clear($cheque->refresh(), $owner->id);

        $entry = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('source_type', Cheque::class)->firstOrFail();
        $this->assertSame('posted', $entry->status);
        $this->assertTrue($entry->isBalanced());
        $byCode = $entry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame(2000000.0, (float) $byCode['1200']->debit);
        $this->assertSame(2000000.0, (float) $byCode['1300']->credit);
    }

    public function test_reconciliation_signs_receipts_and_payments(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(ChequeService::class);
        $today = now()->toDateString();
        $r = $this->receive($tenant, $owner, ['cheque_no' => 'R-1', 'amount' => 1000, 'issue_date' => $today, 'due_date' => $today]);
        $p = $svc->issue($tenant->id, ['bank_name' => 'BCA', 'cheque_no' => 'P-1', 'amount' => 400, 'issue_date' => $today, 'due_date' => $today], $owner->id);
        $svc->deposit($tenant->id, [$r->id, $p->id], 'BCA', $today, $owner->id);
        $svc->clear($r->refresh(), $owner->id);
        $svc->bounce($p->refresh(), 'Tutup', $owner->id);

        $recon = $svc->reconcile($tenant->id, now()->startOfMonth()->toDateString(), now()->toDateString());
        $this->assertSame(1000.0, $recon['cleared']);
        $this->assertSame(-400.0, $recon['bounced']);
        $this->assertSame(0.0, $recon['outstanding']);
        $this->assertSame(1000.0, $recon['by_bank']['BCA']['cleared']);
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko Cek A');
        [$tenantB, $ownerB] = $this->context('Toko Cek B');
        $svc = app(ChequeService::class);
        $orderA = $this->receive($tenantA, null, ['cheque_no' => 'RAHASIA-A']);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, Cheque::query()->count());
        $this->actingAs($ownerB)->get('/cheques')->assertOk()->assertDontSee('RAHASIA-A');
        $this->actingAs($ownerB)->post("/cheques/{$orderA->id}/transition", ['action' => 'cancel'])->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/cheques')->assertForbidden();
        $member->givePermissionTo('cheque.view');
        $this->actingAs($member)->get('/cheques')->assertOk();
        $this->actingAs($member)->post('/cheques', ['type' => 'receipt'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];

        $created = $this->actingAs($owner)->postJson('/api/v1/cheques', [
            'type' => 'receipt', 'bank_name' => 'BCA', 'cheque_no' => 'API-1',
            'amount' => 750000, 'issue_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
        ], $headers)->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/cheques/{$id}/transition", ['action' => 'deposit', 'bank_name' => 'BCA', 'deposited_on' => now()->toDateString()], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/cheques/{$id}/transition", ['action' => 'clear'], $headers)->assertOk()->assertJsonPath('data.status', 'cleared');
        $this->actingAs($owner)->getJson('/api/v1/cheques', $headers)->assertOk()->assertJsonPath('reconciliation.cleared', 750000);
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/cheques', [
            'type' => 'receipt', 'bank_name' => 'BCA', 'cheque_no' => 'WEB-1',
            'amount' => 300000, 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
        ])->assertRedirect();
        $cheque = Cheque::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post('/cheques/deposit', ['cheque_ids_raw' => (string) $cheque->id, 'bank_name' => 'BCA', 'deposited_on' => now()->toDateString()])->assertRedirect();
        $this->actingAs($owner)->post("/cheques/{$cheque->id}/transition", ['action' => 'clear'])->assertRedirect();
        $this->assertSame('cleared', $cheque->refresh()->status);
        $this->actingAs($owner)->get('/cheques')->assertOk()->assertSeeText('WEB-1');
    }
}
