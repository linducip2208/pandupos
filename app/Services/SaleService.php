<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Atomic checkout: sale + payments + stock mutation. Idempotent via idempotency_key. */
final class SaleService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array  $lines  [['variant_id'=>int,'quantity'=>float,'unit_price'=>float,'discount'=>float]]
     * @param  array  $payments  [['method'=>string,'amount'=>float,'reference'=>?string]]
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

            // Validate tenant ownership of all references (prevent IDOR).
            abort_unless(Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $branchId)->exists(), 422, 'Branch does not belong to tenant.');
            abort_unless(Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $warehouseId)->exists(), 422, 'Warehouse does not belong to tenant.');
            if ($contactId) {
                abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $contactId)->exists(), 422, 'Customer does not belong to tenant.');
            }
            foreach ($lines as $l) {
                abort_unless(ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $l['variant_id'])->exists(), 422, 'Variant does not belong to tenant.');
                if (($l['quantity'] ?? 0) <= 0) {
                    abort(422, 'Line quantity must be greater than zero.');
                }
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
            $invoice = SalesInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->status === 'void') {
                return;
            }
            // Completed sales are never hard-deleted; reversal via stock movement + audit.
            foreach ($invoice->lines as $line) {
                $origCost = StockMovement::withoutGlobalScopes()
                    ->where('tenant_id', $invoice->tenant_id)
                    ->where('reference_type', 'sale')->where('reference_id', $invoice->id)
                    ->where('product_variant_id', $line->product_variant_id)
                    ->where('movement_type', 'out')->value('unit_cost') ?? 0;
                $this->stock->increase(
                    $invoice->tenant_id, $invoice->warehouse_id,
                    $line->product_variant_id, (float) $line->quantity, (float) $origCost, 'sale_void', $invoice->id
                );
            }
            $invoice->update(['status' => 'void']);
        });
    }

    public function return(int $invoiceId, array $returnLines): void
    {
        DB::transaction(function () use ($invoiceId, $returnLines) {
            $invoice = SalesInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoiceId);
            abort_if($invoice->status === 'void', 422, 'Cannot return a voided sale.');
            foreach ($returnLines as $rl) {
                $origCost = StockMovement::withoutGlobalScopes()
                    ->where('tenant_id', $invoice->tenant_id)
                    ->where('reference_type', 'sale')->where('reference_id', $invoice->id)
                    ->where('product_variant_id', $rl['variant_id'])
                    ->where('movement_type', 'out')->value('unit_cost') ?? 0;
                $this->stock->increase(
                    $invoice->tenant_id, $invoice->warehouse_id,
                    $rl['variant_id'], (float) $rl['quantity'], (float) $origCost, 'sale_return', $invoice->id
                );
            }
            SalesReturn::withoutGlobalScopes()->create([
                'tenant_id' => $invoice->tenant_id, 'sales_invoice_id' => $invoice->id,
                'total' => collect($returnLines)->sum(fn ($r) => $r['quantity'] * ($r['unit_price'] ?? 0)),
            ]);
        });
    }
}
