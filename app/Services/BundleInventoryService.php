<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

final class BundleInventoryService
{
    public function __construct(private StockService $stock) {}

    public function decrease(int $tenantId, int $warehouseId, ProductVariant $bundleVariant, float $quantity, string $referenceType, int $referenceId): void
    {
        $items = $bundleVariant->product()->withoutGlobalScopes()->with('bundleItems')->firstOrFail()->bundleItems;
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['bundle' => 'Bundle product has no components.']);
        }

        foreach ($items->sortBy('component_variant_id') as $item) {
            $this->stock->decrease(
                $tenantId,
                $warehouseId,
                $item->component_variant_id,
                (float) $item->quantity * $quantity,
                $referenceType,
                $referenceId
            );
        }
    }

    public function increase(int $tenantId, int $warehouseId, ProductVariant $bundleVariant, float $quantity, string $referenceType, int $referenceId): void
    {
        $items = $bundleVariant->product()->withoutGlobalScopes()->with('bundleItems')->firstOrFail()->bundleItems;
        foreach ($items as $item) {
            $cost = $this->stock->weightedAverageCost($tenantId, $warehouseId, $item->component_variant_id);
            $this->stock->increase(
                $tenantId,
                $warehouseId,
                $item->component_variant_id,
                (float) $item->quantity * $quantity,
                $cost,
                $referenceType,
                $referenceId
            );
        }
    }
}
