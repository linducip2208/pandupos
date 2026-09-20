<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GymMembership;
use App\Services\GymService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class GymController extends Controller
{
    public function memberships()
    {
        $this->authorize('viewAny', GymMembership::class);

        return response()->json(['data' => GymMembership::query()->with(['member', 'package'])->orderByDesc('id')->limit(100)->get()]);
    }

    public function subscribe(Request $request, GymService $gym)
    {
        $this->authorize('create', GymMembership::class);
        $data = $request->validate([
            'member_id' => 'required|integer', 'package_id' => 'required|integer',
            'starts_on' => 'required|date',
        ]);

        return response()->json(['data' => $gym->subscribe(TenantContext::idOrFail(), (int) $data['member_id'], (int) $data['package_id'], $data['starts_on'], $request->user()->id)], 201);
    }

    public function transition(Request $request, GymService $gym, int $membership)
    {
        $model = GymMembership::query()->findOrFail($membership);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'action' => 'required|in:pay,checkin,cancel',
            'amount' => 'nullable|numeric|gt:0', 'trainer_id' => 'nullable|integer',
        ]);
        $result = match ($data['action']) {
            'pay' => $gym->recordPayment($model, (float) ($data['amount'] ?? 0), $request->user()->id),
            'checkin' => $gym->checkIn($model, isset($data['trainer_id']) ? (int) $data['trainer_id'] : null, $request->user()->id),
            'cancel' => $gym->cancel($model, $request->user()->id),
        };

        return response()->json(['data' => $result]);
    }
}
