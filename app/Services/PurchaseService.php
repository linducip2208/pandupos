<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Contact;
use App\Models\GoodsReceipt;
use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SystemSetting;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Stock increases ONLY on receiving, never on PO creation. */
final class PurchaseService
{
    public function __construct(
        private StockService $stock,
        private AuditService $audit,
        private BatchInventoryService $batches,
    ) {}

    public function createDraft(int $tenantId, int $warehouseId, int $contactId, array $lines, ?int $actorId = null): Purchase
    {
        // Validate tenant ownership of all references (prevent IDOR, mirrors SaleService).
        abort_unless(Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $warehouseId)->exists(), 422, 'Warehouse does not belong to tenant.');
        abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $contactId)->exists(), 422, 'Contact does not belong to tenant.');
        foreach ($lines as $l) {
            abort_unless(ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $l['product_variant_id'])->exists(), 422, 'Variant does not belong to tenant.');
            if (($l['quantity'] ?? 0) <= 0) {
                abort(422, 'Line quantity must be greater than zero.');
            }
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $contactId, $lines, $actorId) {
            $total = collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_cost']);
            $managerThreshold = (float) SystemSetting::scalar($tenantId, 'purchase_manager_approval_threshold', 0);
            $ownerThreshold = (float) SystemSetting::scalar($tenantId, 'purchase_owner_approval_threshold', 0);
            $approvalLevel = $ownerThreshold > 0 && $total >= $ownerThreshold
                ? 'owner'
                : ($managerThreshold > 0 && $total >= $managerThreshold ? 'manager' : null);
            $purchase = Purchase::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId,
                'contact_id' => $contactId, 'status' => $approvalLevel ? 'pending_approval' : 'ordered',
                'approval_level' => $approvalLevel, 'requested_by' => $actorId, 'total' => $total,
            ]);
            foreach ($lines as $l) {
                $purchase->lines()->create($l);
            }
            if ($approvalLevel) {
                ApprovalRequest::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId, 'subject_type' => 'purchase_order', 'subject_id' => $purchase->id,
                    'amount' => $total, 'status' => 'pending', 'requested_by' => $actorId,
                    'metadata' => ['required_level' => $approvalLevel],
                ]);
            }
            $this->audit->log($tenantId, $actorId, 'purchase.order.created', Purchase::class, $purchase->id, null, $purchase->load('lines')->toArray());

            return $purchase;
        });
    }

    public function receive(int $purchaseId, ?array $partialLines = null, ?int $tenantId = null, ?int $actorId = null, ?string $notes = null): Purchase
    {
        return DB::transaction(function () use ($purchaseId, $partialLines, $tenantId, $actorId, $notes) {
            $tenantId ??= TenantContext::id();
            $q = Purchase::withoutGlobalScopes()->lockForUpdate();
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
            $purchase = $q->findOrFail($purchaseId);

            if ($purchase->status === 'received') {
                return $purchase; // idempotent
            }
            abort_if($purchase->status === 'pending_approval', 422, 'Purchase order requires approval before receiving.');
            abort_if($purchase->status === 'cancelled', 422, 'Cannot receive a cancelled purchase.');

            $map = null;
            if ($partialLines !== null) {
                $map = collect($partialLines)->keyBy('product_variant_id');
            }

            $receivable = [];
            foreach ($purchase->lines()->lockForUpdate()->get() as $line) {
                $qtyToReceive = (float) $line->quantity - (float) ($line->received_quantity ?? 0);
                if ($map !== null) {
                    $row = $map->get($line->product_variant_id);
                    if (! $row) {
                        continue;
                    }
                    $requested = (float) ($row['quantity'] ?? 0);
                    if ($requested > $qtyToReceive) {
                        throw ValidationException::withMessages([
                            'lines' => "Receipt quantity {$requested} exceeds remaining quantity {$qtyToReceive}.",
                        ]);
                    }
                    $qtyToReceive = $requested;
                }
                if ($qtyToReceive <= 0) {
                    continue;
                }
                $receivable[] = [$line, $qtyToReceive, $row ?? []];
            }

            if ($receivable === []) {
                return $purchase->fresh('lines');
            }

            $receipt = GoodsReceipt::withoutGlobalScopes()->create([
                'tenant_id' => $purchase->tenant_id, 'purchase_id' => $purchase->id,
                'warehouse_id' => $purchase->warehouse_id, 'receipt_no' => 'TMP-'.(string) Str::uuid(),
                'received_at' => now(), 'received_by' => $actorId, 'notes' => $notes,
            ]);
            $receipt->update(['receipt_no' => 'GR-'.str_pad((string) $receipt->id, 8, '0', STR_PAD_LEFT)]);

            foreach ($receivable as [$line, $qtyToReceive, $row]) {
                $batchId = $this->receiveIntoBatchIfRequested($purchase, $receipt, $line, $qtyToReceive, $row);
                if ($batchId === null) {
                    $this->stock->increase(
                        $purchase->tenant_id, $purchase->warehouse_id,
                        $line->product_variant_id, $qtyToReceive,
                        (float) $line->unit_cost, 'purchase_receipt', $receipt->id
                    );
                }
                $receipt->lines()->create([
                    'purchase_line_id' => $line->id, 'product_variant_id' => $line->product_variant_id,
                    'inventory_batch_id' => $batchId,
                    'quantity' => $qtyToReceive, 'unit_cost' => $line->unit_cost,
                ]);
                $line->update(['received_quantity' => (float) ($line->received_quantity ?? 0) + $qtyToReceive]);
            }

            $purchase->refresh();
            $totalOrdered = (float) $purchase->lines()->sum('quantity');
            $totalReceived = (float) $purchase->lines()->sum('received_quantity');
            $status = $totalReceived <= 0 ? $purchase->status : ($totalReceived < $totalOrdered ? 'partial' : 'received');
            $purchase->update(['status' => $status]);
            $this->audit->log($purchase->tenant_id, $actorId, 'purchase.goods_receipt.posted', GoodsReceipt::class, $receipt->id, null, $receipt->fresh('lines')->toArray());

            return $purchase;
        });
    }

    /**
     * A purchase receipt may create a new lot or receive into an existing lot.
     * The lot, receipt line, and append-only stock movement are written in the
     * same transaction so a GRN can never leave untraceable batch stock behind.
     */
    private function receiveIntoBatchIfRequested(Purchase $purchase, GoodsReceipt $receipt, $line, float $quantity, array $row): ?int
    {
        $batchId = isset($row['inventory_batch_id']) ? (int) $row['inventory_batch_id'] : null;
        $batchNumber = trim((string) ($row['batch_number'] ?? ''));
        if ($batchId === null && $batchNumber === '') {
            return null;
        }

        if ($batchId !== null) {
            $batch = InventoryBatch::withoutGlobalScopes()
                ->where('tenant_id', $purchase->tenant_id)
                ->where('warehouse_id', $purchase->warehouse_id)
                ->where('product_variant_id', $line->product_variant_id)
                ->lockForUpdate()
                ->find($batchId);
            if (! $batch) {
                throw ValidationException::withMessages(['inventory_batch_id' => 'Selected batch does not belong to this receipt.']);
            }
            if (($batch->purchase_id !== null && $batch->purchase_id !== $purchase->id)
                || ($batch->supplier_id !== null && $batch->supplier_id !== $purchase->contact_id)) {
                throw ValidationException::withMessages(['inventory_batch_id' => 'Selected batch provenance does not match this purchase.']);
            }
            $batch->fill([
                'supplier_id' => $batch->supplier_id ?? $purchase->contact_id,
                'purchase_id' => $batch->purchase_id ?? $purchase->id,
            ])->save();
            $this->stock->increase(
                $purchase->tenant_id, $purchase->warehouse_id, $line->product_variant_id,
                $quantity, (float) $line->unit_cost, 'purchase_receipt', $receipt->id, $batch->id,
            );

            return $batch->id;
        }

        $batch = $this->batches->receive(
            $purchase->tenant_id, $purchase->warehouse_id, $line->product_variant_id,
            $batchNumber, $quantity, (float) $line->unit_cost,
            $row['manufactured_at'] ?? null, $row['expires_at'] ?? null,
            $purchase->contact_id, $purchase->id, $receipt->id,
        );

        return $batch->id;
    }
}
