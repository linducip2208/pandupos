<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BatchInventoryService
{
    public function __construct(private StockService $stock) {}

    public function receive(
        int $tenantId,
        int $warehouseId,
        int $variantId,
        string $batchNumber,
        float $quantity,
        float $unitCost,
        ?string $manufacturedAt = null,
        ?string $expiresAt = null,
        ?int $supplierId = null,
        ?int $purchaseId = null,
    ): InventoryBatch {
        $this->validateReferences($tenantId, $warehouseId, $variantId, $supplierId, $purchaseId);

        return DB::transaction(function () use ($tenantId, $warehouseId, $variantId, $batchNumber, $quantity, $unitCost, $manufacturedAt, $expiresAt, $supplierId, $purchaseId) {
            $batch = InventoryBatch::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => $variantId,
                    'batch_number' => $batchNumber,
                ],
                [
                    'manufactured_at' => $manufacturedAt,
                    'expires_at' => $expiresAt,
                    'supplier_id' => $supplierId,
                    'purchase_id' => $purchaseId,
                ]
            );
            $this->stock->increase(
                $tenantId, $warehouseId, $variantId, $quantity, $unitCost,
                'purchase_receipt', $purchaseId, $batch->id
            );

            return $batch;
        });
    }

    public function allocateFefo(
        int $tenantId,
        int $warehouseId,
        int $variantId,
        float $quantity,
        string $referenceType,
        int $referenceId,
        bool $allowExpired = false,
    ): array {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $variantId, $quantity, $referenceType, $referenceId, $allowExpired) {
            $remaining = $quantity;
            $allocations = [];
            $batches = InventoryBatch::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $variantId)
                ->when(! $allowExpired, fn ($q) => $q->where(fn ($date) => $date->whereNull('expires_at')->orWhereDate('expires_at', '>=', today())))
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expires_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                $available = $this->stock->onHandByBatch($tenantId, $warehouseId, $variantId, $batch->id);
                $take = min($remaining, $available);
                if ($take <= 0) {
                    continue;
                }
                $this->stock->decrease(
                    $tenantId, $warehouseId, $variantId, $take,
                    $referenceType, $referenceId, $batch->id
                );
                $allocations[] = ['batch_id' => $batch->id, 'quantity' => $take];
                $remaining = round($remaining - $take, 6);
                if ($remaining <= 0) {
                    break;
                }
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages([
                    'quantity' => $allowExpired
                        ? 'Insufficient batch stock.'
                        : 'Insufficient non-expired batch stock. Expired stock requires controlled override.',
                ]);
            }

            return $allocations;
        });
    }

    public function allocateSpecific(
        int $tenantId,
        int $warehouseId,
        int $variantId,
        int $batchId,
        float $quantity,
        string $referenceType,
        int $referenceId,
    ): array {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $variantId, $batchId, $quantity, $referenceType, $referenceId) {
            $batch = InventoryBatch::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $variantId)->lockForUpdate()->findOrFail($batchId);
            if ($batch->expires_at?->isBefore(today())) {
                throw ValidationException::withMessages(['inventory_batch_id' => 'Expired batch cannot be sold without a controlled override.']);
            }
            if ($this->stock->onHandByBatch($tenantId, $warehouseId, $variantId, $batch->id) < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Selected batch has insufficient stock.']);
            }
            $this->stock->decrease($tenantId, $warehouseId, $variantId, $quantity, $referenceType, $referenceId, $batch->id);

            return [['batch_id' => $batch->id, 'quantity' => $quantity]];
        });
    }

    public function hasTrackedBatches(int $tenantId, int $warehouseId, int $variantId): bool
    {
        return InventoryBatch::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)->exists();
    }

    public function expirySummary(int $tenantId, int $days): array
    {
        return InventoryBatch::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', today()->addDays($days))
            ->orderBy('expires_at')
            ->get()
            ->map(fn (InventoryBatch $batch) => [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'expires_at' => $batch->expires_at?->toDateString(),
                'expired' => $batch->expires_at?->isBefore(today()) ?? false,
                'quantity' => $this->stock->onHandByBatch(
                    $tenantId, $batch->warehouse_id, $batch->product_variant_id, $batch->id
                ),
            ])->filter(fn (array $row) => $row['quantity'] > 0)->values()->all();
    }

    private function validateReferences(int $tenantId, int $warehouseId, int $variantId, ?int $supplierId, ?int $purchaseId): void
    {
        $valid = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists()
            && ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($variantId)->exists()
            && ($supplierId === null || Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($supplierId)->exists())
            && ($purchaseId === null || Purchase::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($purchaseId)->exists());
        if (! $valid) {
            throw ValidationException::withMessages(['reference' => 'Batch references must belong to the active tenant.']);
        }
    }
}
