<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HrmEmployee;
use App\Models\JournalEntry;
use App\Models\Membership;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\HrmService;
use App\Services\ModuleManager;
use App\Services\PayrollService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Payroll module: structures, snapshotted runs, guarded lifecycle,
 * accounting posting, payslips, isolation, RBAC and API.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Payroll', bool $enablePayroll = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enablePayroll) {
            app(ModuleManager::class)->enable($tenant->id, 'hrm');
            app(ModuleManager::class)->enable($tenant->id, 'payroll');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    private function employee($tenant, $owner, string $name = 'Gaji'): HrmEmployee
    {
        return app(HrmService::class)->registerEmployee($tenant->id, ['name' => $name], $owner->id);
    }

    public function test_module_gates_and_dependency(): void
    {
        [$tenant, $owner] = $this->context('Toko Payroll Gate', false);

        $this->actingAs($owner)->get('/payroll')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/payroll/runs', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        // Payroll declares the hrm dependency: enabling alone must fail first.
        try {
            app(ModuleManager::class)->enable($tenant->id, 'payroll');
            $this->fail('Payroll without HRM must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('hrm', $e->getMessage());
        }
        app(ModuleManager::class)->enable($tenant->id, 'hrm');
        app(ModuleManager::class)->enable($tenant->id, 'payroll');
        $this->actingAs($owner)->get('/payroll')->assertOk()->assertSeeText('Payroll');
    }

    public function test_structure_validation(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(PayrollService::class);
        $employee = $this->employee($tenant, $owner);

        foreach ([
            ['base' => -1, 'allow' => [], 'deduct' => [], 'tax' => 0],
            ['base' => 100, 'allow' => [['name' => '', 'amount' => 1]], 'deduct' => [], 'tax' => 0],
            ['base' => 100, 'allow' => [], 'deduct' => [['name' => 'X', 'amount' => -1]], 'tax' => 0],
            ['base' => 100, 'allow' => [], 'deduct' => [], 'tax' => 1.5],
        ] as $bad) {
            try {
                $svc->setStructure($tenant->id, $employee->id, $bad['base'], $bad['allow'], $bad['deduct'], $bad['tax'], $owner->id);
                $this->fail('Invalid structure must be rejected: '.json_encode($bad));
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_run_snapshots_math_and_locks_on_approve(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(PayrollService::class);
        $a = $this->employee($tenant, $owner, 'A');
        $b = $this->employee($tenant, $owner, 'B');
        $svc->setStructure($tenant->id, $a->id, 5000000, [['name' => 'Transport', 'amount' => 500000]], [['name' => 'BPJS', 'amount' => 200000]], 0.05, $owner->id);
        $svc->setStructure($tenant->id, $b->id, 4000000, [], [], 0, $owner->id);

        // Duplicate period refused.
        $run = $svc->createRun($tenant->id, '2026-08', $owner->id);
        try {
            $svc->createRun($tenant->id, '2026-08', $owner->id);
            $this->fail('Duplicate period must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        try {
            $svc->createRun($tenant->id, 'Agustus', $owner->id);
            $this->fail('Bad period format must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $lines = $run->lines()->orderBy('employee_id')->get();
        // A: gross 5.5jt, tax 275rb, net 5.025jt. B: gross 4jt, net 4jt.
        $this->assertSame(5500000.0, (float) $lines[0]->gross);
        $this->assertSame(275000.0, (float) $lines[0]->tax);
        $this->assertSame(5025000.0, (float) $lines[0]->net);
        $this->assertSame(4000000.0, (float) $lines[1]->net);

        $approved = $svc->approveRun($run, $owner->id);
        $this->assertSame('approved', $approved->status);
        try {
            $svc->approveRun($approved, $owner->id);
            $this->fail('Double approval must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $paid = $svc->markPaid($approved, $owner->id);
        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->paid_at);
    }

    public function test_approval_posts_balanced_payroll_journal_when_accounting_on(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(PayrollService::class);
        app(ModuleManager::class)->enable($tenant->id, 'accounting');
        $employee = $this->employee($tenant, $owner);
        $svc->setStructure($tenant->id, $employee->id, 10000000, [['name' => 'Tunjangan', 'amount' => 1000000]], [['name' => 'BPJS', 'amount' => 500000]], 0.05, $owner->id);

        $run = $svc->createRun($tenant->id, '2026-08', $owner->id);
        $svc->approveRun($run, $owner->id);

        // Gross 11jt, tax 550rb, deductions 500rb, net 9.95jt.
        $entry = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('source_type', PayrollRun::class)->firstOrFail();
        $this->assertSame('posted', $entry->status);
        $this->assertTrue($entry->isBalanced());
        $byCode = $entry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame(11000000.0, (float) $byCode['5210']->debit);
        $this->assertSame(9950000.0, (float) $byCode['2150']->credit);
        $this->assertSame(550000.0, (float) $byCode['2200']->credit);
        $this->assertSame(500000.0, (float) $byCode['2160']->credit);
    }

    public function test_no_accounting_post_when_module_off_but_hrm_on(): void
    {
        [$tenant, $owner] = $this->context('Toko Payroll NoAcct', false);
        app(ModuleManager::class)->enable($tenant->id, 'hrm');
        app(ModuleManager::class)->enable($tenant->id, 'payroll');
        TenantContext::setId($tenant->id);
        $svc = app(PayrollService::class);
        $employee = $this->employee($tenant, $owner);
        $svc->setStructure($tenant->id, $employee->id, 3000000, [], [], 0, $owner->id);

        $run = $svc->createRun($tenant->id, '2026-08', $owner->id);
        $svc->approveRun($run, $owner->id);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_payslip_reflects_snapshot(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(PayrollService::class);
        $employee = $this->employee($tenant, $owner);
        $svc->setStructure($tenant->id, $employee->id, 5000000, [['name' => 'Transport', 'amount' => 500000]], [], 0.05, $owner->id);
        $run = $svc->createRun($tenant->id, '2026-08', $owner->id);

        $slip = $svc->payslip($run, $employee->id);
        $this->assertSame('2026-08', $slip['period']);
        $this->assertSame(5500000.0, $slip['gross']);
        $this->assertSame(275000.0, $slip['tax']);
        $this->assertSame(5225000.0, $slip['net']);
        $this->assertSame('Transport', $slip['allowances'][0]['name']);
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA, $ownerA] = $this->context('Toko Payroll A');
        [$tenantB, $ownerB] = $this->context('Toko Payroll B');
        $this->employee($tenantA, $ownerA, 'Rahasia A');

        TenantContext::setId($tenantB->id);
        $this->actingAs($ownerB)->get('/payroll')->assertOk()->assertDontSee('Rahasia A');

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/payroll')->assertForbidden();
        $member->givePermissionTo('payroll.view');
        $this->actingAs($member)->get('/payroll')->assertOk();
        $this->actingAs($member)->post('/payroll/runs', ['period' => '2026-08'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];
        $employee = $this->employee($tenant, $owner);
        app(PayrollService::class)->setStructure($tenant->id, $employee->id, 6000000, [], [], 0.05, $owner->id);

        $created = $this->actingAs($owner)->postJson('/api/v1/payroll/runs', ['period' => '2026-08'], $headers)->assertCreated();
        $id = $created->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/payroll/runs/{$id}/transition", ['action' => 'approve'], $headers)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($owner)->getJson("/api/v1/payroll/runs/{$id}/payslip/{$employee->id}", $headers)->assertOk()->assertJsonPath('data.net', 5700000);
        $this->actingAs($owner)->postJson("/api/v1/payroll/runs/{$id}/transition", ['action' => 'pay'], $headers)->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();
        $employee = $this->employee($tenant, $owner);

        $this->actingAs($owner)->post('/payroll/structures', ['employee_id' => $employee->id, 'base_salary' => 4500000])->assertRedirect();
        $this->actingAs($owner)->post('/payroll/runs', ['period' => '2026-08'])->assertRedirect();
        $run = PayrollRun::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($owner)->post("/payroll/runs/{$run->id}/approve")->assertRedirect();
        $this->actingAs($owner)->post("/payroll/runs/{$run->id}/paid")->assertRedirect();
        $this->assertSame('paid', $run->refresh()->status);
        $this->actingAs($owner)->get("/payroll/runs/{$run->id}/payslip/{$employee->id}")->assertOk()->assertSeeText('Take-home pay');
        $this->actingAs($owner)->get('/payroll')->assertOk()->assertSeeText('2026-08');
    }
}
