<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\SalesInvoice;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

final class ApprovalService
{
    public function requiresApproval(int $tenantId, float $amount): bool
    {
        $threshold = (float) SystemSetting::scalar($tenantId, 'approval_threshold', 0);

        return $threshold > 0 && $amount >= $threshold;
    }

    public function requestForSale(SalesInvoice $invoice, array $payments, ?int $userId): ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $invoice->tenant_id,
            'subject_type' => 'sales_invoice',
            'subject_id' => $invoice->id,
            'amount' => $invoice->total,
            'status' => 'pending',
            'requested_by' => $userId,
            'metadata' => ['payments' => $payments],
        ]);
    }

    public function approve(ApprovalRequest $approval, int $actorId): ApprovalRequest
    {
        return DB::transaction(function () use ($approval, $actorId) {
            $approval = ApprovalRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($approval->id);
            abort_unless($approval->status === 'pending', 422, 'Approval sudah diproses.');
            abort_unless($approval->subject_type === 'sales_invoice', 422, 'Jenis approval belum didukung.');
            $invoice = SalesInvoice::withoutGlobalScopes()->with('lines')->lockForUpdate()->findOrFail($approval->subject_id);
            foreach ($invoice->lines as $line) {
                app(StockService::class)->decrease($invoice->tenant_id, $invoice->warehouse_id, $line->product_variant_id, (float) $line->quantity, 'sale', $invoice->id);
            }
            $payments = $approval->metadata['payments'] ?? [];
            foreach ($payments as $payment) {
                $invoice->payments()->create([
                    'tenant_id' => $invoice->tenant_id, 'method' => $payment['method'],
                    'amount' => $payment['amount'], 'reference' => $payment['reference'] ?? null,
                ]);
            }
            $paid = collect($payments)->sum('amount');
            $invoice->update(['status' => 'final', 'fulfillment_status' => 'fulfilled', 'payment_status' => $paid >= (float) $invoice->total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid')]);
            $approval->update(['status' => 'approved', 'decided_by' => $actorId, 'decided_at' => now()]);
            app(AuditService::class)->log($invoice->tenant_id, $actorId, 'transaction.approved', SalesInvoice::class, $invoice->id, null, ['amount' => $invoice->total]);

            return $approval->refresh();
        });
    }

    public function reject(ApprovalRequest $approval, int $actorId, string $reason): ApprovalRequest
    {
        abort_unless($approval->status === 'pending', 422, 'Approval sudah diproses.');
        SalesInvoice::withoutGlobalScopes()->whereKey($approval->subject_id)->where('status', 'pending_approval')->update(['status' => 'void', 'fulfillment_status' => 'cancelled']);
        $approval->update(['status' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now(), 'reason' => $reason]);
        app(AuditService::class)->log($approval->tenant_id, $actorId, 'transaction.rejected', SalesInvoice::class, $approval->subject_id, null, ['reason' => $reason]);

        return $approval->refresh();
    }
}
