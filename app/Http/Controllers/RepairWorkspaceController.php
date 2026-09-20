<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\RepairOrder;
use App\Models\Warehouse;
use App\Services\RepairService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class RepairWorkspaceController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', RepairOrder::class);

        return view('repair.index', [
            'orders' => RepairOrder::query()->with(['contact', 'parts.variant'])->orderByDesc('id')->limit(100)->get(),
            'contacts' => Contact::query()->orderBy('name')->limit(200)->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'variants' => ProductVariant::query()->orderBy('sku')->limit(500)->get(),
        ]);
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
        $order = $repairs->intake(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Repair order '.$order->number.' diterima.');
    }

    public function diagnose(Request $request, RepairService $repairs, int $order)
    {
        $model = RepairOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate(['diagnosis' => 'required|string', 'labor_cost' => 'required|numeric|min:0']);
        $repairs->diagnose($model, $data['diagnosis'], (float) $data['labor_cost'], $request->user()->id);

        return back()->with('status', 'Diagnosis tersimpan.');
    }

    public function transition(Request $request, RepairService $repairs, int $order)
    {
        $model = RepairOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate(['action' => 'required|in:start,waiting,ready,cancel']);
        match ($data['action']) {
            'start' => $repairs->start($model, $request->user()->id),
            'waiting' => $repairs->markWaitingParts($model, $request->user()->id),
            'ready' => $repairs->markReady($model, $request->user()->id),
            'cancel' => $repairs->cancel($model, $request->user()->id),
        };

        return back()->with('status', 'Status repair diperbarui.');
    }

    public function addPart(Request $request, RepairService $repairs, int $order)
    {
        $model = RepairOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'product_variant_id' => 'required|integer', 'quantity' => 'required|numeric|gt:0',
            'unit_price' => 'nullable|numeric|min:0',
        ]);
        $repairs->addPart($model, (int) $data['product_variant_id'], (float) $data['quantity'], isset($data['unit_price']) ? (float) $data['unit_price'] : null, $request->user()->id);

        return back()->with('status', 'Part ditambahkan.');
    }

    public function pay(Request $request, RepairService $repairs, int $order)
    {
        $model = RepairOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate(['amount' => 'required|numeric|gt:0', 'method' => 'required|string|max:32']);
        $repairs->recordPayment($model, (float) $data['amount'], $data['method'], $request->user()->id);

        return back()->with('status', 'Pembayaran dicatat.');
    }

    public function deliver(Request $request, RepairService $repairs, int $order)
    {
        $model = RepairOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $repairs->deliver($model, $request->user()->id);

        return back()->with('status', 'Unit diserahkan; stok part terpotong.');
    }
}
