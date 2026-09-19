<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
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
            'orders' => SalesOrder::query()->with(['contact', 'invoice.payments', 'lines.variant.product.unit'])->latest()->limit(30)->get(),
        ]);
    }

    public function store(Request $request, SalesOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'], 'contact_id' => ['required', 'integer'],
            'product_variant_id' => ['required_without:lines', 'integer'], 'unit_id' => ['required_without:lines', 'integer'],
            'quantity' => ['required_without:lines', 'numeric', 'gt:0'], 'unit_price' => ['required_without:lines', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer'], 'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'order_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $lines = $data['lines'] ?? [[
            'product_variant_id' => $data['product_variant_id'], 'unit_id' => $data['unit_id'],
            'quantity' => $data['quantity'], 'unit_price' => $data['unit_price'],
        ]];
        $order = $service->create(TenantContext::idOrFail(), [
            'branch_id' => $data['branch_id'], 'warehouse_id' => $data['warehouse_id'], 'contact_id' => $data['contact_id'],
            'order_date' => $data['order_date'], 'notes' => $data['notes'] ?? null,
            'lines' => $lines,
        ], $request->user()->id);

        return back()->with('status', "Sales Order {$order->order_no} dibuat sebagai draft.");
    }

    public function confirm(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $this->assertTenant($order);
        $service->confirm($order, $request->user()->id);

        return back()->with('status', "Sales Order {$order->order_no} dikonfirmasi dan stok direservasi.");
    }

    public function deliver(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $this->assertTenant($order);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'tracking_reference' => ['nullable', 'string', 'max:120'],
        ]);
        $delivery = $service->deliver($order, $data['lines'], $request->user()->id, $data['tracking_reference'] ?? null);

        return back()->with('status', "Pengiriman {$delivery->delivery_no} berhasil diposting.");
    }

    public function cancel(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $this->assertTenant($order);
        $service->cancel($order, $request->user()->id);

        return back()->with('status', "Sales Order {$order->order_no} dibatalkan dan reservasi aktif dilepas.");
    }

    public function invoice(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $this->assertTenant($order);
        $invoice = $service->invoice($order, $request->user()->id);

        return back()->with('status', "Invoice {$invoice->invoice_no} dibuat tanpa mutasi stok tambahan.");
    }

    public function payInvoice(Request $request, SalesInvoice $invoice, SalesOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        abort_unless($invoice->tenant_id === TenantContext::idOrFail(), 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,transfer,qris,ewallet,card,other'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $service->payInvoice($invoice, (float) $data['amount'], $data['method'], $data['reference'] ?? null, $request->user()->id);

        return back()->with('status', 'Pembayaran invoice berhasil dicatat.');
    }

    private function assertTenant(SalesOrder $order): void
    {
        abort_unless($order->tenant_id === TenantContext::idOrFail(), 404);
    }
}
