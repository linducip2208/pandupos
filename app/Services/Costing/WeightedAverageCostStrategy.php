<?php

namespace App\Services\Costing;

use App\Contracts\CostingStrategy;
use App\Models\StockMovement;

final class WeightedAverageCostStrategy implements CostingStrategy
{
    public function name(): string
    {
        return 'weighted_average';
    }

    public function unitCost(int $tenantId, int $warehouseId, int $variantId): float
    {
        $base = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId);
        $inQty = (float) (clone $base)->where('movement_type', 'in')->sum('quantity');
        $outQty = (float) (clone $base)->where('movement_type', 'out')->sum('quantity');
        $remainingQty = $inQty - $outQty;
        if ($remainingQty <= 0) {
            return 0;
        }
        $inCost = (float) (clone $base)->where('movement_type', 'in')
            ->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as cost')->value('cost');
        $outCost = (float) (clone $base)->where('movement_type', 'out')
            ->selectRaw('COALESCE(SUM(quantity * unit_cost),0) as cost')->value('cost');

        return round(($inCost - $outCost) / $remainingQty, 4);
    }
}
