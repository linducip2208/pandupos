<?php

namespace App\Contracts;

interface CostingStrategy
{
    public function name(): string;

    public function unitCost(int $tenantId, int $warehouseId, int $variantId): float;
}
