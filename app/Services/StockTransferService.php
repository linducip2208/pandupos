<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\SerialNumber;
use App\Models\TransferLine;
use App\Models\TransferOrder;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockTransferService
{
    public function __construct(
        private StockService $stock,
        private AuditService $audit,
        private SerialNumberService $serials,
    ) {}

    public function createDraft(int $tenantId, int $fromWarehouseId, int $toWarehouseId, array $lines, ?string $notes = null, ?int $actorId = null): TransferOrder
    {
        $this->validateWarehouses($tenantId, $fromWarehouseId, $toWarehouseId);
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'At least one transfer line is required.']);
        }

        return DB::transaction(function () use ($tenantId, $fromWarehouseId, $toWarehouseId, $lines, $notes, $actorId) {
            $transfer = TransferOrder::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId, 'status' => 'draft', 'notes' => $notes,
                'requested_by' => $actorId,
            ]);
            foreach ($lines as $line) {
                $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($line['product_variant_id']);
                if (! $variant || (float) $line['quantity'] <= 0) {
                    throw ValidationException::withMessages(['lines' => 'Every variant must belong to the tenant and quantity must be positive.']);
                }
                $batchId = isset($line['inventory_batch_id']) ? (int) $line['inventory_batch_id'] : null;
                $sourceLocationId = isset($line['source_warehouse_location_id']) ? (int) $line['source_warehouse_location_id'] : null;
                $destinationLocationId = isset($line['destination_warehouse_location_id']) ? (int) $line['destination_warehouse_location_id'] : null;
                if ($batchId !== null && ! InventoryBatch::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('warehouse_id', $fromWarehouseId)
                    ->where('product_variant_id', $variant->id)->whereKey($batchId)->exists()) {
                    throw ValidationException::withMessages(['lines' => 'Selected batch must belong to the source warehouse and variant.']);
                }
                if (! $this->locationBelongsTo($tenantId, $fromWarehouseId, $sourceLocationId)
                    || ! $this->locationBelongsTo($tenantId, $toWarehouseId, $destinationLocationId)) {
                    throw ValidationException::withMessages(['lines' => 'Selected locations must be active locations in the respective transfer warehouses.']);
                }
                $serialIds = collect($line['serial_number_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
                if ($serialIds->isNotEmpty()) {
                    if ($serialIds->count() !== (int) $line['quantity'] || $serialIds->unique()->count() !== $serialIds->count()) {
                        throw ValidationException::withMessages(['lines' => 'Each serialized transfer line requires one unique serial per unit.']);
                    }
                    if ($sourceLocationId !== null || $destinationLocationId !== null) {
                        throw ValidationException::withMessages(['lines' => 'Serialized transfers cannot select a rack/bin until serial location tracking is configured.']);
                    }
                    $serialCount = SerialNumber::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)->where('warehouse_id', $fromWarehouseId)
                        ->where('product_variant_id', $variant->id)->where('status', 'available')
                        ->whereIn('id', $serialIds)->count();
                    if ($serialCount !== $serialIds->count()) {
                        throw ValidationException::withMessages(['lines' => 'Every selected serial must be available in the source warehouse.']);
                    }
                }
                $transferLine = $transfer->lines()->create([
                    'product_variant_id' => $variant->id, 'source_inventory_batch_id' => $batchId,
                    'source_warehouse_location_id' => $sourceLocationId, 'destination_warehouse_location_id' => $destinationLocationId,
                    'quantity' => $line['quantity'],
                ]);
                foreach ($serialIds as $serialId) {
                    $transferLine->serials()->create(['serial_number_id' => $serialId]);
                }
            }
            $this->audit->log($tenantId, $actorId, 'inventory.transfer.created', TransferOrder::class, $transfer->id, null, $transfer->load('lines')->toArray());

            return $transfer->fresh('lines');
        });
    }

    public function approve(TransferOrder $transfer, int $actorId): TransferOrder
    {
        return DB::transaction(function () use ($transfer, $actorId) {
            $locked = $this->lock($transfer);
            $this->expectStatus($locked, ['draft']);
            if ($locked->requested_by !== null && (int) $locked->requested_by === $actorId) {
                throw ValidationException::withMessages(['approval' => 'Pembuat transfer tidak dapat menyetujui transfer sendiri.']);
            }

            $before = $locked->toArray();
            $locked->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.transfer.approved', TransferOrder::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh('lines');
        });
    }

    public function ship(TransferOrder $transfer, int $actorId): TransferOrder
    {
        return DB::transaction(function () use ($transfer, $actorId) {
            $locked = $this->lock($transfer);
            $this->expectStatus($locked, ['approved']);
            $before = $locked->load('lines')->toArray();
            foreach ($locked->lines()->with('serials.serialNumber')->get() as $line) {
                $cost = $this->stock->weightedAverageCost($locked->tenant_id, $locked->from_warehouse_id, $line->product_variant_id);
                if ($line->serials->isNotEmpty()) {
                    foreach ($line->serials as $serial) {
                        $this->serials->shipForTransfer($locked->tenant_id, $serial->serial_number_id, $locked->id);
                    }
                    $line->update(['unit_cost' => $cost]);

                    continue;
                }
                if ($line->source_inventory_batch_id !== null
                    && $this->stock->onHandByBatch($locked->tenant_id, $locked->from_warehouse_id, $line->product_variant_id, $line->source_inventory_batch_id) < (float) $line->quantity) {
                    throw ValidationException::withMessages(['lines' => 'Selected source batch has insufficient stock.']);
                }
                $this->stock->decrease(
                    $locked->tenant_id, $locked->from_warehouse_id, $line->product_variant_id,
                    (float) $line->quantity, 'transfer_out', $locked->id, $line->source_inventory_batch_id,
                    null, $line->source_warehouse_location_id,
                );
                $line->update(['unit_cost' => $cost]);
            }
            $locked->update(['status' => 'shipped', 'shipped_by' => $actorId, 'shipped_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.transfer.shipped', TransferOrder::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    public function markInTransit(TransferOrder $transfer, ?int $actorId = null): TransferOrder
    {
        return $this->transition($transfer, ['shipped'], 'in_transit', ['in_transit_at' => now()], 'in_transit', $actorId);
    }

    public function receive(TransferOrder $transfer, array $quantities, ?int $actorId = null): TransferOrder
    {
        return DB::transaction(function () use ($transfer, $quantities, $actorId) {
            $locked = $this->lock($transfer);
            $this->expectStatus($locked, ['shipped', 'in_transit', 'partial_received']);
            $before = $locked->load('lines')->toArray();
            foreach ($quantities as $lineId => $quantity) {
                $line = TransferLine::where('transfer_order_id', $locked->id)->lockForUpdate()->findOrFail($lineId);
                $quantity = (float) $quantity;
                $remaining = round((float) $line->quantity - (float) $line->received_quantity, 3);
                if ($quantity <= 0 || $quantity > $remaining) {
                    throw ValidationException::withMessages(['quantities' => "Receipt for line {$line->id} exceeds remaining quantity {$remaining}."]);
                }
                $serials = $line->serials()->with('serialNumber')->get();
                if ($serials->isNotEmpty()) {
                    $inTransit = $serials->filter(fn ($serial) => $serial->serialNumber?->status === 'transferred')->take((int) $quantity);
                    if ($inTransit->count() !== (int) $quantity) {
                        throw ValidationException::withMessages(['quantities' => 'Serialized receipt exceeds serials currently in transit.']);
                    }
                    foreach ($inTransit as $serial) {
                        $this->serials->receiveFromTransfer($locked->tenant_id, $serial->serial_number_id, $locked->id);
                    }
                } else {
                    $destinationBatchId = $this->destinationBatchId($locked, $line);
                    $this->stock->increase(
                        $locked->tenant_id, $locked->to_warehouse_id, $line->product_variant_id,
                        $quantity, (float) $line->unit_cost, 'transfer_in', $locked->id, $destinationBatchId,
                        null, $line->destination_warehouse_location_id,
                    );
                }
                $line->increment('received_quantity', $quantity);
            }
            $complete = ! $locked->lines()->whereColumn('received_quantity', '<', 'quantity')->exists();
            $locked->update([
                'status' => $complete ? 'received' : 'partial_received',
                'received_at' => $complete ? now() : null,
            ]);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.transfer.received', TransferOrder::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    public function cancel(TransferOrder $transfer, ?int $actorId = null): TransferOrder
    {
        return $this->transition($transfer, ['draft', 'approved'], 'cancelled', ['cancelled_at' => now()], 'cancelled', $actorId);
    }

    private function transition(TransferOrder $transfer, array $from, string $to, array $attributes, string $action, ?int $actorId): TransferOrder
    {
        return DB::transaction(function () use ($transfer, $from, $to, $attributes, $action, $actorId) {
            $locked = $this->lock($transfer);
            $this->expectStatus($locked, $from);
            $before = $locked->toArray();
            $locked->update($attributes + ['status' => $to]);
            $this->audit->log($locked->tenant_id, $actorId, "inventory.transfer.{$action}", TransferOrder::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh('lines');
        });
    }

    private function lock(TransferOrder $transfer): TransferOrder
    {
        return TransferOrder::withoutGlobalScopes()->where('tenant_id', $transfer->tenant_id)->lockForUpdate()->findOrFail($transfer->id);
    }

    private function expectStatus(TransferOrder $transfer, array $statuses): void
    {
        if (! in_array($transfer->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => "Transfer cannot transition from {$transfer->status}."]);
        }
    }

    private function validateWarehouses(int $tenantId, int $fromWarehouseId, int $toWarehouseId): void
    {
        $count = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('id', [$fromWarehouseId, $toWarehouseId])->count();
        if ($fromWarehouseId === $toWarehouseId || $count !== 2) {
            throw ValidationException::withMessages(['warehouse' => 'Source and destination must be different tenant warehouses.']);
        }
    }

    private function locationBelongsTo(int $tenantId, int $warehouseId, ?int $locationId): bool
    {
        return $locationId === null || WarehouseLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('is_active', true)
            ->whereKey($locationId)->exists();
    }

    /** Copy the immutable lot identity and provenance into the destination warehouse once. */
    private function destinationBatchId(TransferOrder $transfer, TransferLine $line): ?int
    {
        if ($line->source_inventory_batch_id === null) {
            return null;
        }

        $source = InventoryBatch::withoutGlobalScopes()
            ->where('tenant_id', $transfer->tenant_id)
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->where('product_variant_id', $line->product_variant_id)
            ->lockForUpdate()
            ->findOrFail($line->source_inventory_batch_id);
        $destination = InventoryBatch::withoutGlobalScopes()->firstOrCreate([
            'tenant_id' => $transfer->tenant_id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'product_variant_id' => $line->product_variant_id,
            'batch_number' => $source->batch_number,
        ], [
            'manufactured_at' => $source->manufactured_at,
            'expires_at' => $source->expires_at,
            'supplier_id' => $source->supplier_id,
            'purchase_id' => $source->purchase_id,
        ]);
        if ($line->destination_inventory_batch_id === null) {
            $line->update(['destination_inventory_batch_id' => $destination->id]);
        }

        return $destination->id;
    }
}
