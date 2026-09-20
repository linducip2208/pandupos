<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Services\PayrollService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function runs()
    {
        $this->authorize('viewAny', PayrollRun::class);

        return response()->json(['data' => PayrollRun::query()->with('lines')->orderByDesc('id')->limit(50)->get()]);
    }

    public function storeRun(Request $request, PayrollService $payroll)
    {
        $this->authorize('create', PayrollRun::class);
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return response()->json(['data' => $payroll->createRun(TenantContext::idOrFail(), $data['period'], $request->user()->id)->load('lines')], 201);
    }

    public function transition(Request $request, PayrollService $payroll, int $run)
    {
        $model = PayrollRun::query()->findOrFail($run);
        $this->authorize('manage', $model);
        $data = $request->validate(['action' => 'required|in:approve,pay']);
        $result = $data['action'] === 'approve'
            ? $payroll->approveRun($model, $request->user()->id)
            : $payroll->markPaid($model, $request->user()->id);

        return response()->json(['data' => $result->load('lines')]);
    }

    public function payslip(PayrollService $payroll, int $run, int $employee)
    {
        $model = PayrollRun::query()->findOrFail($run);
        $this->authorize('viewAny', PayrollRun::class);

        return response()->json(['data' => $payroll->payslip($model, $employee)]);
    }
}
