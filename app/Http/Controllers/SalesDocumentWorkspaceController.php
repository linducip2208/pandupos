<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\SalesQuotation;
use App\Services\SalesDocumentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesDocumentWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('sales.view') || $request->user()->can('sales.create'), 403);

        return view('sales-documents.index', [
            'branches' => Branch::query()->orderBy('name')->get(),
            'customers' => Contact::query()->whereIn('type', ['customer', 'both'])->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product')->where('is_active', true)->orderBy('sku')->get(),
            'quotations' => SalesQuotation::query()->with(['branch', 'contact', 'lines.variant'])->latest()->limit(50)->get(),
        ]);
    }

    public function store(Request $request, SalesDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'], 'contact_id' => ['required', 'integer'],
            'quotation_date' => ['required', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000'],
            'product_variant_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'], 'line_discount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $quotation = $service->createQuotation(TenantContext::idOrFail(), $data + ['lines' => [[
            'product_variant_id' => $data['product_variant_id'], 'quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'], 'discount' => $data['line_discount'] ?? 0,
        ]]], $request->user()->id);

        return back()->with('status', "Quotation {$quotation->quotation_no} dibuat sebagai draft.");
    }

    public function transition(Request $request, SalesQuotation $quotation, SalesDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $this->assertTenant($quotation);
        $data = $request->validate(['status' => ['required', 'in:sent,accepted,rejected,expired']]);
        $service->transition($quotation, $data['status'], $request->user()->id);

        return back()->with('status', 'Status quotation diperbarui.');
    }

    public function print(Request $request, SalesQuotation $quotation): View
    {
        abort_unless($request->user()->can('sales.view') || $request->user()->can('sales.create'), 403);
        $this->assertTenant($quotation);

        return view('sales-documents.quotation-print', ['quotation' => $quotation->load(['branch', 'contact', 'lines.variant'])]);
    }

    private function assertTenant(SalesQuotation $quotation): void
    {
        abort_unless($quotation->tenant_id === TenantContext::idOrFail(), 404);
    }
}
