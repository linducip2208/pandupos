<?php

namespace App\Services;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/** Append-only ledger. Stock on hand = SUM(in) - SUM(out). Never update qty in place. */
final class StockService
{
    public function increase(int $tenantId, int $warehouseId, int $variantId, float $qty, float $unitCost, string $refType, ?int $refId): StockMovement
    {
        return $this->record($tenantId, $warehouseId, $variantId, $qty, $unitCost, 'in', $refType, $refId);
    }

    public function decrease(int $tenantId, int $warehouseId, int $variantId, float $qty, string $refType, ?int $refId): StockMovement
    {
        $onHand = $this->onHand($tenantId, $warehouseId, $variantId);
        if ($onHand < $qty) {
            abort(422, "Insufficient stock (on hand: {$onHand}, requested: {$qty}). Oversell rejected.");
        }

        return $this->record($tenantId, $warehouseId, $variantId, $qty, 0, 'out', $refType, $refId);
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

    public function transfer(int $tenantId, int $fromWarehouse, int $toWarehouse, int $variantId, float $qty, int $transferId): void
    {
        DB::transaction(function () use ($tenantId, $fromWarehouse, $toWarehouse, $variantId, $qty, $transferId) {
            $this->decrease($tenantId, $fromWarehouse, $variantId, $qty, 'transfer', $transferId);
            $this->increase($tenantId, $toWarehouse, $variantId, $qty, 0, 'transfer', $transferId);
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
}
