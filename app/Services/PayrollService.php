<?php

namespace App\Services;

use App\Models\HrmAttendance;
use App\Models\HrmEmployee;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\PayrollStructure;
use Illuminate\Support\Facades\DB;

/**
 * Payroll: salary structures, monthly runs snapshotting gross/tax/net with
 * HRM attendance context, guarded approve/pay lifecycle, and automatic
 * posting to the accounting ledger when that module is enabled.
 */
final class PayrollService
{
    public function __construct(private AuditService $audit) {}

    /**
     * @param  array<int, array{name:string,amount:float}>  $allowances
     * @param  array<int, array{name:string,amount:float}>  $deductions
     */
    public function setStructure(int $tenantId, int $employeeId, float $baseSalary, array $allowances, array $deductions, float $taxRate, ?int $actorId = null): PayrollStructure
    {
        HrmEmployee::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($employeeId);
        $baseSalary = round($baseSalary, 2);
        abort_if($baseSalary < 0, 422, 'Base salary cannot be negative.');
        $taxRate = round($taxRate, 4);
        abort_if($taxRate < 0 || $taxRate > 1, 422, 'Tax rate must be between 0 and 1.');
        $allowances = $this->normalizeItems($allowances, 'allowance');
        $deductions = $this->normalizeItems($deductions, 'deduction');

        $structure = PayrollStructure::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'employee_id' => $employeeId],
            ['base_salary' => $baseSalary, 'allowances' => $allowances, 'deductions' => $deductions, 'income_tax_rate' => $taxRate]
        );
        $this->audit->log($tenantId, $actorId, 'payroll.structure.saved', PayrollStructure::class, $structure->id, null, ['base_salary' => $baseSalary]);

        return $structure;
    }

    public function createRun(int $tenantId, string $period, ?int $actorId = null): PayrollRun
    {
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1, 422, 'Period must be YYYY-MM.');
        abort_if(PayrollRun::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('period', $period)->exists(), 422, 'Payroll run for this period already exists.');

        return DB::transaction(function () use ($tenantId, $period, $actorId) {
            $run = PayrollRun::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'period' => $period, 'status' => PayrollRun::DRAFT]);
            $structures = PayrollStructure::withoutGlobalScopes()->where('tenant_id', $tenantId)->with('employee')->get();
            abort_if($structures->isEmpty(), 422, 'No salary structures defined for this tenant.');
            foreach ($structures as $structure) {
                $this->snapshotLine($run, $structure);
            }
            $this->audit->log($tenantId, $actorId, 'payroll.run.created', PayrollRun::class, $run->id, null, ['period' => $period, 'lines' => $structures->count()]);

            return $run->load('lines');
        });
    }

    public function approveRun(PayrollRun $run, ?int $actorId = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $actorId) {
            $locked = PayrollRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
            abort_if($locked->status !== PayrollRun::DRAFT, 422, 'Only draft runs can be approved.');
            abort_if($locked->lines()->count() === 0, 422, 'Cannot approve an empty run.');
            $before = $locked->toArray();
            $locked->update(['status' => PayrollRun::APPROVED, 'approved_by' => $actorId, 'approved_at' => now()]);
            $this->postToAccounting($locked, $actorId);
            $this->audit->log($locked->tenant_id, $actorId, 'payroll.run.approved', PayrollRun::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh('lines');
        });
    }

    public function markPaid(PayrollRun $run, ?int $actorId = null): PayrollRun
    {
        $locked = PayrollRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
        abort_if($locked->status !== PayrollRun::APPROVED, 422, 'Only approved runs can be marked paid.');
        $before = $locked->toArray();
        $locked->update(['status' => PayrollRun::PAID, 'paid_at' => now()]);
        $this->audit->log($locked->tenant_id, $actorId, 'payroll.run.paid', PayrollRun::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh('lines');
    }

    /** @return array{lines:array,total_gross:float,total_tax:float,total_net:float} */
    public function payslip(PayrollRun $run, int $employeeId): array
    {
        $line = PayrollRunLine::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->firstOrFail();

        return [
            'period' => $run->period, 'status' => $run->status,
            'employee' => $line->employee->only(['id', 'code', 'name']),
            'base_salary' => (float) $line->base_salary,
            'allowances' => $line->breakdown['allowances'] ?? [],
            'allowances_total' => (float) $line->allowances_total,
            'gross' => (float) $line->gross, 'tax' => (float) $line->tax,
            'deductions' => $line->breakdown['deductions'] ?? [],
            'deductions_total' => (float) $line->deductions_total,
            'net' => (float) $line->net, 'present_days' => (int) $line->present_days,
        ];
    }

    private function snapshotLine(PayrollRun $run, PayrollStructure $structure): void
    {
        $allowTotal = round(array_sum(array_column($structure->allowances ?? [], 'amount')), 2);
        $deductTotal = round(array_sum(array_column($structure->deductions ?? [], 'amount')), 2);
        $gross = round((float) $structure->base_salary + $allowTotal, 2);
        $tax = round($gross * (float) $structure->income_tax_rate, 2);
        $net = round($gross - $tax - $deductTotal, 2);
        abort_if($net < 0, 422, 'Net pay cannot be negative.');
        $present = HrmAttendance::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)
            ->where('employee_id', $structure->employee_id)
            ->whereDate('worked_on', '>=', $run->period.'-01')->whereDate('worked_on', '<=', $run->period.'-31')
            ->whereNotNull('check_in')->count();
        $run->lines()->create([
            'tenant_id' => $run->tenant_id, 'employee_id' => $structure->employee_id,
            'base_salary' => $structure->base_salary, 'allowances_total' => $allowTotal,
            'gross' => $gross, 'tax' => $tax, 'deductions_total' => $deductTotal, 'net' => $net,
            'present_days' => $present,
            'breakdown' => ['allowances' => $structure->allowances ?? [], 'deductions' => $structure->deductions ?? []],
        ]);
    }

    /** Post Dr Beban Gaji / Cr Hutang Gaji + Pajak + Potongan when accounting is on. */
    private function postToAccounting(PayrollRun $run, ?int $actorId): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($run->tenant_id, 'accounting')) {
            return;
        }
        $accounting = app(AccountingService::class);
        $accounting->ensureDefaultChart($run->tenant_id);
        $gross = round((float) $run->lines()->sum('gross'), 2);
        $tax = round((float) $run->lines()->sum('tax'), 2);
        $deductions = round((float) $run->lines()->sum('deductions_total'), 2);
        $net = round($gross - $tax - $deductions, 2);
        abort_if($gross <= 0, 422, 'Cannot post an empty payroll.');
        $lines = [['account_code' => '5210', 'debit' => $gross, 'credit' => 0]];
        if ($net > 0) {
            $lines[] = ['account_code' => '2150', 'debit' => 0, 'credit' => $net];
        }
        if ($tax > 0) {
            $lines[] = ['account_code' => '2200', 'debit' => 0, 'credit' => $tax];
        }
        if ($deductions > 0) {
            $lines[] = ['account_code' => '2160', 'debit' => 0, 'credit' => $deductions];
        }
        $entry = $accounting->createDraft($run->tenant_id, now()->toDateString(), 'Gaji periode '.$run->period, $lines, PayrollRun::class, $run->id, $actorId);
        $accounting->post($entry, $actorId);
    }

    /** @return array<int, array{name:string,amount:float}> */
    private function normalizeItems(array $items, string $kind): array
    {
        $out = [];
        foreach (array_values($items) as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $amount = round((float) ($item['amount'] ?? 0), 2);
            abort_if($name === '' || $amount < 0, 422, "Invalid {$kind} item.");
            $out[] = ['name' => $name, 'amount' => $amount];
        }

        return $out;
    }
}
