<?php

namespace App\Http\Controllers;

use App\Models\HrmEmployee;
use App\Models\PayrollRun;
use App\Services\PayrollService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class PayrollWorkspaceController extends Controller
{
    public function index(PayrollService $payroll)
    {
        $this->authorize('viewAny', PayrollRun::class);
        $runs = PayrollRun::query()->with('lines.employee')->orderByDesc('id')->limit(50)->get();
        $totals = [];
        foreach ($runs as $run) {
            $totals[$run->id] = [
                'gross' => (float) $run->lines->sum('gross'),
                'net' => (float) $run->lines->sum('net'),
            ];
        }

        return view('payroll.index', [
            'runs' => $runs, 'totals' => $totals,
            'employees' => HrmEmployee::query()->where('status', 'active')->orderBy('code')->limit(200)->get(),
        ]);
    }

    public function storeStructure(Request $request, PayrollService $payroll)
    {
        $this->authorize('create', PayrollRun::class);
        $data = $request->validate([
            'employee_id' => 'required|integer', 'base_salary' => 'required|numeric|min:0',
            'income_tax_rate' => 'nullable|numeric|min:0|max:1',
            'allowances' => 'nullable|array', 'allowances.*.name' => 'required_with:allowances|string',
            'allowances.*.amount' => 'required_with:allowances|numeric|min:0',
            'deductions' => 'nullable|array', 'deductions.*.name' => 'required_with:deductions|string',
            'deductions.*.amount' => 'required_with:deductions|numeric|min:0',
        ]);
        $payroll->setStructure(
            TenantContext::idOrFail(), (int) $data['employee_id'], (float) $data['base_salary'],
            array_values($data['allowances'] ?? []), array_values($data['deductions'] ?? []),
            (float) ($data['income_tax_rate'] ?? 0), $request->user()->id
        );

        return back()->with('status', 'Struktur gaji disimpan.');
    }

    public function storeRun(Request $request, PayrollService $payroll)
    {
        $this->authorize('create', PayrollRun::class);
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $run = $payroll->createRun(TenantContext::idOrFail(), $data['period'], $request->user()->id);

        return back()->with('status', 'Payroll '.$run->period.' dibuat ('.$run->lines->count().' karyawan).');
    }

    public function approve(Request $request, PayrollService $payroll, int $run)
    {
        $model = PayrollRun::query()->findOrFail($run);
        $this->authorize('manage', $model);
        $payroll->approveRun($model, $request->user()->id);

        return back()->with('status', 'Payroll disetujui dan diposting ke akuntansi (bila aktif).');
    }

    public function markPaid(Request $request, PayrollService $payroll, int $run)
    {
        $model = PayrollRun::query()->findOrFail($run);
        $this->authorize('manage', $model);
        $payroll->markPaid($model, $request->user()->id);

        return back()->with('status', 'Payroll ditandai lunas.');
    }

    public function payslip(PayrollService $payroll, int $run, int $employee)
    {
        $model = PayrollRun::query()->findOrFail($run);
        $this->authorize('viewAny', PayrollRun::class);

        return view('payroll.payslip', ['slip' => $payroll->payslip($model, $employee)]);
    }
}
