<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierInvoice;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SupplierDocumentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchasingWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeView($request);

        return view('purchasing.index', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'suppliers' => Contact::query()->whereIn('type', ['supplier', 'both'])->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product')->orderBy('sku')->get(),
            'purchases' => Purchase::query()->with(['contact', 'warehouse', 'lines.variant.product', 'goodsReceipts.lines'])->latest()->limit(30)->get(),
            'invoices' => SupplierInvoice::query()->with(['supplier', 'purchase', 'payments'])->latest()->limit(30)->get(),
            'returns' => PurchaseReturn::query()->with(['purchase.contact', 'lines.variant.product'])->latest()->limit(30)->get(),
        ]);
    }

    public function storePurchase(Request $request, PurchaseService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'contact_id' => ['required', 'integer'],
            'product_variant_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        $purchase = $service->createDraft(TenantContext::idOrFail(), (int) $data['warehouse_id'], (int) $data['contact_id'], [[
            'product_variant_id' => $data['product_variant_id'], 'quantity' => $data['quantity'], 'unit_cost' => $data['unit_cost'],
        ]], $request->user()->id);

        return back()->with('status', $purchase->status === 'pending_approval' ? 'PO dibuat dan menunggu approval.' : 'PO berhasil dibuat.');
    }

    public function receive(Request $request, Purchase $purchase, PurchaseService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $this->assertTenant($purchase->tenant_id);
        $data = $request->validate(['lines' => ['required', 'array'], 'lines.*' => ['nullable', 'numeric', 'gt:0']]);
        $lines = collect($data['lines'])->filter(fn ($quantity) => $quantity !== null && $quantity !== '')
            ->map(fn ($quantity, $variantId) => ['product_variant_id' => (int) $variantId, 'quantity' => $quantity])->values()->all();
        $service->receive($purchase->id, $lines, TenantContext::idOrFail(), $request->user()->id);

        return back()->with('status', 'Goods Receipt berhasil diposting.');
    }

    public function storeInvoice(Request $request, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $data = $request->validate([
            'purchase_id' => ['required', 'integer'], 'supplier_id' => ['required', 'integer'],
            'invoice_number' => ['required', 'string', 'max:100'], 'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'], 'subtotal' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'], 'shipping' => ['nullable', 'numeric', 'min:0'],
        ]);
        $service->createInvoice(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Invoice pemasok berhasil dicatat.');
    }

    public function pay(Request $request, SupplierInvoice $invoice, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $this->assertTenant($invoice->tenant_id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'string', 'max:40'], 'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $service->pay($invoice, (float) $data['amount'], $data['method'], $data['reference'] ?? null, $request->user()->id);

        return back()->with('status', 'Pembayaran pemasok berhasil dicatat.');
    }

    public function storeReturn(Request $request, Purchase $purchase, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $this->assertTenant($purchase->tenant_id);
        $data = $request->validate([
            'purchase_line_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'], 'settlement_type' => ['required', 'in:supplier_credit,cash_refund,replacement'],
        ]);
        $service->createReturn($purchase, [[
            'purchase_line_id' => $data['purchase_line_id'], 'quantity' => $data['quantity'],
        ]], $data['reason'], $data['settlement_type'], $request->user()->id);

        return back()->with('status', 'Purchase return berhasil diposting.');
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('purchase.create') || $request->user()->can('purchase.approve'), 403);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === TenantContext::idOrFail(), 404);
    }
}
