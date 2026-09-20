<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HrmEmployee;
use App\Models\HrmLeave;
use App\Services\HrmService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class HrmController extends Controller
{
    public function employees()
    {
        $this->authorize('viewAny', HrmEmployee::class);

        return response()->json(['data' => HrmEmployee::query()->with('department')->orderBy('code')->limit(200)->get()]);
    }

    public function storeEmployee(Request $request, HrmService $hrm)
    {
        $this->authorize('create', HrmEmployee::class);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'code' => 'nullable|string|max:32',
            'department_id' => 'nullable|integer', 'position' => 'nullable|string|max:128',
            'phone' => 'nullable|string|max:64',
        ]);

        return response()->json(['data' => $hrm->registerEmployee(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function attend(Request $request, HrmService $hrm, int $employee)
    {
        $model = HrmEmployee::query()->findOrFail($employee);
        $this->authorize('manage', $model);
        $data = $request->validate(['action' => 'required|in:in,out']);
        $result = $data['action'] === 'in' ? $hrm->checkIn($model, $request->user()->id) : $hrm->checkOut($model, $request->user()->id);

        return response()->json(['data' => $result]);
    }

    public function requestLeave(Request $request, HrmService $hrm, int $employee)
    {
        $model = HrmEmployee::query()->findOrFail($employee);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'type' => 'required|in:annual,sick,unpaid', 'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on', 'reason' => 'nullable|string',
        ]);

        return response()->json(['data' => $hrm->requestLeave(TenantContext::idOrFail(), $model->id, $data, $request->user()->id)], 201);
    }

    public function decideLeave(Request $request, HrmService $hrm, int $leave)
    {
        $model = HrmLeave::query()->findOrFail($leave);
        $this->authorize('manage', $model->employee);
        $data = $request->validate(['decision' => 'required|in:approved,rejected']);

        return response()->json(['data' => $hrm->decideLeave($model, $data['decision'] === 'approved', $request->user()->id)]);
    }
}
