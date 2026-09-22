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
use App\Models\WarehouseLocation;
use App\Services\AccountingService;
use App\Services\ModuleRegistry;
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
        private SerialNumberService $serials,
    ) {}

    public function createDraft(int $tenantId, int $warehouseId, int $contactId, array $lines, ?int $actorId = null, bool $keepDraft = false): Purchase
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

        return DB::transaction(function () use ($tenantId, $warehouseId, $contactId, $lines, $actorId, $keepDraft) {
            $total = collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_cost']);
            if ($keepDraft) {
                $purchase = Purchase::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId, 'contact_id' => $contactId,
                    'status' => 'draft', 'requested_by' => $actorId, 'total' => $total,
                ]);
                foreach ($lines as $line) {
                    $purchase->lines()->create($line);
                }
                $this->audit->log($tenantId, $actorId, 'purchase.order.drafted', Purchase::class, $purchase->id, null, $purchase->load('lines')->toArray());

                return $purchase;
            }
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

    public function updateDraft(Purchase $purchase, int $warehouseId, int $contactId, array $lines, int $actorId): Purchase
    {
        return DB::transaction(function () use ($purchase, $warehouseId, $contactId, $lines, $actorId) {
            $locked = Purchase::withoutGlobalScopes()->where('tenant_id', $purchase->tenant_id)->lockForUpdate()->findOrFail($purchase->id);
            abort_unless($locked->status === 'draft' && $locked->requested_by === $actorId, 422, 'Only an unsubmitted PO draft owned by the requester can be edited.');
            abort_unless(Warehouse::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->whereKey($warehouseId)->exists(), 422, 'Warehouse does not belong to tenant.');
            abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->whereKey($contactId)->exists(), 422, 'Supplier does not belong to tenant.');
            abort_if($lines === [], 422, 'Purchase order requires at least one line.');
            foreach ($lines as $line) {
                abort_unless(ProductVariant::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->whereKey($line['product_variant_id'])->exists(), 422, 'Variant does not belong to tenant.');
                abort_if(($line['quantity'] ?? 0) <= 0, 422, 'Line quantity must be greater than zero.');
            }
            $before = $locked->load('lines')->toArray();
            $locked->lines()->delete();
            foreach ($lines as $line) {
                $locked->lines()->create($line);
            }
            $locked->update(['warehouse_id' => $warehouseId, 'contact_id' => $contactId, 'total' => collect($lines)->sum(fn ($line) => $line['quantity'] * $line['unit_cost'])]);
            $this->audit->log($locked->tenant_id, $actorId, 'purchase.order.draft_updated', Purchase::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    public function submitDraft(Purchase $purchase, int $actorId): Purchase
    {
        return DB::transaction(function () use ($purchase, $actorId) {
            $locked = Purchase::withoutGlobalScopes()->where('tenant_id', $purchase->tenant_id)->lockForUpdate()->findOrFail($purchase->id);
            abort_unless($locked->status === 'draft' && $locked->requested_by === $actorId, 422, 'Only the requester may submit this PO draft.');
            $managerThreshold = (float) SystemSetting::scalar($locked->tenant_id, 'purchase_manager_approval_threshold', 0);
            $ownerThreshold = (float) SystemSetting::scalar($locked->tenant_id, 'purchase_owner_approval_threshold', 0);
            $level = $ownerThreshold > 0 && (float) $locked->total >= $ownerThreshold ? 'owner' : ($managerThreshold > 0 && (float) $locked->total >= $managerThreshold ? 'manager' : null);
            $before = $locked->toArray();
            $locked->update(['status' => $level ? 'pending_approval' : 'ordered', 'approval_level' => $level]);
            if ($level) {
                ApprovalRequest::withoutGlobalScopes()->create(['tenant_id' => $locked->tenant_id, 'subject_type' => 'purchase_order', 'subject_id' => $locked->id, 'amount' => $locked->total, 'status' => 'pending', 'requested_by' => $actorId, 'metadata' => ['required_level' => $level]]);
            }
            $this->audit->log($locked->tenant_id, $actorId, 'purchase.order.submitted', Purchase::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function cancel(Purchase $purchase, int $actorId): Purchase
    {
        return DB::transaction(function () use ($purchase, $actorId) {
            $locked = Purchase::withoutGlobalScopes()->where('tenant_id', $purchase->tenant_id)->lockForUpdate()->findOrFail($purchase->id);
            abort_unless(in_array($locked->status, ['draft', 'ordered', 'pending_approval'], true), 422, 'Only unreceived purchase orders can be cancelled.');
            abort_unless($locked->requested_by === $actorId, 403, 'Only the requester may cancel this purchase order.');
            abort_if((float) $locked->lines()->sum('received_quantity') > 0, 422, 'Purchase order with received stock cannot be cancelled.');
            $before = $locked->toArray();
            $locked->update(['status' => 'cancelled']);
            ApprovalRequest::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->where('subject_type', 'purchase_order')->where('subject_id', $locked->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_by' => $actorId, 'decided_at' => now(), 'reason' => 'PO cancelled by requester']);
            $this->audit->log($locked->tenant_id, $actorId, 'purchase.order.cancelled', Purchase::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
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
                if ($partialLines === null) {
                    return $purchase; // Idempotent retry of the completed whole-PO receipt.
                }

                throw ValidationException::withMessages([
                    'lines' => 'Purchase order is fully received and cannot receive additional quantities.',
                ]);
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
                $locationId = filled($row['warehouse_location_id'] ?? null) ? (int) $row['warehouse_location_id'] : null;
                if ($locationId !== null && ! WarehouseLocation::withoutGlobalScopes()
                    ->where('tenant_id', $purchase->tenant_id)->where('warehouse_id', $purchase->warehouse_id)
                    ->where('is_active', true)->whereKey($locationId)->exists()) {
                    throw ValidationException::withMessages(['warehouse_location_id' => 'Selected rack/bin must be active and belong to the receipt warehouse.']);
                }
                $batchId = $this->receiveIntoBatchIfRequested($purchase, $receipt, $line, $qtyToReceive, $row);
                if ($batchId === null) {
                    $this->stock->increase(
                        $purchase->tenant_id, $purchase->warehouse_id,
                        $line->product_variant_id, $qtyToReceive,
                        (float) $line->unit_cost, 'purchase_receipt', $receipt->id, null, null, $locationId
                    );
                }
                $receipt->lines()->create([
                    'purchase_line_id' => $line->id, 'product_variant_id' => $line->product_variant_id,
                    'inventory_batch_id' => $batchId,
                    'warehouse_location_id' => $locationId,
                    'quantity' => $qtyToReceive, 'unit_cost' => $line->unit_cost,
                ]);
                $this->receiveSerials($purchase, $receipt, $line, $qtyToReceive, $batchId, $locationId, $row, $actorId);
                $line->update(['received_quantity' => (float) ($line->received_quantity ?? 0) + $qtyToReceive]);
            }

            $purchase->refresh();
            $totalOrdered = (float) $purchase->lines()->sum('quantity');
            $totalReceived = (float) $purchase->lines()->sum('received_quantity');
            $status = $totalReceived <= 0 ? $purchase->status : ($totalReceived < $totalOrdered ? 'partial' : 'received');
            $purchase->update(['status' => $status]);
            $this->audit->log($purchase->tenant_id, $actorId, 'purchase.goods_receipt.posted', GoodsReceipt::class, $receipt->id, null, $receipt->fresh('lines')->toArray());
            $this->postReceiptToAccounting($purchase, $receipt, $actorId);

            return $purchase;
        });
    }

    /** Post Dr Persediaan / Cr Hutang when accounting is on. Idempotent via the GRN id. */
    private function postReceiptToAccounting(Purchase $purchase, GoodsReceipt $receipt, ?int $actorId): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($purchase->tenant_id, 'accounting')) {
            return;
        }
        $accounting = app(AccountingService::class);
        $accounting->ensureDefaultChart($purchase->tenant_id);
        $existing = \App\Models\JournalEntry::withoutGlobalScopes()->where('tenant_id', $purchase->tenant_id)
            ->where('source_type', GoodsReceipt::class)->where('source_id', $receipt->id)
            ->where('status', \App\Models\JournalEntry::POSTED)->first();
        if ($existing) {
            return;
        }
        $total = round((float) $receipt->lines()->sum(DB::raw('quantity * unit_cost')), 2);
        if ($total <= 0) {
            return;
        }
        $entry = $accounting->createDraft(
            $purchase->tenant_id, $receipt->received_at->toDateString(), 'Penerimaan barang Purchase '.$purchase->id,
            [
                ['account_code' => '1400', 'debit' => $total, 'credit' => 0],
                ['account_code' => '2100', 'debit' => 0, 'credit' => $total],
            ], GoodsReceipt::class, $receipt->id, $actorId
        );
        $accounting->post($entry, $actorId);
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
                $quantity, (float) $line->unit_cost, 'purchase_receipt', $receipt->id, $batch->id, null,
                filled($row['warehouse_location_id'] ?? null) ? (int) $row['warehouse_location_id'] : null,
            );

            return $batch->id;
        }

        $batch = $this->batches->receive(
            $purchase->tenant_id, $purchase->warehouse_id, $line->product_variant_id,
            $batchNumber, $quantity, (float) $line->unit_cost,
            $row['manufactured_at'] ?? null, $row['expires_at'] ?? null,
            $purchase->contact_id, $purchase->id, $receipt->id,
            filled($row['warehouse_location_id'] ?? null) ? (int) $row['warehouse_location_id'] : null,
        );

        return $batch->id;
    }

    /**
     * Register optional serialized units against the same GRN transaction.
     * Stock is already posted above; serial registration must never post it a
     * second time.
     */
    private function receiveSerials(Purchase $purchase, GoodsReceipt $receipt, $line, float $quantity, ?int $batchId, ?int $locationId, array $row, ?int $actorId): void
    {
        $serials = array_values(array_filter($row['serial_numbers'] ?? [], fn ($serial) => filled($serial)));
        if ($serials === []) {
            return;
        }
        if ($quantity !== (float) count($serials) || count($serials) !== count(array_unique($serials))) {
            throw ValidationException::withMessages([
                'serial_numbers' => 'Each serialized receipt quantity requires one unique serial number.',
            ]);
        }
        foreach ($serials as $serial) {
            $this->serials->receive(
                $purchase->tenant_id,
                $purchase->warehouse_id,
                $line->product_variant_id,
                trim((string) $serial),
                (float) $line->unit_cost,
                $batchId,
                $purchase->id,
                $locationId,
                $actorId,
                false,
                $receipt->id,
            );
        }
    }
}
