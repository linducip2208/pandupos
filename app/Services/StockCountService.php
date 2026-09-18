<?php

namespace App\Services;

use App\Models\SerialNumber;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockCountService
{
    public function __construct(private StockService $stock, private AuditService $audit, private SerialNumberService $serials) {}

    public function createAndSnapshot(int $tenantId, int $warehouseId, ?string $reference, ?string $notes, ?int $actorId, ?int $locationId = null): StockCount
    {
        if (! Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists()) {
            throw ValidationException::withMessages(['warehouse' => 'Warehouse must belong to the active tenant.']);
        }
        if ($locationId !== null && ! WarehouseLocation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('is_active', true)->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['warehouse_location_id' => 'Count location must be active in the selected warehouse.']);
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $reference, $notes, $actorId, $locationId) {
            $count = StockCount::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId, 'status' => 'counting',
                'warehouse_location_id' => $locationId, 'reference' => $reference, 'notes' => $notes, 'created_by' => $actorId, 'snapshot_at' => now(),
            ]);
            $nonSerial = StockMovement::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)->whereNull('serial_number_id');
            if ($locationId !== null) {
                $nonSerial->where('warehouse_location_id', $locationId);
            }
            $snapshots = $nonSerial->selectRaw('product_variant_id, inventory_batch_id, SUM(CASE WHEN movement_type = "in" THEN quantity ELSE -quantity END) AS quantity')
                ->groupBy('product_variant_id', 'inventory_batch_id')->havingRaw('SUM(CASE WHEN movement_type = "in" THEN quantity ELSE -quantity END) <> 0')->get();
            foreach ($snapshots as $snapshot) {
                $count->lines()->create([
                    'product_variant_id' => $snapshot->product_variant_id, 'inventory_batch_id' => $snapshot->inventory_batch_id,
                    'expected_quantity' => $snapshot->quantity,
                ]);
            }
            if ($locationId === null) {
                SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
                    ->whereIn('status', ['available', 'returned'])->orderBy('id')->get()
                    ->each(fn (SerialNumber $serial) => $count->lines()->create([
                        'product_variant_id' => $serial->product_variant_id, 'inventory_batch_id' => $serial->inventory_batch_id,
                        'serial_number_id' => $serial->id, 'expected_quantity' => 1,
                    ]));
            }
            $this->audit->log($tenantId, $actorId, 'inventory.count.snapshotted', StockCount::class, $count->id, null, $count->load('lines')->toArray());

            return $count->fresh('lines');
        });
    }

    public function recordCounts(StockCount $count, array $quantities, ?int $actorId): StockCount
    {
        return DB::transaction(function () use ($count, $quantities, $actorId) {
            $locked = $this->lock($count);
            $this->expect($locked, ['counting']);
            foreach ($quantities as $lineId => $quantity) {
                $line = $locked->lines()->lockForUpdate()->findOrFail($lineId);
                if ((float) $quantity < 0) {
                    throw ValidationException::withMessages(['quantities' => 'Counted quantity cannot be negative.']);
                }
                if ($line->serial_number_id !== null && ! in_array((float) $quantity, [0.0, 1.0], true)) {
                    throw ValidationException::withMessages(['quantities' => 'Serialized count lines must be counted as present (1) or missing (0).']);
                }
                $line->update([
                    'counted_quantity' => $quantity,
                    'variance_quantity' => round((float) $quantity - (float) $line->expected_quantity, 6),
                ]);
            }
            if ($locked->lines()->whereNull('counted_quantity')->exists()) {
                throw ValidationException::withMessages(['quantities' => 'Every snapshot line must be counted before review.']);
            }
            $locked->update(['status' => 'reviewed']);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.count.reviewed', StockCount::class, $locked->id, null, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    public function approve(StockCount $count, int $actorId): StockCount
    {
        if ($count->created_by !== null && $count->created_by === $actorId) {
            throw ValidationException::withMessages(['approval' => 'The count creator cannot approve their own stock count.']);
        }

        return $this->transition($count, ['reviewed'], 'approved', ['approved_by' => $actorId, 'approved_at' => now()], 'approved', $actorId);
    }

    public function post(StockCount $count, int $actorId): StockCount
    {
        return DB::transaction(function () use ($count, $actorId) {
            $locked = $this->lock($count);
            $this->expect($locked, ['approved']);
            $before = $locked->load('lines')->toArray();
            foreach ($locked->lines as $line) {
                $variance = (float) $line->variance_quantity;
                if ($line->serial_number_id !== null) {
                    if ($variance < 0) {
                        $this->serials->damageForAdjustment($locked->tenant_id, $line->serial_number_id, $locked->id);
                    }

                    continue;
                }
                if ($variance > 0) {
                    $cost = $this->stock->weightedAverageCost($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id);
                    $this->stock->increase($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, $variance, $cost, 'adjustment_in', $locked->id, $line->inventory_batch_id, null, $locked->warehouse_location_id);
                } elseif ($variance < 0) {
                    if ($locked->warehouse_location_id !== null && $this->stock->onHandAtLocation($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, $locked->warehouse_location_id) < abs($variance)) {
                        throw ValidationException::withMessages(['count' => 'Posting would make rack/bin stock negative.']);
                    }
                    $this->stock->decrease($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, abs($variance), 'adjustment_out', $locked->id, $line->inventory_batch_id, null, $locked->warehouse_location_id);
                }
            }
            $locked->update(['status' => 'posted', 'posted_by' => $actorId, 'posted_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.count.posted', StockCount::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    private function transition(StockCount $count, array $from, string $to, array $values, string $action, ?int $actorId): StockCount
    {
        return DB::transaction(function () use ($count, $from, $to, $values, $action, $actorId) {
            $locked = $this->lock($count);
            $this->expect($locked, $from);
            $locked->update($values + ['status' => $to]);
            $this->audit->log($locked->tenant_id, $actorId, "inventory.count.{$action}", StockCount::class, $locked->id, null, $locked->fresh()->toArray());

            return $locked->fresh('lines');
        });
    }

    private function lock(StockCount $count): StockCount
    {
        return StockCount::withoutGlobalScopes()->where('tenant_id', $count->tenant_id)->lockForUpdate()->findOrFail($count->id);
    }

    private function expect(StockCount $count, array $statuses): void
    {
        if (! in_array($count->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => 'Invalid stock-count transition.']);
        }
    }
}
