<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\TransferLine;
use App\Models\TransferOrder;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockTransferService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

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
                $transfer->lines()->create([
                    'product_variant_id' => $variant->id, 'quantity' => $line['quantity'],
                ]);
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
            foreach ($locked->lines as $line) {
                $cost = $this->stock->weightedAverageCost($locked->tenant_id, $locked->from_warehouse_id, $line->product_variant_id);
                $this->stock->decrease($locked->tenant_id, $locked->from_warehouse_id, $line->product_variant_id, (float) $line->quantity, 'transfer_out', $locked->id);
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
                $this->stock->increase($locked->tenant_id, $locked->to_warehouse_id, $line->product_variant_id, $quantity, (float) $line->unit_cost, 'transfer_in', $locked->id);
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
}
