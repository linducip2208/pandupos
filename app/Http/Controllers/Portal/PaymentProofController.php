<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PaymentProof;
use App\Models\SalesInvoice;
use Illuminate\Http\Request;

class PaymentProofController extends Controller
{
    public function store(Request $request, int $invoice)
    {
        $customer = auth('customer')->user();
        $invoice = SalesInvoice::query()->where('contact_id', $customer->contact_id)->findOrFail($invoice);
        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $file = $validated['proof'];
        $path = $file->store("portal-payment-proofs/{$customer->tenant_id}");

        PaymentProof::create([
            'tenant_id' => $customer->tenant_id,
            'customer_login_id' => $customer->id,
            'sales_invoice_id' => $invoice->id,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'uploaded_at' => now(),
        ]);

        return back()->with('status', 'Bukti pembayaran berhasil dikirim untuk verifikasi.');
    }
}
