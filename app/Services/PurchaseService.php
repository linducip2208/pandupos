<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Stock increases ONLY on receiving, never on PO creation. */
final class PurchaseService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function createDraft(int $tenantId, int $warehouseId, int $contactId, array $lines): Purchase
    {
        // Validate tenant ownership of all references (prevent IDOR, mirrors SaleService).
        abort_unless(Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $warehouseId)->exists(), 422, 'Warehouse does not belong to tenant.');
        abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $contactId)->exists(), 422, 'Contact does not belong to tenant.');
        foreach ($lines as $l) {
            abort_unless(ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $l['product_variant_id'])->exists(), 422, 'Variant does not belong to tenant.');
            if (($l['quantity'] ?? 0) <= 0) {
                abort(422, 'Line quantity must be greater than zero.');
            }
        }

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

    public function receive(int $purchaseId, ?array $partialLines = null, ?int $tenantId = null, ?int $actorId = null, ?string $notes = null): Purchase
    {
        return DB::transaction(function () use ($purchaseId, $partialLines, $tenantId, $actorId, $notes) {
            $tenantId ??= TenantContext::id();
            $q = Purchase::withoutGlobalScopes()->lockForUpdate();
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
            $purchase = $q->findOrFail($purchaseId);

            if ($purchase->status === 'received') {
                return $purchase; // idempotent
            }
            abort_if($purchase->status === 'cancelled', 422, 'Cannot receive a cancelled purchase.');

            $map = null;
            if ($partialLines !== null) {
                $map = collect($partialLines)->keyBy('product_variant_id');
            }

            $receivable = [];
            foreach ($purchase->lines()->lockForUpdate()->get() as $line) {
                $qtyToReceive = (float) $line->quantity - (float) ($line->received_quantity ?? 0);
                if ($map !== null) {
                    $row = $map->get($line->product_variant_id);
                    if (! $row) {
                        continue;
                    }
                    $requested = (float) ($row['quantity'] ?? 0);
                    if ($requested > $qtyToReceive) {
                        throw ValidationException::withMessages([
                            'lines' => "Receipt quantity {$requested} exceeds remaining quantity {$qtyToReceive}.",
                        ]);
                    }
                    $qtyToReceive = $requested;
                }
                if ($qtyToReceive <= 0) {
                    continue;
                }
                $receivable[] = [$line, $qtyToReceive];
            }

            if ($receivable === []) {
                return $purchase->fresh('lines');
            }

            $receipt = GoodsReceipt::withoutGlobalScopes()->create([
                'tenant_id' => $purchase->tenant_id, 'purchase_id' => $purchase->id,
                'warehouse_id' => $purchase->warehouse_id, 'receipt_no' => 'TMP-'.(string) Str::uuid(),
                'received_at' => now(), 'received_by' => $actorId, 'notes' => $notes,
            ]);
            $receipt->update(['receipt_no' => 'GR-'.str_pad((string) $receipt->id, 8, '0', STR_PAD_LEFT)]);

            foreach ($receivable as [$line, $qtyToReceive]) {
                $this->stock->increase(
                    $purchase->tenant_id, $purchase->warehouse_id,
                    $line->product_variant_id, $qtyToReceive,
                    (float) $line->unit_cost, 'purchase_receipt', $receipt->id
                );
                $receipt->lines()->create([
                    'purchase_line_id' => $line->id, 'product_variant_id' => $line->product_variant_id,
                    'quantity' => $qtyToReceive, 'unit_cost' => $line->unit_cost,
                ]);
                $line->update(['received_quantity' => (float) ($line->received_quantity ?? 0) + $qtyToReceive]);
            }

            $purchase->refresh();
            $totalOrdered = (float) $purchase->lines()->sum('quantity');
            $totalReceived = (float) $purchase->lines()->sum('received_quantity');
            $status = $totalReceived <= 0 ? $purchase->status : ($totalReceived < $totalOrdered ? 'partial' : 'received');
            $purchase->update(['status' => $status]);
            $this->audit->log($purchase->tenant_id, $actorId, 'purchase.goods_receipt.posted', GoodsReceipt::class, $receipt->id, null, $receipt->fresh('lines')->toArray());

            return $purchase;
        });
    }
}
