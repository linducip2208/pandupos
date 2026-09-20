<?php

namespace App\Http\Controllers;

use App\Models\SalesInvoice;
use App\Models\ZatcaDocument;
use App\Services\ZatcaService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ZatcaWorkspaceController extends Controller
{
    public function index(ZatcaService $zatca)
    {
        $this->authorize('viewAny', ZatcaDocument::class);
        $docs = ZatcaDocument::query()->orderByDesc('id')->limit(100)->get();
        $qr = [];
        foreach ($docs as $doc) {
            $qr[$doc->id] = $zatca->qrSvg($doc->qr_tlv, 120);
        }

        return view('zatca.index', [
            'docs' => $docs, 'qr' => $qr,
            'invoices' => SalesInvoice::query()->where('status', 'final')->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function generate(Request $request, ZatcaService $zatca)
    {
        $this->authorize('create', ZatcaDocument::class);
        $data = $request->validate([
            'sales_invoice_id' => 'required|integer',
            'seller_name' => 'required|string|max:255',
            'seller_vat' => 'required|string|max:32',
        ]);
        $zatca->generateFromSalesInvoice(TenantContext::idOrFail(), (int) $data['sales_invoice_id'], ['name' => $data['seller_name'], 'vat_number' => $data['seller_vat']], $request->user()->id);

        return back()->with('status', 'Dokumen ZATCA dibuat (QR + XML + hash).');
    }

    public function issueNote(Request $request, ZatcaService $zatca)
    {
        $this->authorize('create', ZatcaDocument::class);
        $data = $request->validate([
            'references_document_id' => 'required|integer',
            'type' => 'required|in:credit_note,debit_note',
            'total' => 'required|numeric|gt:0', 'vat_total' => 'required|numeric|min:0',
        ]);
        $zatca->issueNote(TenantContext::idOrFail(), (int) $data['references_document_id'], $data['type'], (float) $data['total'], (float) $data['vat_total'], $request->user()->id);

        return back()->with('status', 'Nota '.$data['type'].' diterbitkan.');
    }

    public function markReported(Request $request, ZatcaService $zatca, int $document)
    {
        $model = ZatcaDocument::query()->findOrFail($document);
        $this->authorize('manage', $model);
        $data = $request->validate(['clearance_id' => 'required|string|max:64']);
        $zatca->markReported($model, $data['clearance_id'], $request->user()->id);

        return back()->with('status', 'Pelaporan tercatat.');
    }
}
