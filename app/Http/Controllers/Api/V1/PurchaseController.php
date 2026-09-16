<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

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
        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'contact_id' => 'required|exists:contacts,id',
            'lines' => 'required|array|min:1',
            'lines.*.product_variant_id' => 'required|exists:product_variants,id',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
        ]);

        $purchase = $service->createDraft(
            TenantContext::id(),
            $data['warehouse_id'], $data['contact_id'], $data['lines']
        );

        return response()->json($purchase->load('lines'), 201);
    }

    public function receive(Purchase $purchase, PurchaseService $service)
    {
        return response()->json($service->receive($purchase->id)->load('lines'));
    }
}
