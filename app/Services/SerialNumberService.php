<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\SerialNumber;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SerialNumberService
{
    public function __construct(private StockService $stock) {}

    public function receive(
        int $tenantId,
        int $warehouseId,
        int $variantId,
        string $serial,
        float $unitCost,
        ?int $batchId = null,
        ?int $purchaseId = null,
    ): SerialNumber {
        $valid = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists()
            && ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($variantId)->exists()
            && ($purchaseId === null || Purchase::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($purchaseId)->exists())
            && ($batchId === null || InventoryBatch::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $variantId)
                ->whereKey($batchId)
                ->exists());
        if (! $valid) {
            throw ValidationException::withMessages(['reference' => 'Serial references must belong to the active tenant.']);
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $variantId, $serial, $unitCost, $batchId, $purchaseId) {
            $number = SerialNumber::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $variantId,
                'inventory_batch_id' => $batchId,
                'serial_number' => $serial,
                'status' => 'available',
                'purchase_id' => $purchaseId,
            ]);
            $this->stock->increase(
                $tenantId, $warehouseId, $variantId, 1, $unitCost,
                'purchase_receipt', $purchaseId, $batchId, $number->id
            );

            return $number;
        });
    }

    public function sell(int $tenantId, int $serialNumberId, int $invoiceId): SerialNumber
    {
        return DB::transaction(function () use ($tenantId, $serialNumberId, $invoiceId) {
            if (! SalesInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($invoiceId)->exists()) {
                throw ValidationException::withMessages(['sales_invoice_id' => 'Invoice must belong to the active tenant.']);
            }
            $number = SerialNumber::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if ($number->status !== 'available' && $number->status !== 'returned') {
                throw ValidationException::withMessages(['serial_number' => 'Serial number is not available for sale.']);
            }
            $this->stock->decrease(
                $tenantId, $number->warehouse_id, $number->product_variant_id, 1,
                'sale', $invoiceId, $number->inventory_batch_id, $number->id
            );
            $number->update(['status' => 'sold', 'sales_invoice_id' => $invoiceId]);

            return $number;
        });
    }

    public function transition(int $tenantId, int $serialNumberId, string $status, ?int $warehouseId = null): SerialNumber
    {
        $allowed = [
            'received' => ['available', 'damaged'],
            'available' => ['sold', 'damaged', 'transferred'],
            'sold' => ['returned'],
            'returned' => ['available', 'damaged'],
            'transferred' => ['available', 'damaged'],
            'damaged' => [],
        ];

        return DB::transaction(function () use ($tenantId, $serialNumberId, $status, $warehouseId, $allowed) {
            $number = SerialNumber::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if (! in_array($status, $allowed[$number->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "Invalid serial transition {$number->status} -> {$status}."]);
            }
            if ($warehouseId !== null && ! Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists()) {
                throw ValidationException::withMessages(['warehouse_id' => 'Warehouse must belong to the active tenant.']);
            }
            $number->update(array_filter([
                'status' => $status,
                'warehouse_id' => $warehouseId,
                'sales_invoice_id' => $status === 'available' ? null : $number->sales_invoice_id,
            ], fn ($value) => $value !== null));

            return $number;
        });
    }
}
