<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\BillingTransaction;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.billing.view');
        $invoices = BillingInvoice::withoutGlobalScopes()->with('tenant')->orderByDesc('id')->paginate(20);
        $transactions = BillingTransaction::withoutGlobalScopes()->orderByDesc('id')->limit(20)->get();

        return view('platform.billing.index', compact('invoices', 'transactions'));
    }

    public function pdf(Request $request, int $invoice)
    {
        $this->authorizePlatform($request, 'platform.billing.view');
        $invoice = BillingInvoice::withoutGlobalScopes()->with('items', 'tenant', 'subscription.plan')->findOrFail($invoice);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('platform.billing.invoice-pdf', ['invoice' => $invoice])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="billing-invoice-'.$invoice->invoice_no.'.pdf"',
        ]);
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
