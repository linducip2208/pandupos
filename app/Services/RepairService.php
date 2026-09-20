<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\RepairOrder;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repair shop: intake → diagnosis → work → ready → delivered, with parts
 * from warehouse stock (deducted once, at delivery), labor, warranty
 * handling and payment balance. Parts pricing snapshots sell price.
 */
final class RepairService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function intake(int $tenantId, array $data, ?int $actorId = null): RepairOrder
    {
        $complaint = trim((string) ($data['complaint'] ?? ''));
        abort_if($complaint === '', 422, 'Complaint description is required.');
        $contactId = isset($data['contact_id']) ? (int) $data['contact_id'] : null;
        if ($contactId) {
            abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($contactId)->exists(), 422, 'Contact does not belong to tenant.');
        }
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        abort_unless(Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists(), 422, 'Warehouse does not belong to tenant.');

        $order = RepairOrder::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'number' => $this->nextNumber(),
            'contact_id' => $contactId, 'device_brand' => $data['device_brand'] ?? null,
            'device_model' => $data['device_model'] ?? null, 'device_identifier' => $data['device_identifier'] ?? null,
            'complaint' => $complaint, 'status' => RepairOrder::RECEIVED,
            'warranty' => (bool) ($data['warranty'] ?? false),
            'warehouse_id' => $warehouseId, 'technician_id' => $data['technician_id'] ?? $actorId,
            'promised_at' => $data['promised_at'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'repair.intake', RepairOrder::class, $order->id, null, ['number' => $order->number]);

        return $order;
    }

    public function diagnose(RepairOrder $order, string $diagnosis, float $laborCost, ?int $actorId = null): RepairOrder
    {
        $locked = $this->locked($order->id);
        abort_unless(in_array($locked->status, [RepairOrder::RECEIVED, RepairOrder::DIAGNOSED], true), 422, 'Diagnosis is only possible on received orders.');
        $diagnosis = trim($diagnosis);
        abort_if($diagnosis === '', 422, 'Diagnosis is required.');
        abort_if($laborCost < 0, 422, 'Labor cost cannot be negative.');
        $before = $locked->toArray();
        $locked->update(['diagnosis' => $diagnosis, 'labor_cost' => round($laborCost, 2), 'status' => RepairOrder::DIAGNOSED]);
        $this->retotal($locked);
        $this->audit->log($locked->tenant_id, $actorId, 'repair.diagnosed', RepairOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function start(RepairOrder $order, ?int $actorId = null): RepairOrder
    {
        $locked = $this->locked($order->id);
        abort_unless(in_array($locked->status, [RepairOrder::DIAGNOSED, RepairOrder::WAITING_PARTS], true), 422, 'Work can only start after diagnosis.');
        $before = $locked->toArray();
        $locked->update(['status' => RepairOrder::IN_PROGRESS]);
        $this->audit->log($locked->tenant_id, $actorId, 'repair.started', RepairOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function addPart(RepairOrder $order, int $variantId, float $qty, ?float $unitPrice, ?int $actorId = null): RepairOrder
    {
        return DB::transaction(function () use ($order, $variantId, $qty, $unitPrice, $actorId) {
            $locked = $this->locked($order->id);
            abort_unless(in_array($locked->status, [RepairOrder::DIAGNOSED, RepairOrder::IN_PROGRESS, RepairOrder::WAITING_PARTS], true), 422, 'Parts can only be added while the order is being worked.');
            $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->findOrFail($variantId);
            $qty = round($qty, 3);
            abort_if($qty <= 0, 422, 'Part quantity must be positive.');
            $available = $this->stock->onHand($locked->tenant_id, $locked->warehouse_id, $variantId);
            abort_if($available + 0.0005 < $qty, 422, "Insufficient stock for [{$variant->sku}]: needs {$qty}, has {$available}.");
            $price = $unitPrice === null ? round((float) $variant->sell_price, 2) : round($unitPrice, 2);
            abort_if($price < 0, 422, 'Part price cannot be negative.');
            $locked->parts()->create([
                'tenant_id' => $locked->tenant_id, 'product_variant_id' => $variantId,
                'quantity' => $qty, 'unit_price' => $price,
            ]);
            $this->retotal($locked);
            if ($locked->status === RepairOrder::WAITING_PARTS) {
                $locked->update(['status' => RepairOrder::IN_PROGRESS]);
            }
            $this->audit->log($locked->tenant_id, $actorId, 'repair.part.added', RepairOrder::class, $locked->id, null, ['variant_id' => $variantId, 'qty' => $qty]);

            return $locked->fresh();
        });
    }

    public function markWaitingParts(RepairOrder $order, ?int $actorId = null): RepairOrder
    {
        $locked = $this->locked($order->id);
        abort_unless(in_array($locked->status, [RepairOrder::DIAGNOSED, RepairOrder::IN_PROGRESS], true), 422, 'Only an active order can wait for parts.');
        $before = $locked->toArray();
        $locked->update(['status' => RepairOrder::WAITING_PARTS]);
        $this->audit->log($locked->tenant_id, $actorId, 'repair.waiting_parts', RepairOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function markReady(RepairOrder $order, ?int $actorId = null): RepairOrder
    {
        $locked = $this->locked($order->id);
        abort_unless(in_array($locked->status, [RepairOrder::IN_PROGRESS, RepairOrder::DIAGNOSED], true), 422, 'Only work in progress can be marked ready.');
        $before = $locked->toArray();
        $locked->update(['status' => RepairOrder::READY]);
        $this->audit->log($locked->tenant_id, $actorId, 'repair.ready', RepairOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function recordPayment(RepairOrder $order, float $amount, string $method, ?int $actorId = null): RepairOrder
    {
        $locked = $this->locked($order->id);
        abort_unless(in_array($locked->status, [RepairOrder::READY, RepairOrder::DELIVERED, RepairOrder::IN_PROGRESS], true), 422, 'Payment is only accepted once work is underway.');
        $amount = round($amount, 2);
        abort_if($amount <= 0 || $amount > $locked->balance() + 0.005, 422, 'Payment must be positive and cannot exceed the balance.');
        $before = $locked->toArray();
        $locked->update(['paid' => round((float) $locked->paid + $amount, 2), 'payment_method' => $method]);
        $this->audit->log($locked->tenant_id, $actorId, 'repair.payment.recorded', RepairOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    /** Delivery deducts all parts exactly once and requires a settled balance. */
    public function deliver(RepairOrder $order, ?int $actorId = null): RepairOrder
    {
        return DB::transaction(function () use ($order, $actorId) {
            $locked = $this->locked($order->id);
            abort_if($locked->status !== RepairOrder::READY, 422, 'Only ready orders can be delivered.');
            abort_if($locked->balance() > 0.005, 422, 'Balance must be settled before delivery.');
            foreach ($locked->parts as $part) {
                $this->stock->decrease($locked->tenant_id, $locked->warehouse_id, $part->product_variant_id, (float) $part->quantity, 'repair_deliver', $locked->id);
            }
            $before = $locked->toArray();
            $locked->update(['status' => RepairOrder::DELIVERED, 'delivered_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'repair.delivered', RepairOrder::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function cancel(RepairOrder $order, ?int $actorId = null): RepairOrder
    {
        $locked = $this->locked($order->id);
        abort_unless(in_array($locked->status, [RepairOrder::RECEIVED, RepairOrder::DIAGNOSED], true), 422, 'Only unstarted orders can be cancelled.');
        $before = $locked->toArray();
        $locked->update(['status' => RepairOrder::CANCELLED]);
        $this->audit->log($locked->tenant_id, $actorId, 'repair.cancelled', RepairOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    private function retotal(RepairOrder $order): void
    {
        $parts = round((float) $order->parts()->sum(DB::raw('quantity * unit_price')), 2);
        $labor = $order->warranty ? 0.0 : round((float) $order->labor_cost, 2);
        $partsBilled = $order->warranty ? 0.0 : $parts;
        $discount = min(round((float) $order->discount, 2), $labor + $partsBilled);
        $order->update([
            'parts_cost' => $partsBilled,
            'total' => round($labor + $partsBilled - $discount, 2),
        ]);
    }

    private function locked(int $id): RepairOrder
    {
        return RepairOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);
    }

    private function nextNumber(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $no = 'RP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            if (! RepairOrder::withoutGlobalScopes()->where('number', $no)->exists()) {
                return $no;
            }
        }

        return 'RP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
