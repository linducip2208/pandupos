<?php

namespace App\Http\Controllers;

use App\Models\HrmAttendance;
use App\Models\HrmDepartment;
use App\Models\HrmEmployee;
use App\Models\HrmHoliday;
use App\Models\HrmLeave;
use App\Services\HrmService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class HrmWorkspaceController extends Controller
{
    public function index(HrmService $hrm)
    {
        $this->authorize('viewAny', HrmEmployee::class);
        $tenantId = TenantContext::idOrFail();
        $employees = HrmEmployee::query()->with('department')->orderBy('code')->limit(200)->get();
        $balances = [];
        $year = now()->format('Y');
        foreach ($employees as $employee) {
            $balances[$employee->id] = [
                'annual' => $hrm->leaveBalance($tenantId, $employee->id, 'annual', $year),
                'sick' => $hrm->leaveBalance($tenantId, $employee->id, 'sick', $year),
            ];
        }

        return view('hrm.index', [
            'employees' => $employees, 'balances' => $balances,
            'departments' => HrmDepartment::query()->orderBy('name')->get(),
            'leaves' => HrmLeave::query()->with('employee')->orderByDesc('id')->limit(100)->get(),
            'today' => HrmAttendance::query()->with('employee')->whereDate('worked_on', now()->toDateString())->get(),
            'holidays' => HrmHoliday::query()->orderBy('holiday_on')->limit(50)->get(),
        ]);
    }

    public function storeEmployee(Request $request, HrmService $hrm)
    {
        $this->authorize('create', HrmEmployee::class);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'code' => 'nullable|string|max:32',
            'department_id' => 'nullable|integer', 'position' => 'nullable|string|max:128',
            'phone' => 'nullable|string|max:64',
        ]);
        $hrm->registerEmployee(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Karyawan '.$data['name'].' terdaftar.');
    }

    public function checkIn(Request $request, HrmService $hrm, int $employee)
    {
        $model = HrmEmployee::query()->findOrFail($employee);
        $this->authorize('manage', $model);
        $hrm->checkIn($model, $request->user()->id);

        return back()->with('status', 'Check-in tercatat.');
    }

    public function checkOut(Request $request, HrmService $hrm, int $employee)
    {
        $model = HrmEmployee::query()->findOrFail($employee);
        $this->authorize('manage', $model);
        $hrm->checkOut($model, $request->user()->id);

        return back()->with('status', 'Check-out tercatat.');
    }

    public function requestLeave(Request $request, HrmService $hrm, int $employee)
    {
        $model = HrmEmployee::query()->findOrFail($employee);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'type' => 'required|in:annual,sick,unpaid', 'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on', 'reason' => 'nullable|string',
        ]);
        $hrm->requestLeave(TenantContext::idOrFail(), $model->id, $data, $request->user()->id);

        return back()->with('status', 'Pengajuan cuti dikirim.');
    }

    public function decideLeave(Request $request, HrmService $hrm, int $leave)
    {
        $model = HrmLeave::query()->findOrFail($leave);
        $this->authorize('manage', $model->employee);
        $data = $request->validate(['decision' => 'required|in:approved,rejected']);
        $hrm->decideLeave($model, $data['decision'] === 'approved', $request->user()->id);

        return back()->with('status', 'Cuti '.$data['decision'].'.');
    }

    public function storeHoliday(Request $request, HrmService $hrm)
    {
        $this->authorize('create', HrmEmployee::class);
        $data = $request->validate(['holiday_on' => 'required|date', 'name' => 'required|string|max:160']);
        $hrm->createHoliday(TenantContext::idOrFail(), $data['holiday_on'], $data['name'], $request->user()->id);

        return back()->with('status', 'Hari libur ditambahkan.');
    }
}
