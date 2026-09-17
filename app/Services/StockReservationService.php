<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockReservationService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function reserve(array $data, ?int $actorId = null): StockReservation
    {
        $tenantId = (int) $data['tenant_id'];
        $this->validateReferences($tenantId, (int) $data['warehouse_id'], (int) $data['product_variant_id'], $data['warehouse_location_id'] ?? null, $data['inventory_batch_id'] ?? null);

        return DB::transaction(function () use ($data, $tenantId, $actorId) {
            ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->whereKey($data['product_variant_id'])->lockForUpdate()->firstOrFail();

            if (! empty($data['idempotency_key'])) {
                $existing = StockReservation::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $available = $this->stock->availableToPromise($tenantId, (int) $data['warehouse_id'], (int) $data['product_variant_id']);
            if ($available < (float) $data['quantity']) {
                throw ValidationException::withMessages(['quantity' => "Insufficient available stock ({$available})."]);
            }

            $reservation = StockReservation::withoutGlobalScopes()->create($data + ['status' => 'active']);
            $this->audit->log($tenantId, $actorId, 'inventory.reservation.created', StockReservation::class, $reservation->id, null, $reservation->toArray());

            return $reservation;
        });
    }

    public function release(StockReservation $reservation, ?int $actorId = null): StockReservation
    {
        return $this->finish($reservation, 'released', 'released_at', $actorId);
    }

    public function consume(StockReservation $reservation, string $referenceType, int $referenceId, ?int $actorId = null): StockReservation
    {
        return DB::transaction(function () use ($reservation, $referenceType, $referenceId, $actorId) {
            $locked = StockReservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertActive($locked);
            $before = $locked->toArray();
            $locked->update(['status' => 'consuming']);
            $this->stock->decrease(
                $locked->tenant_id, $locked->warehouse_id, $locked->product_variant_id,
                (float) $locked->quantity, $referenceType, $referenceId,
                $locked->inventory_batch_id, null, $locked->warehouse_location_id
            );
            $locked->update(['status' => 'consumed', 'consumed_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'inventory.reservation.consumed', StockReservation::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function expireDue(int $tenantId): int
    {
        return StockReservation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'released_at' => now(), 'updated_at' => now()]);
    }

    private function finish(StockReservation $reservation, string $status, string $timestamp, ?int $actorId): StockReservation
    {
        return DB::transaction(function () use ($reservation, $status, $timestamp, $actorId) {
            $locked = StockReservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertActive($locked);
            $before = $locked->toArray();
            $locked->update(['status' => $status, $timestamp => now()]);
            $this->audit->log($locked->tenant_id, $actorId, "inventory.reservation.{$status}", StockReservation::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    private function assertActive(StockReservation $reservation): void
    {
        if ($reservation->status !== 'active' || ($reservation->expires_at && $reservation->expires_at->isPast())) {
            throw ValidationException::withMessages(['reservation' => 'Reservation is not active.']);
        }
    }

    private function validateReferences(int $tenantId, int $warehouseId, int $variantId, ?int $locationId, ?int $batchId): void
    {
        $valid = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists()
            && ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($variantId)->exists()
            && ($locationId === null || WarehouseLocation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->whereKey($locationId)->exists())
            && ($batchId === null || InventoryBatch::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)->whereKey($batchId)->exists());
        if (! $valid) {
            throw ValidationException::withMessages(['reference' => 'Reservation references must belong to the active tenant and warehouse.']);
        }
    }
}
