<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RepairOrder;
use App\Services\RepairService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class RepairController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', RepairOrder::class);

        return response()->json(['data' => RepairOrder::query()->with('parts')->orderByDesc('id')->limit(100)->get()]);
    }

    public function store(Request $request, RepairService $repairs)
    {
        $this->authorize('create', RepairOrder::class);
        $data = $request->validate([
            'contact_id' => 'nullable|integer', 'device_brand' => 'nullable|string|max:64',
            'device_model' => 'nullable|string|max:128', 'device_identifier' => 'nullable|string|max:128',
            'complaint' => 'required|string', 'warranty' => 'nullable|boolean',
            'warehouse_id' => 'required|integer', 'promised_at' => 'nullable|date',
        ]);

        return response()->json(['data' => $repairs->intake(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function transition(Request $request, RepairService $repairs, int $order)
    {
        $model = RepairOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'action' => 'required|in:diagnose,start,waiting,ready,deliver,cancel',
            'diagnosis' => 'nullable|string', 'labor_cost' => 'nullable|numeric|min:0',
        ]);
        $result = match ($data['action']) {
            'diagnose' => $repairs->diagnose($model, (string) ($data['diagnosis'] ?? ''), (float) ($data['labor_cost'] ?? 0), $request->user()->id),
            'start' => $repairs->start($model, $request->user()->id),
            'waiting' => $repairs->markWaitingParts($model, $request->user()->id),
            'ready' => $repairs->markReady($model, $request->user()->id),
            'deliver' => $repairs->deliver($model, $request->user()->id),
            'cancel' => $repairs->cancel($model, $request->user()->id),
        };

        return response()->json(['data' => $result]);
    }

    public function addPart(Request $request, RepairService $repairs, int $order)
    {
        $model = RepairOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'product_variant_id' => 'required|integer', 'quantity' => 'required|numeric|gt:0',
            'unit_price' => 'nullable|numeric|min:0',
        ]);

        return response()->json(['data' => $repairs->addPart($model, (int) $data['product_variant_id'], (float) $data['quantity'], isset($data['unit_price']) ? (float) $data['unit_price'] : null, $request->user()->id)]);
    }

    public function pay(Request $request, RepairService $repairs, int $order)
    {
        $model = RepairOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate(['amount' => 'required|numeric|gt:0', 'method' => 'required|string|max:32']);

        return response()->json(['data' => $repairs->recordPayment($model, (float) $data['amount'], $data['method'], $request->user()->id)]);
    }
}
