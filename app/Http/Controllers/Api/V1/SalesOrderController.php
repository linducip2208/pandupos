<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('sales.view'), 403);

        return response()->json(SalesOrder::with(['contact', 'lines.variant', 'deliveries.lines'])->latest()->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request, SalesOrderService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'], 'contact_id' => ['required', 'integer'],
            'sales_quotation_id' => ['nullable', 'integer'], 'order_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_variant_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($service->create(TenantContext::idOrFail(), $data, $request->user()->id), 201);
    }

    public function confirm(Request $request, SalesOrder $order, SalesOrderService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);

        return response()->json($service->confirm($order, $request->user()->id));
    }

    public function deliver(Request $request, SalesOrder $order, SalesOrderService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate(['tracking_reference' => ['nullable', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.sales_order_line_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0']]);

        return response()->json($service->deliver($order, $data['lines'], $request->user()->id, $data['tracking_reference'] ?? null), 201);
    }

    public function cancel(Request $request, SalesOrder $order, SalesOrderService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);

        return response()->json($service->cancel($order, $request->user()->id));
    }
}
