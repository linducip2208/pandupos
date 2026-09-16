<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Atomic checkout: sale + payments + stock mutation. Idempotent via idempotency_key. */
final class SaleService
{
    public function __construct(
        private StockService $stock,
        private ApprovalService $approvals,
        private BundleInventoryService $bundles,
    ) {}

    /**
     * @param  array  $lines  [['variant_id'=>int,'quantity'=>float,'unit_price'=>float,'discount'=>float]]
     * @param  array  $payments  [['method'=>string,'amount'=>float,'reference'=>?string]]
     */
    public function checkout(
        int $tenantId, int $branchId, int $warehouseId, ?int $contactId,
        array $lines, array $payments, string $idempotencyKey,
    ): SalesInvoice {
        abort_if(trim($idempotencyKey) === '', 422, 'Idempotency-Key required.');
        // Sort lines for deterministic lock order (prevents deadlock on multi-variant checkout).
        $lines = collect($lines)->sortBy('variant_id')->values()->all();
        try {
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

                $requiresApproval = $this->approvals->requiresApproval($tenantId, (float) $subtotal);

                $invoice = SalesInvoice::withoutGlobalScopes()->create([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId, 'branch_id' => $branchId, 'warehouse_id' => $warehouseId,
                    'contact_id' => $contactId, 'invoice_no' => 'S-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                    'status' => $requiresApproval ? 'pending_approval' : 'final',
                    'payment_status' => $requiresApproval ? 'unpaid' : ($paid > 0 ? ($paid < $subtotal - 0.01 ? 'partial' : 'paid') : 'unpaid'),
                    'fulfillment_status' => $requiresApproval ? 'pending' : 'fulfilled',
                    'subtotal' => $subtotal, 'total' => $subtotal,
                    'idempotency_key' => $idempotencyKey,
                ]);

                foreach ($lines as $l) {
                    $invoice->lines()->create([
                        'product_variant_id' => $l['variant_id'], 'quantity' => $l['quantity'],
                        'unit_price' => $l['unit_price'], 'discount' => $l['discount'] ?? 0,
                    ]);
                    if (! $requiresApproval) {
                        $this->decreaseSoldInventory(
                            $tenantId,
                            $warehouseId,
                            (int) $l['variant_id'],
                            (float) $l['quantity'],
                            'sale',
                            $invoice->id
                        );
                    }
                }

                if ($requiresApproval) {
                    $this->approvals->requestForSale($invoice, $payments, auth()->id());
                } else {
                    foreach ($payments as $p) {
                        $invoice->payments()->create([
                            'tenant_id' => $tenantId, 'method' => $p['method'],
                            'amount' => $p['amount'], 'reference' => $p['reference'] ?? null,
                        ]);
                    }
                }

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

    public function void(int $invoiceId, bool $canVoid, ?int $tenantId = null): void
    {
        abort_unless($canVoid, 403, 'Unauthorized to void sale.');

        DB::transaction(function () use ($invoiceId, $tenantId) {
            $tenantId ??= TenantContext::id();
            $q = SalesInvoice::withoutGlobalScopes()->lockForUpdate();
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
            $invoice = $q->findOrFail($invoiceId);
            if ($invoice->status === 'void') {
                return;
            }
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
                $origCost = StockMovement::withoutGlobalScopes()
                    ->where('tenant_id', $invoice->tenant_id)
                    ->where('reference_type', 'sale')->where('reference_id', $invoice->id)
                    ->where('product_variant_id', $line->product_variant_id)
                    ->where('movement_type', 'out')->value('unit_cost') ?? 0;
                $this->stock->increase(
                    $invoice->tenant_id, $invoice->warehouse_id,
                    $line->product_variant_id, $toRestore, (float) $origCost, 'sale_void', $invoice->id
                );
            }
            $invoice->update(['status' => 'void']);
        });
    }

    public function return(int $invoiceId, array $returnLines, ?int $tenantId = null): void
    {
        DB::transaction(function () use ($invoiceId, $returnLines, $tenantId) {
            $tenantId ??= TenantContext::id();
            $q = SalesInvoice::withoutGlobalScopes()->lockForUpdate();
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
            $invoice = $q->findOrFail($invoiceId);
            abort_if($invoice->status === 'void', 422, 'Cannot return a voided sale.');
            $soldByVariant = [];
            foreach ($invoice->lines as $line) {
                $soldByVariant[$line->product_variant_id] = ($soldByVariant[$line->product_variant_id] ?? 0) + (float) $line->quantity;
            }
            $return = SalesReturn::withoutGlobalScopes()->create([
                'tenant_id' => $invoice->tenant_id,
                'sales_invoice_id' => $invoice->id,
                'total' => collect($returnLines)->sum(fn ($row) => $row['quantity'] * ($row['unit_price'] ?? 0)),
            ]);
            foreach ($returnLines as $rl) {
                if (($rl['quantity'] ?? 0) <= 0) {
                    abort(422, 'Return quantity must be greater than zero.');
                }
                $salesLine = $invoice->lines->firstWhere('product_variant_id', $rl['variant_id']);
                $sold = $soldByVariant[$rl['variant_id']] ?? 0;
                abort_if($sold <= 0, 422, 'Variant was not sold on this invoice.');
                $alreadyReturned = (float) $salesLine->returnLines()->sum('quantity');
                abort_if($alreadyReturned + (float) $rl['quantity'] > $sold + 0.000001, 422, 'Return quantity exceeds sold quantity.');
                $variant = ProductVariant::withoutGlobalScopes()->with('product')->findOrFail($rl['variant_id']);
                if ($variant->product->track_inventory && $variant->product->product_type === 'bundle') {
                    $this->bundles->increase(
                        $invoice->tenant_id,
                        $invoice->warehouse_id,
                        $variant,
                        (float) $rl['quantity'],
                        'sale_return',
                        $invoice->id
                    );
                } elseif ($variant->product->track_inventory) {
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
                $return->lines()->create([
                    'sales_line_id' => $salesLine->id,
                    'quantity' => $rl['quantity'],
                    'unit_price' => $rl['unit_price'] ?? $salesLine->unit_price,
                ]);
            }
        });
    }

    private function decreaseSoldInventory(
        int $tenantId,
        int $warehouseId,
        int $variantId,
        float $quantity,
        string $referenceType,
        int $referenceId,
    ): void {
        $variant = ProductVariant::withoutGlobalScopes()->with('product')->findOrFail($variantId);
        if (! $variant->product->track_inventory || $variant->product->product_type === 'service') {
            return;
        }
        if ($variant->product->product_type === 'bundle') {
            $this->bundles->decrease($tenantId, $warehouseId, $variant, $quantity, $referenceType, $referenceId);

            return;
        }
        $this->stock->decrease($tenantId, $warehouseId, $variantId, $quantity, $referenceType, $referenceId);
    }
}
