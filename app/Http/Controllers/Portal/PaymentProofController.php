<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PaymentProof;
use App\Models\SalesInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaymentProofController extends Controller
{
    public function store(Request $request, int $invoice)
    {
        $customer = auth('customer')->user();
        $invoice = SalesInvoice::query()->where('contact_id', $customer->contact_id)->findOrFail($invoice);
        $validated = $request->validate([
            // Extension allowlist + content sniffing + size cap. Stored on the
            // private disk under a tenant-scoped prefix with a hashed filename.
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
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

    /** Owner-only download from private storage with correct MIME and nosniff. */
    public function download(int $invoice, int $proof)
    {
        $customer = auth('customer')->user();
        $invoice = SalesInvoice::query()->where('contact_id', $customer->contact_id)->findOrFail($invoice);
        $record = PaymentProof::query()
            ->where('sales_invoice_id', $invoice->id)
            ->where('tenant_id', $customer->tenant_id)
            ->findOrFail($proof);
        abort_unless(Storage::disk('local')->exists($record->path), 404);

        return Storage::disk('local')->download($record->path, $record->original_name, [
            'Content-Type' => $record->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
