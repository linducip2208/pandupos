<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierDocumentService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function createInvoice(int $tenantId, array $data, ?int $actorId = null): SupplierInvoice
    {
        $purchase = null;
        if (! empty($data['purchase_id'])) {
            $purchase = Purchase::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($data['purchase_id']);
        }
        $supplierId = (int) ($data['supplier_id'] ?? $purchase?->contact_id);
        abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($supplierId)->whereIn('type', ['supplier', 'both'])->exists(), 422, 'Supplier does not belong to tenant.');
        if ($purchase && $purchase->contact_id !== $supplierId) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier must match the purchase order.']);
        }

        $subtotal = round((float) $data['subtotal'], 2);
        $discount = round((float) ($data['discount'] ?? 0), 2);
        $tax = round((float) ($data['tax'] ?? 0), 2);
        $shipping = round((float) ($data['shipping'] ?? 0), 2);
        $total = round($subtotal - $discount + $tax + $shipping, 2);
        if ($subtotal < 0 || $discount < 0 || $tax < 0 || $shipping < 0 || $total < 0) {
            throw ValidationException::withMessages(['total' => 'Invoice money values must produce a non-negative total.']);
        }

        return DB::transaction(function () use ($tenantId, $data, $purchase, $supplierId, $subtotal, $discount, $tax, $shipping, $total, $actorId) {
            if (SupplierInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $supplierId)
                ->where('invoice_number', $data['invoice_number'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'invoice_number' => 'Supplier invoice number already exists for this supplier.',
                ]);
            }
            $invoice = SupplierInvoice::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'purchase_id' => $purchase?->id, 'supplier_id' => $supplierId,
                'invoice_number' => $data['invoice_number'], 'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null, 'subtotal' => $subtotal, 'discount' => $discount,
                'tax' => $tax, 'shipping' => $shipping, 'total' => $total, 'paid' => 0,
                'balance' => $total, 'status' => $total == 0.0 ? 'paid' : 'posted',
            ]);
            $this->audit->log($tenantId, $actorId, 'purchase.supplier_invoice.created', SupplierInvoice::class, $invoice->id, null, $invoice->toArray());

            return $invoice;
        });
    }

    public function pay(SupplierInvoice $invoice, float $amount, string $method, ?string $reference, int $actorId): SupplierPayment
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $actorId) {
            $locked = SupplierInvoice::withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->lockForUpdate()->findOrFail($invoice->id);
            $amount = round($amount, 2);
            if ($amount <= 0 || $amount > (float) $locked->balance) {
                throw ValidationException::withMessages(['amount' => 'Payment must be positive and cannot exceed invoice balance.']);
            }
            $payment = SupplierPayment::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id, 'supplier_invoice_id' => $locked->id,
                'paid_at' => now(), 'amount' => $amount, 'method' => $method,
                'reference' => $reference, 'created_by' => $actorId,
            ]);
            $paid = round((float) $locked->paid + $amount, 2);
            $balance = round((float) $locked->total - $paid, 2);
            $locked->update(['paid' => $paid, 'balance' => $balance, 'status' => $balance == 0.0 ? 'paid' : 'partial']);
            $this->audit->log($locked->tenant_id, $actorId, 'purchase.supplier_payment.created', SupplierPayment::class, $payment->id, null, $payment->toArray());

            return $payment;
        });
    }

    public function createReturn(Purchase $purchase, array $lines, string $reason, string $settlementType, int $actorId): PurchaseReturn
    {
        if ($lines === [] || trim($reason) === '' || ! in_array($settlementType, ['supplier_credit', 'cash_refund', 'replacement'], true)) {
            throw ValidationException::withMessages(['return' => 'Lines, reason, and a valid settlement type are required.']);
        }

        return DB::transaction(function () use ($purchase, $lines, $reason, $settlementType, $actorId) {
            $locked = Purchase::withoutGlobalScopes()->where('tenant_id', $purchase->tenant_id)->lockForUpdate()->findOrFail($purchase->id);
            $prepared = [];
            foreach ($lines as $row) {
                $line = PurchaseLine::where('purchase_id', $locked->id)->lockForUpdate()->findOrFail($row['purchase_line_id']);
                $quantity = round((float) $row['quantity'], 3);
                $prior = (float) DB::table('purchase_return_lines')
                    ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
                    ->where('purchase_returns.status', 'posted')->where('purchase_return_lines.purchase_line_id', $line->id)
                    ->sum('purchase_return_lines.quantity');
                $remaining = round((float) $line->received_quantity - $prior, 3);
                if ($quantity <= 0 || $quantity > $remaining) {
                    throw ValidationException::withMessages(['lines' => "Return quantity exceeds received-minus-prior-return quantity ({$remaining})."]);
                }
                $prepared[] = [$line, $quantity];
            }

            $return = PurchaseReturn::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id, 'purchase_id' => $locked->id,
                'return_no' => 'TMP-'.(string) Str::uuid(), 'status' => 'posted',
                'total' => collect($prepared)->sum(fn ($row) => $row[1] * (float) $row[0]->unit_cost),
                'settlement_type' => $settlementType, 'reason' => $reason,
                'created_by' => $actorId, 'posted_at' => now(),
            ]);
            $return->update(['return_no' => 'PR-'.str_pad((string) $return->id, 8, '0', STR_PAD_LEFT)]);
            foreach ($prepared as [$line, $quantity]) {
                $return->lines()->create([
                    'purchase_line_id' => $line->id, 'product_variant_id' => $line->product_variant_id,
                    'quantity' => $quantity, 'unit_cost' => $line->unit_cost,
                    'line_total' => round($quantity * (float) $line->unit_cost, 2),
                ]);
                $this->stock->decrease($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, $quantity, 'purchase_return', $return->id);
            }
            $this->audit->log($locked->tenant_id, $actorId, 'purchase.return.posted', PurchaseReturn::class, $return->id, null, $return->fresh('lines')->toArray());

            return $return->fresh('lines');
        });
    }
}
