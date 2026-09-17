<?php

namespace App\Services;

use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockCountService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function createAndSnapshot(int $tenantId, int $warehouseId, ?string $reference, ?string $notes, ?int $actorId): StockCount
    {
        if (! Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists()) {
            throw ValidationException::withMessages(['warehouse' => 'Warehouse must belong to the active tenant.']);
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $reference, $notes, $actorId) {
            $count = StockCount::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId, 'status' => 'counting',
                'reference' => $reference, 'notes' => $notes, 'created_by' => $actorId, 'snapshot_at' => now(),
            ]);
            $variantIds = StockMovement::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)->distinct()->pluck('product_variant_id');
            foreach ($variantIds as $variantId) {
                $count->lines()->create([
                    'product_variant_id' => $variantId,
                    'expected_quantity' => $this->stock->onHand($tenantId, $warehouseId, $variantId),
                ]);
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
                if ($variance > 0) {
                    $cost = $this->stock->weightedAverageCost($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id);
                    $this->stock->increase($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, $variance, $cost, 'adjustment_in', $locked->id);
                } elseif ($variance < 0) {
                    $this->stock->decrease($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, abs($variance), 'adjustment_out', $locked->id);
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
