<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/** Append-only ledger. Stock on hand = SUM(in) - SUM(out). Never update qty in place. */
final class StockService
{
    public function increase(int $tenantId, int $warehouseId, int $variantId, float $qty, float $unitCost, string $refType, ?int $refId): StockMovement
    {
        $this->validateMutation($tenantId, $warehouseId, $variantId, $qty);

        return DB::transaction(function () use ($tenantId, $warehouseId, $variantId, $qty, $unitCost, $refType, $refId) {
            $this->lockVariant($tenantId, $variantId, $warehouseId);

            return $this->record($tenantId, $warehouseId, $variantId, $qty, max(0, $unitCost), 'in', $refType, $refId);
        });
    }

    public function decrease(int $tenantId, int $warehouseId, int $variantId, float $qty, string $refType, ?int $refId): StockMovement
    {
        $this->validateMutation($tenantId, $warehouseId, $variantId, $qty);

        return DB::transaction(function () use ($tenantId, $warehouseId, $variantId, $qty, $refType, $refId) {
            $this->lockVariant($tenantId, $variantId, $warehouseId);

            $onHand = $this->onHandLocked($tenantId, $warehouseId, $variantId);
            if ($onHand < $qty) {
                abort(422, "Insufficient stock (on hand: {$onHand}, requested: {$qty}). Oversell rejected.");
            }

            // Weighted-average costing for reproducible COGS/valuation (FIFO optional future).
            $unitCost = $this->weightedAverageCost($tenantId, $warehouseId, $variantId);

            return $this->record($tenantId, $warehouseId, $variantId, $qty, $unitCost, 'out', $refType, $refId);
        });
    }

    public function onHand(int $tenantId, int $warehouseId, int $variantId): float
    {
        $in = (float) StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)->where('movement_type', 'in')->sum('quantity');
        $out = (float) StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)->where('movement_type', 'out')->sum('quantity');

        return round($in - $out, 3);
    }

    public function weightedAverageCost(int $tenantId, int $warehouseId, int $variantId): float
    {
        $inQty = (float) StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)->where('movement_type', 'in')->sum('quantity');
        if ($inQty <= 0) {
            return 0;
        }
        $inCost = (float) StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)->where('movement_type', 'in')
            ->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as c')->value('c');
        $outCost = (float) StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)->where('movement_type', 'out')
            ->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as c')->value('c');
        $outQty = (float) StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)->where('movement_type', 'out')->sum('quantity');

        $remainingQty = $inQty - $outQty;
        if ($remainingQty <= 0) {
            return 0;
        }

        return round(($inCost - $outCost) / $remainingQty, 2);
    }

    public function valuation(int $tenantId, ?int $warehouseId = null): float
    {
        $q = StockMovement::withoutGlobalScopes()->where('tenant_id', $tenantId);
        if ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }
        $inCost = (float) (clone $q)->where('movement_type', 'in')->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as c')->value('c');
        $outCost = (float) (clone $q)->where('movement_type', 'out')->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as c')->value('c');

        return round($inCost - $outCost, 2);
    }

    public function transfer(int $tenantId, int $fromWarehouse, int $toWarehouse, int $variantId, float $qty, int $transferId): void
    {
        if ($fromWarehouse === $toWarehouse) {
            abort(422, 'Source and destination warehouse must differ.');
        }
        DB::transaction(function () use ($tenantId, $fromWarehouse, $toWarehouse, $variantId, $qty, $transferId) {
            // Sort lock order to prevent deadlock on opposite transfers (A->B vs B->A).
            $ordered = [$fromWarehouse, $toWarehouse];
            sort($ordered);
            foreach ($ordered as $wid) {
                $this->lockVariant($tenantId, $variantId, $wid);
            }
            $onHand = $this->onHandLocked($tenantId, $fromWarehouse, $variantId);
            if ($onHand < $qty) {
                abort(422, "Insufficient stock (on hand: {$onHand}, requested: {$qty}). Oversell rejected.");
            }
            $unitCost = $this->weightedAverageCost($tenantId, $fromWarehouse, $variantId);
            $this->record($tenantId, $fromWarehouse, $variantId, $qty, $unitCost, 'out', 'transfer', $transferId);
            $this->record($tenantId, $toWarehouse, $variantId, $qty, $unitCost, 'in', 'transfer', $transferId);
        });
    }

    private function record(int $tenantId, int $warehouseId, int $variantId, float $qty, float $cost, string $type, string $refType, ?int $refId): StockMovement
    {
        return StockMovement::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId, 'reference_type' => $refType,
            'reference_id' => $refId, 'movement_type' => $type,
            'quantity' => $qty, 'unit_cost' => $cost, 'occurred_at' => now(),
        ]);
    }

    private function validateMutation(int $tenantId, int $warehouseId, int $variantId, float $qty): void
    {
        if ($qty <= 0) {
            abort(422, 'Quantity must be greater than zero.');
        }
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($warehouseId);
        abort_unless($warehouse, 422, 'Warehouse does not belong to tenant.');
        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($variantId);
        abort_unless($variant, 422, 'Product variant does not belong to tenant.');
    }

    private function lockVariant(int $tenantId, int $variantId, int $warehouseId): void
    {
        // Serialize concurrent mutations per (warehouse, variant) to prevent oversell races.
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('id', $variantId)->lockForUpdate()->first();
        abort_unless($variant, 422, 'Product variant does not belong to tenant.');
        $warehouse = Warehouse::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('id', $warehouseId)->lockForUpdate()->first();
        abort_unless($warehouse, 422, 'Warehouse does not belong to tenant.');
    }

    private function onHandLocked(int $tenantId, int $warehouseId, int $variantId): float
    {
        return $this->onHand($tenantId, $warehouseId, $variantId);
    }
}
