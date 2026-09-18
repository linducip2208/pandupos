<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockAdjustmentService
{
    private const REASONS = ['damage', 'expired', 'loss', 'count_correction', 'opening_correction', 'other'];

    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function create(int $tenantId, int $warehouseId, string $reason, array $lines, ?string $notes, ?int $actorId): StockAdjustment
    {
        if (! in_array($reason, self::REASONS, true) || trim((string) $notes) === '') {
            throw ValidationException::withMessages(['reason' => 'A controlled reason and notes are required.']);
        }
        if (! Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists() || $lines === []) {
            throw ValidationException::withMessages(['warehouse' => 'Tenant warehouse and at least one line are required.']);
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $reason, $lines, $notes, $actorId) {
            $adjustment = StockAdjustment::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId, 'status' => 'draft',
                'reason' => $reason, 'notes' => $notes, 'requested_by' => $actorId,
            ]);
            foreach ($lines as $line) {
                $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($line['product_variant_id']);
                if (! $variant || (float) $line['quantity_change'] === 0.0) {
                    throw ValidationException::withMessages(['lines' => 'Tenant variant and non-zero quantity change are required.']);
                }
                $adjustment->lines()->create($line);
            }
            $this->audit->log($tenantId, $actorId, 'inventory.adjustment.created', StockAdjustment::class, $adjustment->id, null, $adjustment->load('lines')->toArray());

            return $adjustment->fresh('lines');
        });
    }

    public function approve(StockAdjustment $adjustment, int $actorId): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actorId) {
            $locked = $this->lock($adjustment);
            $this->expect($locked, 'draft');
            if ($locked->requested_by !== null && $locked->requested_by === $actorId) {
                throw ValidationException::withMessages(['approval' => 'The requester cannot approve their own stock adjustment.']);
            }
            $before = $locked->toArray();
            $locked->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.adjustment.approved', StockAdjustment::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh('lines');
        });
    }

    public function post(StockAdjustment $adjustment, int $actorId): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actorId) {
            $locked = $this->lock($adjustment);
            $this->expect($locked, 'approved');
            $before = $locked->load('lines')->toArray();
            foreach ($locked->lines as $line) {
                $change = (float) $line->quantity_change;
                if ($change > 0) {
                    $cost = $line->unit_cost ?? $this->stock->weightedAverageCost($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id);
                    $this->stock->increase($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, $change, (float) $cost, 'adjustment_in', $locked->id);
                } else {
                    $this->stock->decrease($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, abs($change), 'adjustment_out', $locked->id);
                }
            }
            $locked->update(['status' => 'posted', 'posted_by' => $actorId, 'posted_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.adjustment.posted', StockAdjustment::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    private function lock(StockAdjustment $adjustment): StockAdjustment
    {
        return StockAdjustment::withoutGlobalScopes()->where('tenant_id', $adjustment->tenant_id)->lockForUpdate()->findOrFail($adjustment->id);
    }

    private function expect(StockAdjustment $adjustment, string $status): void
    {
        if ($adjustment->status !== $status) {
            throw ValidationException::withMessages(['status' => "Adjustment must be {$status}."]);
        }
    }
}
