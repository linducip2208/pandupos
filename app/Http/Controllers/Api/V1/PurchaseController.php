<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Purchase::with('lines')->orderByDesc('id')->paginate($request->get('per_page', 15))
        );
    }

    public function store(Request $request, PurchaseService $service)
    {
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'contact_id' => ['required', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'lines' => 'required|array|min:1',
            'lines.*.product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
        ]);

        $purchase = $service->createDraft(
            $tenantId,
            $data['warehouse_id'], $data['contact_id'], $data['lines']
        );

        return response()->json($purchase->load('lines'), 201);
    }

    public function receive(Request $request, Purchase $purchase, PurchaseService $service)
    {
        $data = $request->validate([
            'lines' => 'sometimes|array',
            'lines.*.product_variant_id' => 'required_with:lines|integer',
            'lines.*.quantity' => 'required_with:lines|numeric|min:0.001',
        ]);

        return response()->json($service->receive(
            $purchase->id, $data['lines'] ?? null, TenantContext::idOrFail(), $request->user()?->id
        )->load(['lines', 'goodsReceipts.lines']));
    }
}
