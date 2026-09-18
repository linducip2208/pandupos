<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Services\StockAdjustmentService;
use App\Services\StockCountService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryControlController extends Controller
{
    public function adjustments(Request $request)
    {
        $this->authorizeAdjustment($request);

        return response()->json(['data' => StockAdjustment::with(['warehouse', 'lines.variant'])->latest()->paginate(50)]);
    }

    public function storeAdjustment(Request $request, StockAdjustmentService $service)
    {
        $this->authorizeAdjustment($request);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'warehouse_location_id' => ['nullable', Rule::exists('warehouse_locations', 'id')->where('tenant_id', $tenantId)],
            'reason' => 'required|in:damage,expired,loss,count_correction,opening_correction,other',
            'notes' => 'required|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity_change' => 'required|numeric|not_in:0',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.inventory_batch_id' => ['nullable', Rule::exists('inventory_batches', 'id')->where('tenant_id', $tenantId)],
            'lines.*.serial_number_id' => ['nullable', Rule::exists('serial_numbers', 'id')->where('tenant_id', $tenantId)],
            'lines.*.warehouse_location_id' => ['nullable', Rule::exists('warehouse_locations', 'id')->where('tenant_id', $tenantId)],
        ]);
        $adjustment = $service->create($tenantId, (int) $data['warehouse_id'], $data['reason'], $data['lines'], $data['notes'], $request->user()->id);

        return response()->json(['data' => $adjustment], 201);
    }

    public function approveAdjustment(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service)
    {
        $this->authorizeAdjustment($request);

        return response()->json(['data' => $service->approve($adjustment, $request->user()->id)]);
    }

    public function submitAdjustment(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service)
    {
        $this->authorizeAdjustment($request);

        return response()->json(['data' => $service->submit($adjustment, $request->user()->id)]);
    }

    public function postAdjustment(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service)
    {
        $this->authorizeAdjustment($request);

        return response()->json(['data' => $service->post($adjustment, $request->user()->id)]);
    }

    public function counts(Request $request)
    {
        $this->authorizeAdjustment($request);

        return response()->json(['data' => StockCount::with(['warehouse', 'lines.variant'])->latest()->paginate(50)]);
    }

    public function storeCount(Request $request, StockCountService $service)
    {
        $this->authorizeAdjustment($request);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'reference' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:2000',
        ]);
        $count = $service->createAndSnapshot($tenantId, (int) $data['warehouse_id'], $data['reference'] ?? null, $data['notes'] ?? null, $request->user()->id, $data['warehouse_location_id'] ?? null);

        return response()->json(['data' => $count], 201);
    }

    public function recordCount(Request $request, StockCount $count, StockCountService $service)
    {
        $this->authorizeAdjustment($request);
        $data = $request->validate(['quantities' => 'required|array|min:1', 'quantities.*' => 'required|numeric|min:0']);

        return response()->json(['data' => $service->recordCounts($count, $data['quantities'], $request->user()->id)]);
    }

    public function approveCount(Request $request, StockCount $count, StockCountService $service)
    {
        $this->authorizeAdjustment($request);

        return response()->json(['data' => $service->approve($count, $request->user()->id)]);
    }

    public function postCount(Request $request, StockCount $count, StockCountService $service)
    {
        $this->authorizeAdjustment($request);

        return response()->json(['data' => $service->post($count, $request->user()->id)]);
    }

    private function authorizeAdjustment(Request $request): void
    {
        abort_unless($request->user()->is_platform_admin || $request->user()->can('inventory.adjust'), 403);
    }
}
