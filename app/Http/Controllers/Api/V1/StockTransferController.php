<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TransferOrder;
use App\Services\StockTransferService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeTransfer($request);

        return response()->json(['data' => TransferOrder::with(['fromWarehouse', 'toWarehouse', 'lines.variant'])
            ->latest()->paginate(50)]);
    }

    public function show(Request $request, TransferOrder $transfer)
    {
        $this->authorizeTransfer($request);

        return response()->json(['data' => $transfer->load(['fromWarehouse', 'toWarehouse', 'lines.variant'])]);
    }

    public function store(Request $request, StockTransferService $service)
    {
        $this->authorizeTransfer($request);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'different:to_warehouse_id', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'to_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity' => 'required|numeric|gt:0',
        ]);
        $transfer = $service->createDraft(
            $tenantId, (int) $data['from_warehouse_id'], (int) $data['to_warehouse_id'],
            $data['lines'], $data['notes'] ?? null, $request->user()->id
        );

        return response()->json(['data' => $transfer], 201);
    }

    public function approve(Request $request, TransferOrder $transfer, StockTransferService $service)
    {
        $this->authorizeTransfer($request);

        return response()->json(['data' => $service->approve($transfer, $request->user()->id)]);
    }

    public function ship(Request $request, TransferOrder $transfer, StockTransferService $service)
    {
        $this->authorizeTransfer($request);

        return response()->json(['data' => $service->ship($transfer, $request->user()->id)]);
    }

    public function inTransit(Request $request, TransferOrder $transfer, StockTransferService $service)
    {
        $this->authorizeTransfer($request);

        return response()->json(['data' => $service->markInTransit($transfer, $request->user()->id)]);
    }

    public function receive(Request $request, TransferOrder $transfer, StockTransferService $service)
    {
        $this->authorizeTransfer($request);
        $data = $request->validate([
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|numeric|gt:0',
        ]);

        return response()->json(['data' => $service->receive($transfer, $data['quantities'], $request->user()->id)]);
    }

    public function cancel(Request $request, TransferOrder $transfer, StockTransferService $service)
    {
        $this->authorizeTransfer($request);

        return response()->json(['data' => $service->cancel($transfer, $request->user()->id)]);
    }

    private function authorizeTransfer(Request $request): void
    {
        abort_unless($request->user()->is_platform_admin || $request->user()->can('inventory.transfer'), 403);
    }
}
