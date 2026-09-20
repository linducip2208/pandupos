<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryBatch;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\SerialNumber;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\WarehouseLocation;
use Illuminate\Database\QueryException;
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
            $this->postInvoiceToAccounting($tenantId, $invoice, $actorId);

            return $invoice;
        });
    }

    public function pay(SupplierInvoice $invoice, float $amount, string $method, ?string $reference, int $actorId): SupplierPayment
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $actorId) {
            $locked = SupplierInvoice::withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->lockForUpdate()->findOrFail($invoice->id);
            $amount = round($amount, 2);
            $reference = filled($reference) ? trim($reference) : null;
            if ($amount <= 0 || $amount > (float) $locked->balance) {
                throw ValidationException::withMessages(['amount' => 'Payment must be positive and cannot exceed invoice balance.']);
            }
            if ($reference !== null && SupplierPayment::withoutGlobalScopes()
                ->where('tenant_id', $locked->tenant_id)
                ->where('reference', $reference)
                ->exists()) {
                throw ValidationException::withMessages([
                    'reference' => 'Payment reference has already been recorded for this tenant.',
                ]);
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
            $this->postPaymentToAccounting($locked->tenant_id, $payment, $actorId);

            return $payment;
        });
    }

    /**
     * Staged purchase-return lifecycle: draft → reviewed → approved → posted (immutable).
     * The draft path enforces requester/approver segregation; the legacy
     * createReturn() fast-track remains for single privileged postings.
     */
    /** Post AP invoice/payment inside the originating transaction when accounting is enabled. */
    private function postInvoiceToAccounting(int $tenantId, SupplierInvoice $invoice, ?int $actorId): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($tenantId, 'accounting')) {
            return;
        }
        app(AccountingService::class)->postSupplierInvoice($invoice, $actorId);
    }

    private function postPaymentToAccounting(int $tenantId, SupplierPayment $payment, int $actorId): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($tenantId, 'accounting')) {
            return;
        }
        app(AccountingService::class)->recordSupplierPayment($payment, $actorId);
    }

    public function createDraft(
        Purchase $purchase,
        array $lines,
        string $reason,
        string $settlementType,
        int $actorId,
        ?string $idempotencyKey = null,
        float $tax = 0,
        float $discount = 0,
    ): PurchaseReturn {
        if ($lines === [] || trim($reason) === '' || ! in_array($settlementType, ['supplier_credit', 'cash_refund', 'replacement'], true)) {
            throw ValidationException::withMessages(['return' => 'Lines, reason, and a valid settlement type are required.']);
        }
        if ($tax < 0 || $discount < 0) {
            throw ValidationException::withMessages(['total' => 'Tax and discount must be non-negative.']);
        }
        $idempotencyKey = filled($idempotencyKey) ? trim($idempotencyKey) : null;

        try {
            return DB::transaction(function () use ($purchase, $lines, $reason, $settlementType, $actorId, $idempotencyKey, $tax, $discount) {
                if ($idempotencyKey !== null) {
                    $existing = PurchaseReturn::withoutGlobalScopes()
                        ->where('tenant_id', $purchase->tenant_id)->where('idempotency_key', $idempotencyKey)->first();
                    if ($existing) {
                        return $existing->load('lines');
                    }
                }
                $locked = Purchase::withoutGlobalScopes()->where('tenant_id', $purchase->tenant_id)->lockForUpdate()->findOrFail($purchase->id);
                abort_unless(in_array($locked->status, ['partial', 'received'], true), 422, 'Only received purchases can be returned.');
                $prepared = $this->prepareReturnLines($locked, $lines);

                $subtotal = round(collect($prepared)->sum(fn ($row) => $row['quantity'] * (float) $row['line']->unit_cost), 2);
                abort_if($discount > $subtotal, 422, 'Return discount cannot exceed subtotal.');
                $total = round($subtotal - $discount + $tax, 2);

                $return = PurchaseReturn::withoutGlobalScopes()->create([
                    'tenant_id' => $locked->tenant_id, 'purchase_id' => $locked->id,
                    'supplier_id' => $locked->contact_id, 'warehouse_id' => $locked->warehouse_id,
                    'return_no' => 'TMP-'.(string) Str::uuid(), 'idempotency_key' => $idempotencyKey,
                    'status' => 'draft', 'subtotal' => $subtotal, 'tax' => round($tax, 2), 'discount' => round($discount, 2),
                    'total' => $total, 'settlement_type' => $settlementType, 'reason' => trim($reason),
                    'created_by' => $actorId, 'requested_by' => $actorId,
                ]);
                $return->update(['return_no' => 'PR-'.str_pad((string) $return->id, 8, '0', STR_PAD_LEFT)]);
                foreach ($prepared as $row) {
                    $return->lines()->create([
                        'purchase_line_id' => $row['line']->id, 'product_variant_id' => $row['line']->product_variant_id,
                        'inventory_batch_id' => $row['batchId'], 'warehouse_location_id' => $row['locationId'],
                        'serial_number_id' => $row['serialId'],
                        'quantity' => $row['quantity'], 'unit_cost' => $row['line']->unit_cost,
                        'line_total' => round($row['quantity'] * (float) $row['line']->unit_cost, 2),
                    ]);
                }
                $this->audit->log($locked->tenant_id, $actorId, 'purchase.return.drafted', PurchaseReturn::class, $return->id, null, $return->fresh('lines')->toArray());

                return $return->fresh('lines');
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && $idempotencyKey !== null) {
                $existing = PurchaseReturn::withoutGlobalScopes()
                    ->where('tenant_id', $purchase->tenant_id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing->load('lines');
                }
            }
            throw $e;
        }
    }

    public function submitReturn(PurchaseReturn $purchaseReturn, int $actorId): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn, $actorId) {
            $locked = $this->lockReturn($purchaseReturn);
            $this->expectReturnStatus($locked, 'draft');
            if ($locked->requested_by !== null && $locked->requested_by !== $actorId) {
                throw ValidationException::withMessages(['review' => 'Only the return requester can submit it for review.']);
            }
            $before = $locked->load('lines')->toArray();
            $locked->update(['status' => 'reviewed', 'reviewed_by' => $actorId, 'reviewed_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'purchase.return.reviewed', PurchaseReturn::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    public function approveReturn(PurchaseReturn $purchaseReturn, int $actorId): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn, $actorId) {
            $locked = $this->lockReturn($purchaseReturn);
            $this->expectReturnStatus($locked, 'reviewed');
            if ($locked->requested_by !== null && $locked->requested_by === $actorId) {
                throw ValidationException::withMessages(['approval' => 'The requester cannot approve their own purchase return.']);
            }
            $before = $locked->load('lines')->toArray();
            $locked->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'purchase.return.approved', PurchaseReturn::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    public function postReturn(PurchaseReturn $purchaseReturn, int $actorId): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn, $actorId) {
            $locked = $this->lockReturn($purchaseReturn);
            $this->expectReturnStatus($locked, 'approved');
            $purchase = Purchase::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->lockForUpdate()->findOrFail($locked->purchase_id);
            abort_unless((int) $purchase->contact_id === (int) $locked->supplier_id, 422, 'Return supplier must match the purchase order.');
            abort_unless((int) $purchase->warehouse_id === (int) $locked->warehouse_id, 422, 'Return warehouse must match the purchase order.');
            // Re-validate remaining quantities at post time to prevent double-return races.
            $this->assertPostableQuantities($purchase, $locked);

            foreach ($locked->lines as $returnLine) {
                $purchaseLine = PurchaseLine::where('purchase_id', $purchase->id)->lockForUpdate()->findOrFail($returnLine->purchase_line_id);
                $quantity = (float) $returnLine->quantity;
                $this->validateReturnTrace($purchase, $purchaseLine, $returnLine->inventory_batch_id, $returnLine->warehouse_location_id, $quantity);
                if ($returnLine->serial_number_id !== null) {
                    $this->returnSerialToSupplier($locked, $returnLine->serial_number_id, $purchaseLine);
                }
                $this->stock->decrease(
                    $locked->tenant_id, $locked->warehouse_id, $returnLine->product_variant_id, $quantity,
                    'purchase_return', $locked->id, $returnLine->inventory_batch_id, $returnLine->serial_number_id,
                    $returnLine->warehouse_location_id, (float) $returnLine->unit_cost
                );
            }
            $before = $locked->toArray();
            $locked->update(['status' => 'posted', 'posted_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'purchase.return.posted', PurchaseReturn::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    /**
     * Privileged single-step posting (holder of purchase.approve).
     * Preserved for backward compatibility and quick corrections; the staged
     * draft/submit/approve/post path above is the segregated v1 workflow.
     */
    public function createReturn(
        Purchase $purchase,
        array $lines,
        string $reason,
        string $settlementType,
        int $actorId,
        ?string $idempotencyKey = null,
        float $tax = 0,
        float $discount = 0,
    ): PurchaseReturn {
        $draft = $this->createDraft($purchase, $lines, $reason, $settlementType, $actorId, $idempotencyKey, $tax, $discount);
        if ($draft->status === 'posted') {
            return $draft;
        }
        // Fast-track: the same privileged actor reviews, approves and posts in
        // one transaction chain, with each transition audited. Segregation is
        // enforced only on the staged path where requester != approver.
        $draft->update(['status' => 'reviewed', 'reviewed_by' => $actorId, 'reviewed_at' => now()]);
        $this->audit->log($draft->tenant_id, $actorId, 'purchase.return.reviewed', PurchaseReturn::class, $draft->id, ['status' => 'draft'], ['status' => 'reviewed']);
        $draft->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
        $this->audit->log($draft->tenant_id, $actorId, 'purchase.return.approved', PurchaseReturn::class, $draft->id, ['status' => 'reviewed'], ['status' => 'approved']);

        return $this->postReturn($draft->fresh(), $actorId);
    }

    private function validateReturnTrace(Purchase $purchase, PurchaseLine $line, ?int $batchId, ?int $locationId, float $quantity): void
    {
        if ($batchId !== null) {
            $batch = InventoryBatch::withoutGlobalScopes()
                ->where('tenant_id', $purchase->tenant_id)->where('warehouse_id', $purchase->warehouse_id)
                ->where('product_variant_id', $line->product_variant_id)->lockForUpdate()->find($batchId);
            if (! $batch || (int) $batch->purchase_id !== (int) $purchase->id
                || ! GoodsReceiptLine::query()->where('purchase_line_id', $line->id)->where('inventory_batch_id', $batchId)->exists()) {
                throw ValidationException::withMessages(['inventory_batch_id' => 'Return batch must originate from this purchase receipt.']);
            }
            if ($this->stock->onHandByBatch($purchase->tenant_id, $purchase->warehouse_id, $line->product_variant_id, $batchId) < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Selected batch has insufficient stock for this return.']);
            }
        }

        if ($locationId !== null) {
            $location = WarehouseLocation::withoutGlobalScopes()->where('tenant_id', $purchase->tenant_id)
                ->where('warehouse_id', $purchase->warehouse_id)->where('is_active', true)->find($locationId);
            if (! $location || ! GoodsReceiptLine::query()->where('purchase_line_id', $line->id)->where('warehouse_location_id', $locationId)->exists()) {
                throw ValidationException::withMessages(['warehouse_location_id' => 'Return rack/bin must originate from this purchase receipt.']);
            }
            if ($this->stock->onHandAtLocation($purchase->tenant_id, $purchase->warehouse_id, $line->product_variant_id, $locationId) < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Selected rack/bin has insufficient stock for this return.']);
            }
        }
    }

    /** Validate all lines up-front and return execution-ready rows. */
    private function prepareReturnLines(Purchase $purchase, array $lines): array
    {
        $prepared = [];
        $requestedByLine = [];
        foreach ($lines as $row) {
            $line = PurchaseLine::where('purchase_id', $purchase->id)->lockForUpdate()->findOrFail($row['purchase_line_id']);
            $quantity = round((float) $row['quantity'], 3);
            $requestedByLine[$line->id] = round(($requestedByLine[$line->id] ?? 0) + $quantity, 3);
            $prior = (float) DB::table('purchase_return_lines')
                ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
                ->where('purchase_returns.status', 'posted')->where('purchase_return_lines.purchase_line_id', $line->id)
                ->sum('purchase_return_lines.quantity');
            $remaining = round((float) $line->received_quantity - $prior, 3);
            if ($quantity <= 0 || $requestedByLine[$line->id] > $remaining) {
                throw ValidationException::withMessages(['lines' => "Return quantity exceeds received-minus-prior-return quantity ({$remaining})."]);
            }
            $batchId = filled($row['inventory_batch_id'] ?? null) ? (int) $row['inventory_batch_id'] : null;
            $locationId = filled($row['warehouse_location_id'] ?? null) ? (int) $row['warehouse_location_id'] : null;
            $serialId = filled($row['serial_number_id'] ?? null) ? (int) $row['serial_number_id'] : null;
            $this->validateReturnTrace($purchase, $line, $batchId, $locationId, $quantity);
            if ($serialId !== null) {
                $this->validateReturnSerial($purchase, $line, $serialId, $batchId, $quantity);
            }
            $prepared[] = ['line' => $line, 'quantity' => $quantity, 'batchId' => $batchId, 'locationId' => $locationId, 'serialId' => $serialId];
        }

        return $prepared;
    }

    private function validateReturnSerial(Purchase $purchase, PurchaseLine $line, int $serialId, ?int $batchId, float $quantity): void
    {
        if (abs($quantity - 1.0) > 0.000001) {
            throw ValidationException::withMessages(['serial_number_id' => 'A serialized return line must return exactly one unit.']);
        }
        $serial = SerialNumber::withoutGlobalScopes()
            ->where('tenant_id', $purchase->tenant_id)->where('warehouse_id', $purchase->warehouse_id)
            ->where('product_variant_id', $line->product_variant_id)->lockForUpdate()->find($serialId);
        if (! $serial || (int) $serial->purchase_id !== (int) $purchase->id
            || ! in_array($serial->status, ['available', 'returned'], true)) {
            throw ValidationException::withMessages(['serial_number_id' => 'Return serial must be an available unit received from this purchase.']);
        }
        if ($batchId !== null && (int) $serial->inventory_batch_id !== $batchId) {
            throw ValidationException::withMessages(['serial_number_id' => 'Return serial does not belong to the selected batch.']);
        }
    }

    private function returnSerialToSupplier(PurchaseReturn $purchaseReturn, int $serialId, PurchaseLine $line): void
    {
        $serial = SerialNumber::withoutGlobalScopes()
            ->where('tenant_id', $purchaseReturn->tenant_id)->where('warehouse_id', $purchaseReturn->warehouse_id)
            ->where('product_variant_id', $line->product_variant_id)->lockForUpdate()->findOrFail($serialId);
        if (! in_array($serial->status, ['available', 'returned'], true) || (int) $serial->purchase_id !== (int) $purchaseReturn->purchase_id) {
            throw ValidationException::withMessages(['serial_number_id' => 'Return serial is no longer available for this purchase return.']);
        }
        $before = $serial->toArray();
        $serial->update(['status' => 'returned_to_supplier', 'sales_invoice_id' => null]);
        $this->audit->log($purchaseReturn->tenant_id, $purchaseReturn->approved_by, 'inventory.serial.purchase_returned', SerialNumber::class, $serial->id, $before, $serial->fresh()->toArray());
    }

    private function lockReturn(PurchaseReturn $purchaseReturn): PurchaseReturn
    {
        return PurchaseReturn::withoutGlobalScopes()->where('tenant_id', $purchaseReturn->tenant_id)->lockForUpdate()->findOrFail($purchaseReturn->id);
    }

    private function expectReturnStatus(PurchaseReturn $purchaseReturn, string $status): void
    {
        if ($purchaseReturn->status !== $status) {
            throw ValidationException::withMessages(['status' => "Purchase return must be {$status} (immutable once posted)."]);
        }
    }

    private function assertPostableQuantities(Purchase $purchase, PurchaseReturn $purchaseReturn): void
    {
        $requestedByLine = [];
        foreach ($purchaseReturn->lines as $returnLine) {
            $line = PurchaseLine::where('purchase_id', $purchase->id)->lockForUpdate()->findOrFail($returnLine->purchase_line_id);
            $quantity = round((float) $returnLine->quantity, 3);
            $requestedByLine[$line->id] = round(($requestedByLine[$line->id] ?? 0) + $quantity, 3);
            $priorOther = (float) DB::table('purchase_return_lines')
                ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
                ->where('purchase_returns.status', 'posted')->where('purchase_return_lines.purchase_line_id', $line->id)
                ->sum('purchase_return_lines.quantity');
            $remaining = round((float) $line->received_quantity - $priorOther, 3);
            if ($requestedByLine[$line->id] > $remaining) {
                throw ValidationException::withMessages(['lines' => "Return quantity exceeds received-minus-prior-return quantity ({$remaining}). Double return rejected."]);
            }
        }
    }
}
