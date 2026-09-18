<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\SerialNumber;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockAdjustmentService
{
    private const REASONS = ['damage', 'expired', 'loss', 'count_correction', 'opening_correction', 'other'];

    public function __construct(private StockService $stock, private AuditService $audit, private SerialNumberService $serials) {}

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
                $this->validateLine($tenantId, $warehouseId, $line);
                $adjustment->lines()->create($line);
            }
            $this->audit->log($tenantId, $actorId, 'inventory.adjustment.created', StockAdjustment::class, $adjustment->id, null, $adjustment->load('lines')->toArray());

            return $adjustment->fresh('lines');
        });
    }

    public function submit(StockAdjustment $adjustment, int $actorId): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actorId) {
            $locked = $this->lock($adjustment);
            $this->expect($locked, 'draft');
            if ($locked->requested_by !== null && $locked->requested_by !== $actorId) {
                throw ValidationException::withMessages(['review' => 'Only the adjustment requester can submit it for review.']);
            }
            $before = $locked->load('lines')->toArray();
            $locked->update(['status' => 'reviewed']);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.adjustment.reviewed', StockAdjustment::class, $locked->id, $before, $locked->fresh('lines')->toArray());

            return $locked->fresh('lines');
        });
    }

    public function approve(StockAdjustment $adjustment, int $actorId): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actorId) {
            $locked = $this->lock($adjustment);
            $this->expect($locked, 'reviewed');
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
                $this->validateLine($locked->tenant_id, $locked->warehouse_id, $line->toArray());
                $change = (float) $line->quantity_change;
                if ($line->serial_number_id !== null) {
                    $this->serials->damageForAdjustment($locked->tenant_id, $line->serial_number_id, $locked->id);

                    continue;
                }
                if ($change > 0) {
                    $cost = $line->unit_cost ?? $this->stock->weightedAverageCost($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id);
                    $this->stock->increase($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, $change, (float) $cost, 'adjustment_in', $locked->id, $line->inventory_batch_id, null, $line->warehouse_location_id);
                } else {
                    $this->stock->decrease($locked->tenant_id, $locked->warehouse_id, $line->product_variant_id, abs($change), 'adjustment_out', $locked->id, $line->inventory_batch_id, null, $line->warehouse_location_id);
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

    private function validateLine(int $tenantId, int $warehouseId, array $line): void
    {
        $variantId = (int) ($line['product_variant_id'] ?? 0);
        $quantity = (float) ($line['quantity_change'] ?? 0);
        $batchId = isset($line['inventory_batch_id']) ? (int) $line['inventory_batch_id'] : null;
        $serialId = isset($line['serial_number_id']) ? (int) $line['serial_number_id'] : null;
        $locationId = isset($line['warehouse_location_id']) ? (int) $line['warehouse_location_id'] : null;
        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($variantId);
        if (! $variant || $quantity === 0.0) {
            throw ValidationException::withMessages(['lines' => 'Tenant variant and non-zero quantity change are required.']);
        }
        if ($batchId !== null && ! InventoryBatch::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)->whereKey($batchId)->exists()) {
            throw ValidationException::withMessages(['lines' => 'Adjustment batch must belong to its tenant warehouse and variant.']);
        }
        if ($locationId !== null && ! WarehouseLocation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('is_active', true)->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['lines' => 'Adjustment rack/bin must be active in the selected warehouse.']);
        }
        if ($serialId !== null) {
            $serial = SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)->find($serialId);
            if (! $serial || $quantity !== -1.0 || $locationId !== null || ($batchId !== null && $batchId !== $serial->inventory_batch_id)) {
                throw ValidationException::withMessages(['lines' => 'A serial adjustment must remove exactly one matching warehouse serial without a rack/bin override.']);
            }
        }
    }
}
