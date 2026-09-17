<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SalesQuotation;
use App\Services\SalesDocumentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesDocumentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('sales.view'), 403);

        return response()->json(SalesQuotation::with(['contact', 'lines.variant'])->latest()->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request, SalesDocumentService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'contact_id' => ['required', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'quotation_date' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'lines.*.description' => ['nullable', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($service->createQuotation($tenantId, $data, $request->user()->id), 201);
    }

    public function transition(Request $request, SalesQuotation $quotation, SalesDocumentService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate(['status' => ['required', 'in:sent,accepted,rejected,expired']]);

        return response()->json($service->transition($quotation, $data['status'], $request->user()->id));
    }

    public function proforma(Request $request, SalesQuotation $quotation, SalesDocumentService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate(['due_date' => ['nullable', 'date', 'after_or_equal:today']]);

        return response()->json($service->convertToProforma($quotation, $request->user()->id, $data['due_date'] ?? null), 201);
    }
}
