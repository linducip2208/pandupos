<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\SerialNumber;
use App\Models\TransferOrder;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SerialNumberService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function receive(
        int $tenantId,
        int $warehouseId,
        int $variantId,
        string $serial,
        float $unitCost,
        ?int $batchId = null,
        ?int $purchaseId = null,
        ?int $locationId = null,
        ?int $actorId = null,
        bool $recordStock = true,
        ?int $stockReferenceId = null,
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
        $valid = $valid && ($locationId === null || WarehouseLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('is_active', true)->whereKey($locationId)->exists());
        if (! $valid) {
            throw ValidationException::withMessages(['reference' => 'Serial references must belong to the active tenant.']);
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $variantId, $serial, $unitCost, $batchId, $purchaseId, $locationId, $actorId, $recordStock, $stockReferenceId) {
            $number = SerialNumber::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'product_variant_id' => $variantId,
                'inventory_batch_id' => $batchId,
                'serial_number' => $serial,
                'status' => 'available',
                'purchase_id' => $purchaseId,
            ]);
            if ($recordStock) {
                $this->stock->increase(
                    $tenantId, $warehouseId, $variantId, 1, $unitCost,
                    'purchase_receipt', $stockReferenceId ?? $purchaseId, $batchId, $number->id, $locationId
                );
            }
            $this->audit->log($tenantId, $actorId, 'inventory.serial.received', SerialNumber::class, $number->id, null, $number->toArray());

            return $number;
        });
    }

    public function reserve(int $tenantId, int $serialNumberId, string $referenceType, int $referenceId, ?int $actorId = null): SerialNumber
    {
        return DB::transaction(function () use ($tenantId, $serialNumberId, $referenceType, $referenceId, $actorId) {
            $number = SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if ($number->status === 'reserved') {
                abort_unless($number->reserved_reference_type === $referenceType && (int) $number->reserved_reference_id === $referenceId, 422, 'Serial is reserved by another transaction.');

                return $number;
            }
            if (! in_array($number->status, ['available', 'returned'], true)) {
                throw ValidationException::withMessages(['serial_number' => 'Serial number is not available for reservation.']);
            }
            $before = $number->toArray();
            $number->update(['status' => 'reserved', 'reserved_reference_type' => $referenceType, 'reserved_reference_id' => $referenceId, 'reserved_at' => now()]);
            $this->audit->log($tenantId, $actorId, 'inventory.serial.reserved', SerialNumber::class, $number->id, $before, $number->fresh()->toArray());

            return $number->fresh();
        });
    }

    public function releaseReservation(int $tenantId, int $serialNumberId, ?int $actorId = null): SerialNumber
    {
        return DB::transaction(function () use ($tenantId, $serialNumberId, $actorId) {
            $number = SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if ($number->status !== 'reserved') {
                throw ValidationException::withMessages(['serial_number' => 'Only a reserved serial can be released.']);
            }
            $before = $number->toArray();
            $number->update(['status' => 'available', 'reserved_reference_type' => null, 'reserved_reference_id' => null, 'reserved_at' => null]);
            $this->audit->log($tenantId, $actorId, 'inventory.serial.released', SerialNumber::class, $number->id, $before, $number->fresh()->toArray());

            return $number->fresh();
        });
    }

    public function sell(int $tenantId, int $serialNumberId, int $invoiceId, ?int $actorId = null): SerialNumber
    {
        return DB::transaction(function () use ($tenantId, $serialNumberId, $invoiceId, $actorId) {
            if (! SalesInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($invoiceId)->exists()) {
                throw ValidationException::withMessages(['sales_invoice_id' => 'Invoice must belong to the active tenant.']);
            }
            $number = SerialNumber::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if (! in_array($number->status, ['available', 'returned', 'reserved'], true)) {
                throw ValidationException::withMessages(['serial_number' => 'Serial number is not available for sale.']);
            }
            $before = $number->toArray();
            $this->stock->decrease(
                $tenantId, $number->warehouse_id, $number->product_variant_id, 1,
                'sale', $invoiceId, $number->inventory_batch_id, $number->id, $number->warehouse_location_id
            );
            $number->update(['status' => 'sold', 'sales_invoice_id' => $invoiceId, 'reserved_reference_type' => null, 'reserved_reference_id' => null, 'reserved_at' => null]);
            $this->audit->log($tenantId, $actorId, 'inventory.serial.sold', SerialNumber::class, $number->id, $before, $number->fresh()->toArray());

            return $number;
        });
    }

    /** Restore the exact serial and its historical cost as part of a sale reversal. */
    public function restoreFromSale(
        int $tenantId,
        int $serialNumberId,
        int $invoiceId,
        float $unitCost,
        string $referenceType,
    ): SerialNumber {
        return DB::transaction(function () use ($tenantId, $serialNumberId, $invoiceId, $unitCost, $referenceType) {
            $number = SerialNumber::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if ($number->status !== 'sold' || (int) $number->sales_invoice_id !== $invoiceId) {
                throw ValidationException::withMessages(['serial_number' => 'Serial is not sold on this invoice.']);
            }
            $before = $number->toArray();
            $this->stock->increase(
                $tenantId, $number->warehouse_id, $number->product_variant_id, 1, $unitCost,
                $referenceType, $invoiceId, $number->inventory_batch_id, $number->id, $number->warehouse_location_id,
            );
            $number->update([
                'status' => $referenceType === 'sale_void' ? 'available' : 'returned',
                'sales_invoice_id' => null,
            ]);
            $this->audit->log($tenantId, null, "inventory.serial.{$referenceType}", SerialNumber::class, $number->id, $before, $number->fresh()->toArray());

            return $number;
        });
    }

    public function shipForTransfer(int $tenantId, int $serialNumberId, int $transferId, ?int $sourceLocationId = null): SerialNumber
    {
        return DB::transaction(function () use ($tenantId, $serialNumberId, $transferId, $sourceLocationId) {
            $transfer = TransferOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($transferId);
            $number = SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if ($number->status !== 'available' || $number->warehouse_id !== $transfer->from_warehouse_id) {
                throw ValidationException::withMessages(['serial_number' => 'Serial is not available in the transfer source warehouse.']);
            }
            if ($sourceLocationId !== null && (int) $number->warehouse_location_id !== $sourceLocationId) {
                throw ValidationException::withMessages(['serial_number' => 'Serial number is not stored in the selected source rack/bin.']);
            }
            $before = $number->toArray();
            $this->stock->decrease(
                $tenantId, $number->warehouse_id, $number->product_variant_id, 1,
                'transfer_out', $transferId, $number->inventory_batch_id, $number->id, $number->warehouse_location_id,
            );
            $number->update(['status' => 'transferred']);
            $this->audit->log($tenantId, null, 'inventory.serial.transferred', SerialNumber::class, $number->id, $before, $number->fresh()->toArray());

            return $number;
        });
    }

    /** Remove one available serial through an approved stock adjustment. */
    public function damageForAdjustment(int $tenantId, int $serialNumberId, int $adjustmentId): SerialNumber
    {
        return DB::transaction(function () use ($tenantId, $serialNumberId, $adjustmentId) {
            $number = SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if (! in_array($number->status, ['available', 'returned'], true)) {
                throw ValidationException::withMessages(['serial_number' => 'Only an available serial can be removed by an adjustment.']);
            }
            $before = $number->toArray();
            $this->stock->decrease(
                $tenantId, $number->warehouse_id, $number->product_variant_id, 1,
                'adjustment_out', $adjustmentId, $number->inventory_batch_id, $number->id,
            );
            $number->update(['status' => 'damaged']);
            $this->audit->log($tenantId, null, 'inventory.serial.damaged', SerialNumber::class, $number->id, $before, $number->fresh()->toArray());

            return $number;
        });
    }

    public function receiveFromTransfer(int $tenantId, int $serialNumberId, int $transferId, ?int $destinationLocationId = null): SerialNumber
    {
        return DB::transaction(function () use ($tenantId, $serialNumberId, $transferId, $destinationLocationId) {
            $transfer = TransferOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($transferId);
            $number = SerialNumber::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($serialNumberId);
            if ($number->status !== 'transferred' || $number->warehouse_id !== $transfer->from_warehouse_id) {
                throw ValidationException::withMessages(['serial_number' => 'Serial is not in transit for this transfer.']);
            }
            if ($destinationLocationId !== null && ! WarehouseLocation::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('warehouse_id', $transfer->to_warehouse_id)->where('is_active', true)->whereKey($destinationLocationId)->exists()) {
                throw ValidationException::withMessages(['warehouse_location_id' => 'Destination rack/bin must belong to the destination warehouse.']);
            }
            $before = $number->toArray();
            $destinationBatchId = $this->destinationBatchId($number, $transfer->to_warehouse_id);
            $this->stock->increase(
                $tenantId, $transfer->to_warehouse_id, $number->product_variant_id, 1,
                $this->stock->weightedAverageCost($tenantId, $transfer->from_warehouse_id, $number->product_variant_id),
                'transfer_in', $transferId, $destinationBatchId, $number->id, $destinationLocationId,
            );
            $number->update([
                'status' => 'available', 'warehouse_id' => $transfer->to_warehouse_id,
                'inventory_batch_id' => $destinationBatchId, 'warehouse_location_id' => $destinationLocationId,
            ]);
            $this->audit->log($tenantId, null, 'inventory.serial.transfer_received', SerialNumber::class, $number->id, $before, $number->fresh()->toArray());

            return $number;
        });
    }

    public function transition(int $tenantId, int $serialNumberId, string $status, ?int $warehouseId = null): SerialNumber
    {
        $allowed = [
            'received' => ['available', 'damaged'],
            'available' => ['sold', 'damaged', 'transferred', 'returned_to_supplier'],
            'sold' => ['returned'],
            'returned' => ['available', 'damaged', 'returned_to_supplier'],
            'transferred' => ['available', 'damaged'],
            'damaged' => [],
            'returned_to_supplier' => [],
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
            $before = $number->toArray();
            $number->update(array_filter([
                'status' => $status,
                'warehouse_id' => $warehouseId,
                'sales_invoice_id' => $status === 'available' ? null : $number->sales_invoice_id,
            ], fn ($value) => $value !== null));
            $this->audit->log($tenantId, null, "inventory.serial.transitioned.{$status}", SerialNumber::class, $number->id, $before, $number->fresh()->toArray());

            return $number;
        });
    }

    private function destinationBatchId(SerialNumber $number, int $destinationWarehouseId): ?int
    {
        if ($number->inventory_batch_id === null) {
            return null;
        }
        $source = InventoryBatch::withoutGlobalScopes()->where('tenant_id', $number->tenant_id)
            ->where('warehouse_id', $number->warehouse_id)->lockForUpdate()->findOrFail($number->inventory_batch_id);

        return InventoryBatch::withoutGlobalScopes()->firstOrCreate([
            'tenant_id' => $number->tenant_id,
            'warehouse_id' => $destinationWarehouseId,
            'product_variant_id' => $number->product_variant_id,
            'batch_number' => $source->batch_number,
        ], [
            'manufactured_at' => $source->manufactured_at,
            'expires_at' => $source->expires_at,
            'supplier_id' => $source->supplier_id,
            'purchase_id' => $source->purchase_id,
        ])->id;
    }
}
