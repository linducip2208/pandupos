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
    /**
     * Create an issued invoice with immutable money invariant: total = subtotal - discount + tax.
     * Line items are persisted so invoices are auditable and printable.
     *
     * @param  array  $items  [['description'=>string,'quantity'=>int,'unit_price'=>float]]
     */
    public function createInvoice(int $tenantId, ?int $subscriptionId, float $subtotal, float $discount = 0, array $items = [], float $tax = 0, string $currency = 'IDR'): BillingInvoice
    {
        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);
        $tax = round($tax, 2);
        $currency = strtoupper(trim($currency) ?: 'IDR');
        abort_if($subtotal < 0 || $discount < 0 || $discount > $subtotal || $tax < 0, 422, 'Billing figures are invalid.');
        $total = round($subtotal - $discount + $tax, 2);

        return DB::transaction(function () use ($tenantId, $subscriptionId, $subtotal, $discount, $tax, $total, $currency, $items) {
            $invoice = BillingInvoice::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'subscription_id' => $subscriptionId,
                'invoice_no' => 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'currency' => $currency,
                'status' => 'issued',
                'issued_at' => now(),
            ]);

            foreach ($items as $item) {
                $qty = (int) ($item['quantity'] ?? 1);
                $price = round((float) ($item['unit_price'] ?? 0), 2);
                abort_if($qty < 0 || $price < 0, 422, 'Billing line item figures are invalid.');
                $invoice->items()->create([
                    'billing_invoice_id' => $invoice->id,
                    'description' => (string) ($item['description'] ?? ''),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'amount' => round($qty * $price, 2),
                ]);
            }

            return $invoice;
        });
    }

    public function recordAttempt(int $tenantId, ?int $invoiceId, string $gateway, ?string $gatewayRef, float $amount): BillingTransaction
    {
        return BillingTransaction::withoutGlobalScopes()->updateOrCreate(
            ['gateway' => $gateway, 'gateway_ref' => $gatewayRef ?? Str::uuid()->toString()],
            ['tenant_id' => $tenantId, 'billing_invoice_id' => $invoiceId, 'amount' => $amount, 'status' => 'pending']
        );
    }

    /** Void only a draft or issued invoice; paid invoices are immutable (straight refund path). */
    public function voidInvoice(int $invoiceId, ?int $tenantId = null): BillingInvoice
    {
        return DB::transaction(function () use ($invoiceId, $tenantId) {
            $q = BillingInvoice::withoutGlobalScopes()->lockForUpdate();
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
            $invoice = $q->findOrFail($invoiceId);
            abort_if($invoice->status === 'paid' || $invoice->status === 'void', 422, 'Paid or already-voided invoices cannot be voided.');
            $invoice->update(['status' => 'void']);

            return $invoice;
        });
    }

    /** Idempotent mark-paid used by webhooks and reconciliation. */
    public function markPaid(int $invoiceId): void
    {
        BillingInvoice::withoutGlobalScopes()->where('id', $invoiceId)
            ->whereIn('status', ['draft', 'issued'])
            ->update(['status' => 'paid', 'paid_at' => now()]);
    }

    /**
     * Reconcile issued invoices against successful payment transactions.
     * An invoice whose successful attempts reach the total is closed as paid;
     * everything else is reported. Never double-charges: paid stays paid.
     */
    public function reconcileAll(): array
    {
        $reconciled = 0;
        $alreadyPaid = 0;
        $issuedIds = BillingInvoice::withoutGlobalScopes()->where('status', 'issued')->pluck('id');

        foreach ($issuedIds as $invoiceId) {
            $invoice = BillingInvoice::withoutGlobalScopes()->find($invoiceId);
            $paidTotal = (float) BillingTransaction::withoutGlobalScopes()
                ->where('billing_invoice_id', $invoiceId)->where('status', 'success')->sum('amount');
            if ((float) $invoice->total <= 0) {
                continue;
            }
            if ($invoice->status === 'paid') {
                $alreadyPaid++;

                continue;
            }
            if ($paidTotal >= (float) $invoice->total) {
                $this->markPaid($invoiceId);
                $reconciled++;
            }
        }

        return ['reconciled' => $reconciled, 'already_paid' => $alreadyPaid];
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
