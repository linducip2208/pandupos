<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $customer = auth('customer')->user();
        $invoices = SalesInvoice::query()->where('contact_id', $customer->contact_id);

        return view('portal.dashboard', [
            'customer' => $customer->load('contact'),
            'activeOrders' => (clone $invoices)->whereNotIn('status', ['void'])->whereNotIn('fulfillment_status', ['completed', 'cancelled'])->count(),
            'totalSpent' => (float) (clone $invoices)->where('status', 'final')->sum('total'),
            'outstanding' => (float) (clone $invoices)->whereIn('payment_status', ['unpaid', 'partial'])->sum('total'),
            'recentInvoices' => $invoices->latest()->limit(5)->get(),
        ]);
    }
}
