<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;

class OrderController extends Controller
{
    public function index()
    {
        return view('portal.orders.index', ['orders' => $this->owned()->latest()->paginate(15)]);
    }

    public function show(int $invoice)
    {
        return view('portal.orders.show', ['invoice' => $this->owned()->with(['lines.variant.product', 'payments', 'paymentProofs'])->findOrFail($invoice)]);
    }

    private function owned()
    {
        return SalesInvoice::query()->where('contact_id', auth('customer')->user()->contact_id);
    }
}
