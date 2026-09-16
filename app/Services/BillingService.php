<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingTransaction;
use App\Models\PaymentWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** SaaS billing separated from POS payments. Webhook idempotent via gateway_ref unique. */
final class BillingService
{
    public function createInvoice(int $tenantId, ?int $subscriptionId, float $subtotal, float $discount = 0): BillingInvoice
    {
        return BillingInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscriptionId,
            'invoice_no' => 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => max(0, $subtotal - $discount),
            'status' => 'issued',
            'issued_at' => now(),
        ]);
    }

    public function recordAttempt(int $tenantId, ?int $invoiceId, string $gateway, ?string $gatewayRef, float $amount): BillingTransaction
    {
        return BillingTransaction::withoutGlobalScopes()->updateOrCreate(
            ['gateway' => $gateway, 'gateway_ref' => $gatewayRef ?? Str::uuid()->toString()],
            ['tenant_id' => $tenantId, 'billing_invoice_id' => $invoiceId, 'amount' => $amount, 'status' => 'pending']
        );
    }

    /** Idempotent webhook handler: same gateway_ref never double-applies. */
    public function handleWebhook(string $gateway, string $gatewayRef, array $payload, string $status): BillingTransaction
    {
        return DB::transaction(function () use ($gateway, $gatewayRef, $payload, $status) {
            PaymentWebhook::updateOrCreate(
                ['gateway' => $gateway, 'gateway_ref' => $gatewayRef],
                ['payload' => $payload, 'status' => 'processed']
            );

            $tx = BillingTransaction::withoutGlobalScopes()
                ->where('gateway', $gateway)->where('gateway_ref', $gatewayRef)->first();

            if (! $tx) {
                $tx = BillingTransaction::withoutGlobalScopes()->create([
                    'tenant_id' => $payload['tenant_id'] ?? null,
                    'billing_invoice_id' => $payload['invoice_id'] ?? null,
                    'gateway' => $gateway, 'gateway_ref' => $gatewayRef,
                    'amount' => $payload['amount'] ?? 0,
                    'status' => $status, 'metadata' => $payload,
                ]);
            } elseif ($tx->status === 'pending') {
                $tx->update(['status' => $status, 'metadata' => $payload]);
            }

            if ($status === 'success' && $tx->billing_invoice_id) {
                BillingInvoice::withoutGlobalScopes()->where('id', $tx->billing_invoice_id)
                    ->update(['status' => 'paid', 'paid_at' => now()]);
            }

            return $tx;
        });
    }
}
