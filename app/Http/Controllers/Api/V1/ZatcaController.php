<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ZatcaDocument;
use App\Services\ZatcaService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ZatcaController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', ZatcaDocument::class);

        return response()->json(['data' => ZatcaDocument::query()->orderByDesc('id')->limit(100)->get()]);
    }

    public function generate(Request $request, ZatcaService $zatca)
    {
        $this->authorize('create', ZatcaDocument::class);
        $data = $request->validate([
            'sales_invoice_id' => 'required|integer',
            'seller_name' => 'required|string|max:255',
            'seller_vat' => 'required|string|max:32',
        ]);

        return response()->json(['data' => $zatca->generateFromSalesInvoice(TenantContext::idOrFail(), (int) $data['sales_invoice_id'], ['name' => $data['seller_name'], 'vat_number' => $data['seller_vat']], $request->user()->id)], 201);
    }

    public function show(ZatcaService $zatca, int $document)
    {
        $model = ZatcaDocument::query()->findOrFail($document);
        $this->authorize('viewAny', ZatcaDocument::class);

        return response()->json(['data' => array_merge($model->toArray(), ['qr_svg' => $zatca->qrSvg($model->qr_tlv)])]);
    }

    public function markReported(Request $request, ZatcaService $zatca, int $document)
    {
        $model = ZatcaDocument::query()->findOrFail($document);
        $this->authorize('manage', $model);
        $data = $request->validate(['clearance_id' => 'required|string|max:64']);

        return response()->json(['data' => $zatca->markReported($model, $data['clearance_id'], $request->user()->id)]);
    }
}
