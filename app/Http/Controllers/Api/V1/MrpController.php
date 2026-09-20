<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MrpBom;
use App\Models\MrpWorkOrder;
use App\Services\MrpService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class MrpController extends Controller
{
    public function boms()
    {
        $this->authorize('viewAny', MrpBom::class);

        return response()->json(['data' => MrpBom::query()->with(['lines'])->orderByDesc('id')->limit(100)->get()]);
    }

    public function storeBom(Request $request, MrpService $mrp)
    {
        $this->authorize('create', MrpBom::class);
        $data = $request->validate([
            'finished_variant_id' => 'required|integer',
            'lines' => 'required|array|min:1',
            'lines.*.component_variant_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.scrap_rate' => 'nullable|numeric|min:0|max:1',
            'notes' => 'nullable|string',
        ]);

        return response()->json(['data' => $mrp->createBom(TenantContext::idOrFail(), (int) $data['finished_variant_id'], $data['lines'], $request->user()->id, $data['notes'] ?? null)->load('lines')], 201);
    }

    public function orders()
    {
        $this->authorize('viewAny', MrpBom::class);

        return response()->json(['data' => MrpWorkOrder::query()->orderByDesc('id')->limit(100)->get()]);
    }

    public function storeOrder(Request $request, MrpService $mrp)
    {
        $this->authorize('create', MrpBom::class);
        $data = $request->validate([
            'bom_id' => 'required|integer', 'warehouse_id' => 'required|integer',
            'quantity_planned' => 'required|numeric|gt:0', 'scheduled_at' => 'nullable|date',
        ]);

        return response()->json(['data' => $mrp->createWorkOrder(TenantContext::idOrFail(), (int) $data['bom_id'], (int) $data['warehouse_id'], (float) $data['quantity_planned'], $request->user()->id, $data['scheduled_at'] ?? null)], 201);
    }

    public function transition(Request $request, MrpService $mrp, int $order)
    {
        $model = MrpWorkOrder::query()->findOrFail($order);
        $this->authorize('manageOrder', $model);
        $data = $request->validate(['action' => 'required|in:release,start,finish,cancel']);
        $result = match ($data['action']) {
            'release' => $mrp->release($model, $request->user()->id),
            'start' => $mrp->start($model, $request->user()->id),
            'finish' => $mrp->finish($model, $request->user()->id),
            'cancel' => $mrp->cancel($model, $request->user()->id),
        };

        return response()->json(['data' => $result]);
    }

    public function produce(Request $request, MrpService $mrp, int $order)
    {
        $model = MrpWorkOrder::query()->findOrFail($order);
        $this->authorize('manageOrder', $model);
        $data = $request->validate(['quantity_good' => 'required|numeric|min:0', 'quantity_scrap' => 'required|numeric|min:0']);

        return response()->json(['data' => $mrp->recordProduction($model, (float) $data['quantity_good'], (float) $data['quantity_scrap'], $request->user()->id)]);
    }
}
