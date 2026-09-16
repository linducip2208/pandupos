<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\BillingTransaction;
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

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
