<?php

namespace App\Http\Controllers;

use App\Models\MrpBom;
use App\Models\MrpWorkOrder;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\MrpService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class MrpWorkspaceController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', MrpBom::class);

        return view('mrp.index', [
            'boms' => MrpBom::query()->with(['finishedVariant', 'lines.component'])->orderByDesc('id')->limit(100)->get(),
            'variants' => ProductVariant::query()->orderBy('sku')->limit(500)->get(),
        ]);
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
        $mrp->createBom(TenantContext::idOrFail(), (int) $data['finished_variant_id'], $data['lines'], $request->user()->id, $data['notes'] ?? null);

        return back()->with('status', 'BOM dibuat sebagai revisi aktif terbaru.');
    }

    public function orders(MrpService $mrp)
    {
        $this->authorize('viewAny', MrpBom::class);
        $orders = MrpWorkOrder::query()->with(['bom', 'finishedVariant', 'warehouse'])->orderByDesc('id')->limit(100)->get();
        $shortages = [];
        foreach ($orders as $order) {
            if ($order->status === MrpWorkOrder::DRAFT) {
                $shortages[$order->id] = $mrp->shortages($order);
            }
        }

        return view('mrp.orders', [
            'orders' => $orders,
            'shortages' => $shortages,
            'boms' => MrpBom::query()->where('is_active', true)->with('finishedVariant')->orderByDesc('id')->limit(100)->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function storeOrder(Request $request, MrpService $mrp)
    {
        $this->authorize('create', MrpBom::class);
        $data = $request->validate([
            'bom_id' => 'required|integer', 'warehouse_id' => 'required|integer',
            'quantity_planned' => 'required|numeric|gt:0', 'scheduled_at' => 'nullable|date',
        ]);
        $order = $mrp->createWorkOrder(TenantContext::idOrFail(), (int) $data['bom_id'], (int) $data['warehouse_id'], (float) $data['quantity_planned'], $request->user()->id, $data['scheduled_at'] ?? null);

        return back()->with('status', 'Work order '.$order->number.' dibuat.');
    }

    public function release(Request $request, MrpService $mrp, int $order)
    {
        $model = MrpWorkOrder::query()->findOrFail($order);
        $this->authorize('manageOrder', $model);
        $mrp->release($model, $request->user()->id);

        return back()->with('status', 'Work order dirilis.');
    }

    public function start(Request $request, MrpService $mrp, int $order)
    {
        $model = MrpWorkOrder::query()->findOrFail($order);
        $this->authorize('manageOrder', $model);
        $mrp->start($model, $request->user()->id);

        return back()->with('status', 'Produksi dimulai.');
    }

    public function produce(Request $request, MrpService $mrp, int $order)
    {
        $model = MrpWorkOrder::query()->findOrFail($order);
        $this->authorize('manageOrder', $model);
        $data = $request->validate(['quantity_good' => 'required|numeric|min:0', 'quantity_scrap' => 'required|numeric|min:0']);
        $mrp->recordProduction($model, (float) $data['quantity_good'], (float) $data['quantity_scrap'], $request->user()->id);

        return back()->with('status', 'Hasil produksi dicatat.');
    }

    public function finish(Request $request, MrpService $mrp, int $order)
    {
        $model = MrpWorkOrder::query()->findOrFail($order);
        $this->authorize('manageOrder', $model);
        $mrp->finish($model, $request->user()->id);

        return back()->with('status', 'Work order selesai.');
    }

    public function cancel(Request $request, MrpService $mrp, int $order)
    {
        $model = MrpWorkOrder::query()->findOrFail($order);
        $this->authorize('manageOrder', $model);
        $mrp->cancel($model, $request->user()->id);

        return back()->with('status', 'Work order dibatalkan.');
    }
}
