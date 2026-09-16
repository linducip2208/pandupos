<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Services\SaleService;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            SalesInvoice::with(['lines', 'payments'])->orderByDesc('id')->paginate($request->get('per_page', 15))
        );
    }

    public function store(Request $request, SaleService $service)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'contact_id' => 'nullable|exists:contacts,id',
            'lines' => 'required|array|min:1',
            'lines.*.variant_id' => 'required|exists:product_variants,id',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|in:cash,transfer,qris,ewallet,card',
            'payments.*.amount' => 'required|numeric|min:0',
        ]);

        $key = $request->header('Idempotency-Key', (string) \Str::uuid());

        $invoice = $service->checkout(
            \App\Support\TenantContext::id(),
            $data['branch_id'], $data['warehouse_id'], $data['contact_id'] ?? null,
            $data['lines'], $data['payments'], $key
        );

        return response()->json($invoice->load(['lines', 'payments']), 201);
    }

    public function void(SalesInvoice $invoice, SaleService $service, Request $request)
    {
        $service->void($invoice->id, $request->user()->can('pos.sale.void'));

        return response()->json(['ok' => true]);
    }
}
