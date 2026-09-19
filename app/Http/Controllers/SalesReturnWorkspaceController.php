<?php

namespace App\Http\Controllers;

use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Services\SaleService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesReturnWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('pos.sale.create') || $request->user()->can('pos.sale.void') || $request->user()->can('sales.view'), 403);

        return view('sales-returns.index', [
            'invoices' => SalesInvoice::query()->with(['contact', 'lines.variant.product'])->where('status', 'final')->latest()->limit(30)->get(),
            'returns' => SalesReturn::query()->with(['invoice.contact', 'lines.salesLine', 'refunds'])->latest()->limit(30)->get(),
        ]);
    }

    public function store(Request $request, SalesInvoice $invoice, SaleService $service): RedirectResponse
    {
        abort_unless($request->user()->can('pos.sale.create'), 403);
        $this->assertTenant($invoice->tenant_id);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.inventory_batch_id' => ['nullable', 'integer'],
            'lines.*.serial_number_ids' => ['nullable', 'array'],
            'lines.*.serial_number_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);
        $service->return(
            $invoice->id, $data['lines'], TenantContext::idOrFail(), $request->user()->id,
            $data['idempotency_key'] ?? null, $data['reason'], $request->user()->can('pos.sale.create')
        );

        return back()->with('status', 'Return penjualan berhasil dicatat dan stok dikembalikan.');
    }

    public function refund(Request $request, SalesReturn $salesReturn, SaleService $service): RedirectResponse
    {
        abort_unless($request->user()->can('pos.sale.void'), 403);
        $this->assertTenant($salesReturn->tenant_id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,transfer,qris,ewallet,card'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $service->refund(
            $salesReturn->sales_invoice_id, $salesReturn->id, (float) $data['amount'], $data['method'],
            $data['reference'] ?? null, $data['reason'], $request->user()->id,
            $request->user()->can('pos.sale.void'), TenantContext::idOrFail()
        );

        return back()->with('status', 'Refund berhasil dicatat.');
    }

    public function void(Request $request, SalesInvoice $invoice, SaleService $service): RedirectResponse
    {
        abort_unless($request->user()->can('pos.sale.void'), 403);
        $this->assertTenant($invoice->tenant_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->void($invoice->id, true, TenantContext::idOrFail(), $data['reason']);

        return back()->with('status', 'Penjualan dibatalkan (void) dan stok dikembalikan.');
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === TenantContext::idOrFail(), 404);
    }
}
