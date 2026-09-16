<?php

namespace App\Services;

use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Atomic checkout: sale + payments + stock mutation. Idempotent via idempotency_key. */
final class SaleService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param array $lines [['variant_id'=>int,'quantity'=>float,'unit_price'=>float,'discount'=>float]]
     * @param array $payments [['method'=>string,'amount'=>float,'reference'=>?string]]
     */
    public function checkout(
        int $tenantId, int $branchId, int $warehouseId, ?int $contactId,
        array $lines, array $payments, string $idempotencyKey,
    ): SalesInvoice {
        return DB::transaction(function () use ($tenantId, $branchId, $warehouseId, $contactId, $lines, $payments, $idempotencyKey) {
            // Idempotency: same key returns existing invoice, never duplicates.
            $existing = SalesInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $subtotal = collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_price'] - ($l['discount'] ?? 0));
            $paid = collect($payments)->sum(fn ($p) => $p['amount']);

            if (abs($paid - $subtotal) > 0.01) {
                abort(422, "Split payment total ({$paid}) must equal invoice total ({$subtotal}).");
            }

            $invoice = SalesInvoice::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'warehouse_id' => $warehouseId,
                'contact_id' => $contactId, 'invoice_no' => 'S-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'status' => 'final',
                'payment_status' => $paid > 0 ? 'paid' : 'unpaid',
                'subtotal' => $subtotal, 'total' => $subtotal,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($lines as $l) {
                $invoice->lines()->create([
                    'product_variant_id' => $l['variant_id'], 'quantity' => $l['quantity'],
                    'unit_price' => $l['unit_price'], 'discount' => $l['discount'] ?? 0,
                ]);
                $this->stock->decrease($tenantId, $warehouseId, $l['variant_id'], (float) $l['quantity'], 'sale', $invoice->id);
            }

            foreach ($payments as $p) {
                $invoice->payments()->create([
                    'tenant_id' => $tenantId, 'method' => $p['method'],
                    'amount' => $p['amount'], 'reference' => $p['reference'] ?? null,
                ]);
            }

            return $invoice;
        });
    }

    public function void(int $invoiceId, bool $canVoid): void
    {
        abort_unless($canVoid, 403, 'Unauthorized to void sale.');

        DB::transaction(function () use ($invoiceId) {
            $invoice = SalesInvoice::withoutGlobalScopes()->findOrFail($invoiceId);
            if ($invoice->status === 'void') {
                return;
            }
            foreach ($invoice->lines as $line) {
                $this->stock->increase(
                    $invoice->tenant_id, $invoice->warehouse_id,
                    $line->product_variant_id, (float) $line->quantity, 0, 'sale_void', $invoice->id
                );
            }
            $invoice->update(['status' => 'void']);
        });
    }

    public function return(int $invoiceId, array $returnLines): void
    {
        DB::transaction(function () use ($invoiceId, $returnLines) {
            $invoice = SalesInvoice::withoutGlobalScopes()->findOrFail($invoiceId);
            foreach ($returnLines as $rl) {
                $this->stock->increase(
                    $invoice->tenant_id, $invoice->warehouse_id,
                    $rl['variant_id'], (float) $rl['quantity'], 0, 'sale_return', $invoice->id
                );
            }
            \App\Models\SalesReturn::withoutGlobalScopes()->create([
                'tenant_id' => $invoice->tenant_id, 'sales_invoice_id' => $invoice->id,
                'total' => collect($returnLines)->sum(fn ($r) => $r['quantity'] * ($r['unit_price'] ?? 0)),
            ]);
        });
    }
}
