<?php

namespace App\Services;

use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

/** Stock increases ONLY on receiving, never on PO creation. */
final class PurchaseService
{
    public function __construct(private StockService $stock) {}

    public function createDraft(int $tenantId, int $warehouseId, int $contactId, array $lines): Purchase
    {
        return DB::transaction(function () use ($tenantId, $warehouseId, $contactId, $lines) {
            $total = collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_cost']);
            $purchase = Purchase::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId,
                'contact_id' => $contactId, 'status' => 'ordered', 'total' => $total,
            ]);
            foreach ($lines as $l) {
                $purchase->lines()->create($l);
            }

            return $purchase;
        });
    }

    public function receive(int $purchaseId): Purchase
    {
        return DB::transaction(function () use ($purchaseId) {
            $purchase = Purchase::withoutGlobalScopes()->findOrFail($purchaseId);

            if ($purchase->status === 'received') {
                return $purchase; // idempotent
            }

            foreach ($purchase->lines as $line) {
                $this->stock->increase(
                    $purchase->tenant_id, $purchase->warehouse_id,
                    $line->product_variant_id, (float) $line->quantity,
                    (float) $line->unit_cost, 'purchase_receipt', $purchase->id
                );
            }

            $purchase->update(['status' => 'received']);

            return $purchase;
        });
    }
}
