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

    public function receive(int $purchaseId, ?array $partialLines = null): Purchase
    {
        return DB::transaction(function () use ($purchaseId, $partialLines) {
            $purchase = Purchase::withoutGlobalScopes()->lockForUpdate()->findOrFail($purchaseId);

            if ($purchase->status === 'received') {
                return $purchase; // idempotent
            }
            abort_if($purchase->status === 'cancelled', 422, 'Cannot receive a cancelled purchase.');

            $map = null;
            if ($partialLines !== null) {
                $map = collect($partialLines)->keyBy('product_variant_id');
            }

            foreach ($purchase->lines as $line) {
                $qtyToReceive = (float) $line->quantity - (float) ($line->received_quantity ?? 0);
                if ($map !== null) {
                    $row = $map->get($line->product_variant_id);
                    if (! $row) {
                        continue;
                    }
                    // Never receive more than remaining; prevents +100 twice.
                    $qtyToReceive = min($qtyToReceive, (float) ($row['quantity'] ?? 0));
                }
                if ($qtyToReceive <= 0) {
                    continue;
                }
                $this->stock->increase(
                    $purchase->tenant_id, $purchase->warehouse_id,
                    $line->product_variant_id, $qtyToReceive,
                    (float) $line->unit_cost, 'purchase_receipt', $purchase->id
                );
                $line->update(['received_quantity' => (float) ($line->received_quantity ?? 0) + $qtyToReceive]);
            }

            $purchase->refresh();
            $totalOrdered = (float) $purchase->lines()->sum('quantity');
            $totalReceived = (float) $purchase->lines()->sum('received_quantity');
            $status = $totalReceived <= 0 ? $purchase->status : ($totalReceived < $totalOrdered ? 'partial' : 'received');
            $purchase->update(['status' => $status]);

            return $purchase;
        });
    }
}
