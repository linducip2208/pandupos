<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesOrderWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('sales.view') || $request->user()->can('sales.create'), 403);

        return view('sales-orders.index', [
            'branches' => Branch::query()->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'customers' => Contact::query()->whereIn('type', ['customer', 'both'])->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product.unit')->where('is_active', true)->orderBy('sku')->get(),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
            'orders' => SalesOrder::query()->with(['contact', 'lines.variant.product.unit'])->latest()->limit(30)->get(),
        ]);
    }

    public function store(Request $request, SalesOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'], 'contact_id' => ['required', 'integer'],
            'product_variant_id' => ['required', 'integer'], 'unit_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'], 'unit_price' => ['required', 'numeric', 'min:0'],
            'order_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = $service->create(TenantContext::idOrFail(), [
            'branch_id' => $data['branch_id'], 'warehouse_id' => $data['warehouse_id'], 'contact_id' => $data['contact_id'],
            'order_date' => $data['order_date'], 'notes' => $data['notes'] ?? null,
            'lines' => [[
                'product_variant_id' => $data['product_variant_id'], 'unit_id' => $data['unit_id'],
                'quantity' => $data['quantity'], 'unit_price' => $data['unit_price'],
            ]],
        ], $request->user()->id);

        return back()->with('status', "Sales Order {$order->order_no} dibuat sebagai draft.");
    }
}
