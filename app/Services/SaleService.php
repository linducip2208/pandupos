<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashSession;
use App\Models\CashSessionMovement;
use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\SaleRefund;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\SerialNumber;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Atomic checkout: sale + payments + stock mutation. Idempotent via idempotency_key. */
final class SaleService
{
    public function __construct(
        private StockService $stock,
        private ApprovalService $approvals,
        private BundleInventoryService $bundles,
        private BatchInventoryService $batches,
        private SerialNumberService $serials,
        private AuditService $audit,
        private UsageLimitService $usage,
    ) {}

    /**
     * @param  array  $lines  [['variant_id'=>int,'quantity'=>float,'unit_price'=>float,'discount'=>float]]
     * @param  array  $payments  [['method'=>string,'amount'=>float,'reference'=>?string]]
     */
    public function checkout(
        int $tenantId, int $branchId, int $warehouseId, ?int $contactId,
        array $lines, array $payments, string $idempotencyKey, ?int $cashSessionId = null, float $tax = 0,
    ): SalesInvoice {
        abort_if(trim($idempotencyKey) === '', 422, 'Idempotency-Key required.');
        // Sort lines for deterministic lock order (prevents deadlock on multi-variant checkout).
        $lines = collect($lines)->sortBy('variant_id')->values()->all();
        try {
            return DB::transaction(function () use ($tenantId, $branchId, $warehouseId, $contactId, $lines, $payments, $idempotencyKey, $cashSessionId, $tax) {
                // Idempotency: same key returns existing invoice, never duplicates.
                $existing = SalesInvoice::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }

                // Plan usage meter: do not let a tenant exceed its monthly invoice quota.
                $this->usage->assertCanCreate($tenantId, 'invoices.monthly');

                // Validate tenant ownership of all references (prevent IDOR).
                abort_unless(Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $branchId)->exists(), 422, 'Branch does not belong to tenant.');
                abort_unless(Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $warehouseId)->exists(), 422, 'Warehouse does not belong to tenant.');
                if ($contactId) {
                    abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $contactId)->exists(), 422, 'Customer does not belong to tenant.');
                }
                if ($cashSessionId !== null) {
                    $session = CashSession::withoutGlobalScopes()->lockForUpdate()
                        ->where('tenant_id', $tenantId)->where('status', 'open')->findOrFail($cashSessionId);
                    abort_unless($session->register?->branch_id === null || $session->register?->branch_id === $branchId, 422, 'Register session does not belong to the sale branch.');
                    abort_unless($session->opened_by === auth()->id(), 403, 'Only the cashier who opened the register session may post to it.');
                }
                foreach ($lines as $l) {
                    abort_unless(ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $l['variant_id'])->exists(), 422, 'Variant does not belong to tenant.');
                    if (($l['quantity'] ?? 0) <= 0) {
                        abort(422, 'Line quantity must be greater than zero.');
                    }
                    $serialIds = collect($l['serial_number_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
                    if ($serialIds->isNotEmpty() && ($serialIds->count() !== (int) $l['quantity'] || $serialIds->unique()->count() !== $serialIds->count())) {
                        abort(422, 'Each serialized sale line requires one unique serial per unit.');
                    }
                }

                $subtotal = round((float) collect($lines)->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_price']), 2);
                $discount = round((float) collect($lines)->sum(fn ($line) => (float) ($line['discount'] ?? 0)), 2);
                $tax = round($tax, 2);
                abort_if($discount < 0 || $discount > $subtotal || $tax < 0, 422, 'Discount and tax values are invalid.');
                $total = round($subtotal - $discount + $tax, 2);
                $paid = collect($payments)->sum(fn ($p) => $p['amount']);

                if (abs($paid - $total) > 0.01) {
                    abort(422, "Split payment total ({$paid}) must equal invoice total ({$total}).");
                }

                $requiresApproval = $this->approvals->requiresApproval($tenantId, $total);

                $invoice = SalesInvoice::withoutGlobalScopes()->create([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId, 'branch_id' => $branchId, 'warehouse_id' => $warehouseId,
                    'contact_id' => $contactId, 'cash_session_id' => $cashSessionId, 'invoice_no' => 'S-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                    'status' => $requiresApproval ? 'pending_approval' : 'final',
                    'payment_status' => $requiresApproval ? 'unpaid' : ($paid > 0 ? ($paid < $total - 0.01 ? 'partial' : 'paid') : 'unpaid'),
                    'fulfillment_status' => $requiresApproval ? 'pending' : 'fulfilled',
                    'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total,
                    'idempotency_key' => $idempotencyKey,
                ]);

                foreach ($lines as $l) {
                    $invoice->lines()->create([
                        'product_variant_id' => $l['variant_id'], 'quantity' => $l['quantity'],
                        'unit_price' => $l['unit_price'], 'discount' => $l['discount'] ?? 0,
                    ]);
                    if (! $requiresApproval) {
                        $serialIds = collect($l['serial_number_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
                        if ($serialIds->isNotEmpty()) {
                            foreach ($serialIds as $serialId) {
                                $this->serials->sell($tenantId, $serialId, $invoice->id);
                            }
                        } else {
                            $this->decreaseSoldInventory(
                                $tenantId,
                                $warehouseId,
                                (int) $l['variant_id'],
                                (float) $l['quantity'],
                                'sale',
                                $invoice->id,
                                $l['inventory_batch_id'] ?? null,
                            );
                        }
                    }
                }

                if ($requiresApproval) {
                    $this->approvals->requestForSale($invoice, $payments, auth()->id());
                } else {
                    $postedPayments = [];
                    foreach ($payments as $p) {
                        $postedPayments[] = $invoice->payments()->create([
                            'tenant_id' => $tenantId, 'method' => $p['method'],
                            'amount' => $p['amount'], 'reference' => $p['reference'] ?? null,
                        ]);
                    }
                    $this->postSaleToAccounting($tenantId, $invoice, $postedPayments);
                }

                $this->audit->log($tenantId, auth()->id(), 'sale.checkout.posted', SalesInvoice::class, $invoice->id, null, [
                    'invoice_no' => $invoice->invoice_no,
                    'total' => $invoice->total,
                    'payment_status' => $invoice->payment_status,
                ]);

                return $invoice;
            });
        } catch (QueryException $e) {
            // Concurrent duplicate Idempotency-Key: loser re-reads winner instead of 500.
            if ($e->getCode() === '23000') {
                $existing = SalesInvoice::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    public function void(int $invoiceId, bool $canVoid, ?int $tenantId = null, ?string $reason = null): void
    {
        abort_unless($canVoid, 403, 'Unauthorized to void sale.');
        abort_if(filled($reason) && mb_strlen(trim($reason)) > 1000, 422, 'Void reason is too long.');

        DB::transaction(function () use ($invoiceId, $tenantId, $reason) {
            $tenantId ??= TenantContext::id();
            $q = SalesInvoice::withoutGlobalScopes()->lockForUpdate();
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
            $invoice = $q->findOrFail($invoiceId);
            if ($invoice->status === 'void') {
                return;
            }
            abort_if($invoice->status !== 'final', 422, 'Only a final sale can be voided.');
            // Completed sales are never hard-deleted; reversal via stock movement + audit.
            // Restore only net sold (sold - already returned) to avoid double-restore after returns.
            foreach ($invoice->lines as $line) {
                $alreadyReturned = (float) $line->returnLines()->sum('quantity');
                $toRestore = (float) $line->quantity - $alreadyReturned;
                if ($toRestore <= 0) {
                    continue;
                }
                $variant = ProductVariant::withoutGlobalScopes()->with('product')->findOrFail($line->product_variant_id);
                if (! $variant->product->track_inventory) {
                    continue;
                }
                if ($variant->product->product_type === 'bundle') {
                    $this->bundles->increase(
                        $invoice->tenant_id,
                        $invoice->warehouse_id,
                        $variant,
                        $toRestore,
                        'sale_void',
                        $invoice->id
                    );

                    continue;
                }
                $this->restoreSoldInventory($invoice, (int) $line->product_variant_id, $toRestore, 'sale_void');
            }
            // Payment reversal: voided invoices are excluded from register expected-cash
            // (RegisterSessionService sums only final invoices), and the payment state
            // is marked refunded so reports never treat voided money as collected.
            $before = $invoice->toArray();
            $invoice->update(['status' => 'void', 'payment_status' => 'refunded']);
            $this->audit->log($invoice->tenant_id, auth()->id(), 'sale.void.posted', SalesInvoice::class, $invoice->id, $before, [
                'status' => 'void',
                'payment_status' => 'refunded',
                'reason' => filled($reason) ? trim($reason) : 'Direct service invocation',
            ]);
        });
    }

    /**
     * Controlled sales return with idempotency, batch/serial provenance,
     * over-return protection and audit. Returns the created SalesReturn.
     */
    public function return(
        int $invoiceId,
        array $returnLines,
        ?int $tenantId = null,
        ?int $actorId = null,
        ?string $idempotencyKey = null,
        ?string $reason = null,
        bool $canReturn = true,
    ): SalesReturn {
        abort_unless($canReturn, 403, 'Unauthorized to return sale.');
        if ($returnLines === []) {
            throw ValidationException::withMessages(['lines' => 'At least one return line is required.']);
        }
        $idempotencyKey = filled($idempotencyKey) ? trim($idempotencyKey) : null;

        try {
            return DB::transaction(function () use ($invoiceId, $returnLines, $tenantId, $actorId, $idempotencyKey, $reason) {
                $tenantId ??= TenantContext::id();
                if ($idempotencyKey !== null && $tenantId !== null) {
                    $existing = SalesReturn::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
                    if ($existing) {
                        return $existing->load('lines');
                    }
                }
                $q = SalesInvoice::withoutGlobalScopes()->lockForUpdate();
                if ($tenantId !== null) {
                    $q->where('tenant_id', $tenantId);
                }
                $invoice = $q->findOrFail($invoiceId);
                abort_if($invoice->status === 'void', 422, 'Cannot return a voided sale.');
                abort_if($invoice->status !== 'final', 422, 'Only a final sale can be returned.');
                $soldByVariant = [];
                foreach ($invoice->lines as $line) {
                    $soldByVariant[$line->product_variant_id] = ($soldByVariant[$line->product_variant_id] ?? 0) + (float) $line->quantity;
                }
                // Validate everything before mutating any stock.
                $prepared = [];
                foreach ($returnLines as $rl) {
                    if (($rl['quantity'] ?? 0) <= 0) {
                        abort(422, 'Return quantity must be greater than zero.');
                    }
                    $salesLine = $invoice->lines->firstWhere('product_variant_id', $rl['variant_id']);
                    $sold = $soldByVariant[$rl['variant_id']] ?? 0;
                    abort_if(! $salesLine || $sold <= 0, 422, 'Variant was not sold on this invoice.');
                    $alreadyReturned = (float) $salesLine->returnLines()->sum('quantity');
                    abort_if($alreadyReturned + (float) $rl['quantity'] > $sold + 0.000001, 422, 'Return quantity exceeds sold quantity.');
                    $batchId = isset($rl['inventory_batch_id']) && filled($rl['inventory_batch_id']) ? (int) $rl['inventory_batch_id'] : null;
                    if ($batchId !== null) {
                        $this->validateReturnBatch($invoice, (int) $rl['variant_id'], $batchId, (float) $rl['quantity']);
                    }
                    $serialIds = collect($rl['serial_number_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
                    if ($serialIds->isNotEmpty()) {
                        $this->validateReturnSerials($invoice, (int) $rl['variant_id'], $serialIds, (float) $rl['quantity']);
                    }
                    $prepared[] = [$salesLine, $rl, $batchId, $serialIds];
                }
                $return = SalesReturn::withoutGlobalScopes()->create([
                    'tenant_id' => $invoice->tenant_id,
                    'sales_invoice_id' => $invoice->id,
                    'total' => collect($returnLines)->sum(fn ($row) => $row['quantity'] * ($row['unit_price'] ?? 0)),
                    'status' => 'completed',
                    'reason' => filled($reason) ? trim($reason) : null,
                    'created_by' => $actorId ?? auth()->id(),
                    'idempotency_key' => $idempotencyKey,
                ]);
                foreach ($prepared as [$salesLine, $rl, $batchId, $serialIds]) {
                    $variant = ProductVariant::withoutGlobalScopes()->with('product')->findOrFail($rl['variant_id']);
                    if ($serialIds->isNotEmpty()) {
                        foreach ($serialIds as $serialId) {
                            $serial = SerialNumber::withoutGlobalScopes()
                                ->where('tenant_id', $invoice->tenant_id)->lockForUpdate()->findOrFail($serialId);
                            abort_unless((int) $serial->sales_invoice_id === (int) $invoice->id && $serial->status === 'sold', 422, 'Serial is not sold on this invoice.');
                            $soldMovement = StockMovement::withoutGlobalScopes()
                                ->where('tenant_id', $invoice->tenant_id)->where('reference_type', 'sale')->where('reference_id', $invoice->id)
                                ->where('serial_number_id', $serialId)->where('movement_type', 'out')->firstOrFail();
                            $this->serials->restoreFromSale($invoice->tenant_id, $serialId, $invoice->id, (float) $soldMovement->unit_cost, 'sale_return');
                        }
                    } elseif ($variant->product->track_inventory && $variant->product->product_type === 'bundle') {
                        $this->bundles->increase(
                            $invoice->tenant_id,
                            $invoice->warehouse_id,
                            $variant,
                            (float) $rl['quantity'],
                            'sale_return',
                            $invoice->id
                        );
                    } elseif ($variant->product->track_inventory) {
                        $this->restoreSoldInventory($invoice, (int) $rl['variant_id'], (float) $rl['quantity'], 'sale_return');
                    }
                    $return->lines()->create([
                        'sales_line_id' => $salesLine->id,
                        'quantity' => $rl['quantity'],
                        'unit_price' => $rl['unit_price'] ?? $salesLine->unit_price,
                        'inventory_batch_id' => $batchId,
                        'serial_number_id' => $serialIds->count() === 1 ? $serialIds->first() : null,
                    ]);
                }
                $this->audit->log($invoice->tenant_id, $actorId ?? auth()->id(), 'sale.return.posted', SalesReturn::class, $return->id, null, [
                    'sales_invoice_id' => $invoice->id, 'total' => $return->total,
                    'reason' => filled($reason) ? trim($reason) : 'Direct service invocation',
                ]);

                return $return->load('lines');
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && $idempotencyKey !== null && $tenantId !== null) {
                $existing = SalesReturn::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing->load('lines');
                }
            }
            throw $e;
        }
    }

    /**
     * Payment reversal for a posted sales return. Idempotent via the tenant-unique
     * refund reference; cash refunds post a cash_out movement against the invoice's
     * open register session so expected cash stays correct.
     */
    public function refund(
        int $invoiceId,
        int $salesReturnId,
        float $amount,
        string $method,
        ?string $reference,
        string $reason,
        int $actorId,
        bool $canRefund,
        ?int $tenantId = null,
    ): SaleRefund {
        abort_unless($canRefund, 403, 'Unauthorized to refund sale.');
        if (! in_array($method, ['cash', 'transfer', 'qris', 'ewallet', 'card'], true)) {
            throw ValidationException::withMessages(['method' => 'Unknown refund payment method.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A refund reason is required.']);
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Refund amount must be greater than zero.']);
        }
        $reference = filled($reference) ? trim($reference) : null;

        // Idempotency: same tenant reference returns the existing refund, never a duplicate.
        if ($reference !== null && $tenantId !== null) {
            $existing = SaleRefund::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('reference', $reference)->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($invoiceId, $salesReturnId, $amount, $method, $reference, $reason, $actorId, $tenantId) {
                $tenantId ??= TenantContext::id();
                $q = SalesInvoice::withoutGlobalScopes()->lockForUpdate();
                if ($tenantId !== null) {
                    $q->where('tenant_id', $tenantId);
                }
                $invoice = $q->findOrFail($invoiceId);
                abort_if($invoice->status === 'void', 422, 'Cannot refund a voided sale; the void already reverses payment.');
                $salesReturn = SalesReturn::withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->lockForUpdate()->findOrFail($salesReturnId);
                abort_unless((int) $salesReturn->sales_invoice_id === (int) $invoice->id, 422, 'Sales return does not belong to this invoice.');
                if ($reference !== null) {
                    $existing = SaleRefund::withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->where('reference', $reference)->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                $paid = round((float) $invoice->payments()->sum('amount'), 2);
                $refunded = round((float) $invoice->refunds()->sum('amount'), 2);
                $remaining = round($paid - $refunded, 2);
                if ($amount > $remaining) {
                    throw ValidationException::withMessages(['amount' => "Refund ({$amount}) exceeds remaining paid amount ({$remaining})."]);
                }
                $maxReturnValue = round((float) $salesReturn->total, 2);
                $refundedForReturn = round((float) $salesReturn->refunds()->sum('amount'), 2);
                if ($amount > round($maxReturnValue - $refundedForReturn, 2)) {
                    throw ValidationException::withMessages(['amount' => 'Refund exceeds the sales return value.']);
                }

                $movementId = null;
                if ($method === 'cash' && $invoice->cash_session_id !== null) {
                    $session = CashSession::withoutGlobalScopes()->lockForUpdate()
                        ->where('tenant_id', $invoice->tenant_id)->findOrFail($invoice->cash_session_id);
                    abort_unless($session->status === 'open', 422, 'Cash register session is closed; cash refund cannot be posted.');
                    $movement = CashSessionMovement::withoutGlobalScopes()->create([
                        'tenant_id' => $invoice->tenant_id, 'cash_session_id' => $session->id, 'created_by' => $actorId,
                        'type' => 'cash_out', 'amount' => $amount, 'reason' => 'Refund '.$invoice->invoice_no.': '.trim($reason),
                    ]);
                    $movementId = $movement->id;
                    $this->audit->log($invoice->tenant_id, $actorId, 'register.cash.cash_out', CashSessionMovement::class, $movement->id, null, $movement->toArray());
                }

                $refund = SaleRefund::withoutGlobalScopes()->create([
                    'tenant_id' => $invoice->tenant_id, 'sales_invoice_id' => $invoice->id, 'sales_return_id' => $salesReturn->id,
                    'amount' => $amount, 'method' => $method, 'reference' => $reference,
                    'reason' => trim($reason), 'created_by' => $actorId,
                    'cash_session_movement_id' => $movementId, 'refunded_at' => now(),
                ]);
                $netPaid = round($paid - ($refunded + $amount), 2);
                $invoice->update(['payment_status' => $netPaid <= 0 ? 'refunded' : 'partial']);
                $this->audit->log($invoice->tenant_id, $actorId, 'sale.refund.posted', SaleRefund::class, $refund->id, null, $refund->toArray());

                return $refund;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && $reference !== null) {
                $existing = SaleRefund::withoutGlobalScopes()->where('reference', $reference)->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /** A return may target the original sale batch; expired lots remain returnable. */
    private function validateReturnBatch(SalesInvoice $invoice, int $variantId, int $batchId, float $quantity): void
    {
        $soldInBatch = (float) StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)->where('reference_type', 'sale')->where('reference_id', $invoice->id)
            ->where('product_variant_id', $variantId)->where('inventory_batch_id', $batchId)
            ->where('movement_type', 'out')->sum('quantity');
        abort_if($soldInBatch <= 0, 422, 'Selected batch was not sold on this invoice.');
        $restoredInBatch = (float) StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)->whereIn('reference_type', ['sale_return', 'sale_void'])
            ->where('reference_id', $invoice->id)->where('product_variant_id', $variantId)
            ->where('inventory_batch_id', $batchId)->where('movement_type', 'in')->sum('quantity');
        abort_if($restoredInBatch + $quantity > $soldInBatch + 0.000001, 422, 'Return quantity exceeds sold quantity in the selected batch.');
    }

    /**
     * Post invoice + receipts to the accounting ledger. Runs inside the sale
     * transaction and only when the accounting module is enabled, so Core
     * behavior is unchanged for tenants without the module.
     */
    private function postSaleToAccounting(int $tenantId, SalesInvoice $invoice, array $payments): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($tenantId, 'accounting')) {
            return;
        }
        $accounting = app(AccountingService::class);
        $actorId = auth()->id();
        $accounting->postSalesInvoice($invoice, $actorId);
        foreach ($payments as $payment) {
            $accounting->recordCustomerReceipt($payment, $actorId);
        }
    }

    private function validateReturnSerials(SalesInvoice $invoice, int $variantId, $serialIds, float $quantity): void
    {
        if ($serialIds->count() !== (int) $quantity || $serialIds->unique()->count() !== $serialIds->count()) {
            throw ValidationException::withMessages(['serial_number_ids' => 'Each serialized return unit requires one unique serial.']);
        }
        foreach ($serialIds as $serialId) {
            $serial = SerialNumber::withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)->find($serialId);
            if (! $serial || (int) $serial->product_variant_id !== $variantId
                || $serial->status !== 'sold' || (int) $serial->sales_invoice_id !== (int) $invoice->id) {
                throw ValidationException::withMessages(['serial_number_ids' => 'Return serial is not sold on this invoice.']);
            }
        }
    }

    private function decreaseSoldInventory(
        int $tenantId,
        int $warehouseId,
        int $variantId,
        float $quantity,
        string $referenceType,
        int $referenceId,
        ?int $batchId = null,
    ): void {
        $variant = ProductVariant::withoutGlobalScopes()->with('product')->findOrFail($variantId);
        if (! $variant->product->track_inventory || $variant->product->product_type === 'service') {
            return;
        }
        if ($variant->product->product_type === 'bundle') {
            $this->bundles->decrease($tenantId, $warehouseId, $variant, $quantity, $referenceType, $referenceId);

            return;
        }
        if ($batchId !== null) {
            $this->batches->allocateSpecific($tenantId, $warehouseId, $variantId, $batchId, $quantity, $referenceType, $referenceId);

            return;
        }
        if ($this->batches->hasTrackedBatches($tenantId, $warehouseId, $variantId)) {
            $this->batches->allocateFefo($tenantId, $warehouseId, $variantId, $quantity, $referenceType, $referenceId);

            return;
        }
        $this->stock->decrease($tenantId, $warehouseId, $variantId, $quantity, $referenceType, $referenceId);
    }

    private function restoreSoldInventory(SalesInvoice $invoice, int $variantId, float $quantity, string $referenceType): void
    {
        $remaining = $quantity;
        $sold = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)->where('reference_type', 'sale')->where('reference_id', $invoice->id)
            ->where('product_variant_id', $variantId)->where('movement_type', 'out')->orderBy('id')->get();
        $alreadyRestored = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)->whereIn('reference_type', ['sale_return', 'sale_void'])
            ->where('reference_id', $invoice->id)->where('product_variant_id', $variantId)->where('movement_type', 'in')->get()
            ->groupBy(fn (StockMovement $movement) => $movement->serial_number_id ?? ($movement->inventory_batch_id ?? 'unbatched'))
            ->map(fn ($movements) => (float) $movements->sum('quantity'));

        foreach ($sold as $movement) {
            $batchKey = $movement->serial_number_id ?? ($movement->inventory_batch_id ?? 'unbatched');
            $available = (float) $movement->quantity - (float) ($alreadyRestored[$batchKey] ?? 0);
            $take = min($remaining, max(0, $available));
            if ($take <= 0) {
                continue;
            }
            if ($movement->serial_number_id !== null) {
                // A serial can only be restored as a whole; serialized sale lines are one unit each.
                abort_if(abs($take - 1.0) > 0.000001, 422, 'Serialized return must contain complete serial units.');
                $this->serials->restoreFromSale(
                    $invoice->tenant_id, $movement->serial_number_id, $invoice->id,
                    (float) $movement->unit_cost, $referenceType,
                );
            } else {
                $this->stock->increase(
                    $invoice->tenant_id, $invoice->warehouse_id, $variantId, $take,
                    (float) $movement->unit_cost, $referenceType, $invoice->id, $movement->inventory_batch_id,
                );
            }
            $alreadyRestored[$batchKey] = (float) ($alreadyRestored[$batchKey] ?? 0) + $take;
            $remaining = round($remaining - $take, 6);
            if ($remaining <= 0) {
                return;
            }
        }

        abort(422, 'Return quantity exceeds remaining sold stock.');
    }
}
