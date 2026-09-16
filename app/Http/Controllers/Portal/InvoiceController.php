<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use Dompdf\Dompdf;
use Dompdf\Options;

class InvoiceController extends Controller
{
    public function index()
    {
        return view('portal.invoices.index', ['invoices' => $this->owned()->latest()->paginate(15)]);
    }

    public function show(int $invoice)
    {
        return view('portal.invoices.show', ['invoice' => $this->findOwned($invoice)]);
    }

    public function pdf(int $invoice)
    {
        $invoice = $this->findOwned($invoice);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('portal.invoices.pdf', compact('invoice'))->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-'.$invoice->id.'.pdf"',
        ]);
    }

    private function owned()
    {
        return SalesInvoice::query()->where('contact_id', auth('customer')->user()->contact_id);
    }

    private function findOwned(int $invoice): SalesInvoice
    {
        return $this->owned()->with(['contact', 'lines.variant.product', 'payments', 'paymentProofs'])->findOrFail($invoice);
    }
}
