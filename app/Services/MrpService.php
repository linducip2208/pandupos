<?php

namespace App\Services;

use App\Models\MrpBom;
use App\Models\MrpWorkOrder;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manufacturing: versioned multi-level BOMs with cycle guard, work orders
 * with availability-checked release, proportional material consumption with
 * provenance, finished-goods receipt at running-average cost, and scrap.
 */
final class MrpService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

    /**
     * @param  array<int, array{component_variant_id:int,quantity:float,scrap_rate?:float}>  $lines
     */
    public function createBom(int $tenantId, int $finishedVariantId, array $lines, ?int $actorId = null, ?string $notes = null): MrpBom
    {
        $finished = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($finishedVariantId);
        abort_if(count($lines) === 0, 422, 'A BOM needs at least one component line.');
        $seen = [];
        foreach ($lines as $line) {
            $componentId = (int) ($line['component_variant_id'] ?? 0);
            abort_if($componentId === $finished->id, 422, 'A product cannot be its own component.');
            abort_if(isset($seen[$componentId]), 422, 'Duplicate component in BOM.');
            abort_unless(ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($componentId)->exists(), 422, 'Component variant does not belong to tenant.');
            $qty = round((float) ($line['quantity'] ?? 0), 3);
            abort_if($qty <= 0, 422, 'Component quantity must be positive.');
            $scrap = round((float) ($line['scrap_rate'] ?? 0), 4);
            abort_if($scrap < 0 || $scrap > 1, 422, 'Scrap rate must be between 0 and 1.');
            $seen[$componentId] = ['quantity' => $qty, 'scrap_rate' => $scrap];
        }

        return DB::transaction(function () use ($tenantId, $finished, $seen, $actorId, $notes) {
            $version = ((int) MrpBom::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('finished_variant_id', $finished->id)->max('version')) + 1;
            MrpBom::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('finished_variant_id', $finished->id)->update(['is_active' => false]);
            $bom = MrpBom::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'finished_variant_id' => $finished->id,
                'version' => $version, 'is_active' => true, 'notes' => $notes,
            ]);
            foreach ($seen as $componentId => $row) {
                $bom->lines()->create([
                    'tenant_id' => $tenantId, 'component_variant_id' => $componentId,
                    'quantity' => $row['quantity'], 'scrap_rate' => $row['scrap_rate'],
                ]);
            }
            // A new revision must not introduce a dependency cycle.
            $this->explode($tenantId, $bom->id, 1);
            $this->audit->log($tenantId, $actorId, 'mrp.bom.created', MrpBom::class, $bom->id, null, ['version' => $version, 'lines' => count($seen)]);

            return $bom->load('lines');
        });
    }

    /** @return array<int, array{variant_id:int,quantity:float}> flat multi-level requirements */
    public function explode(int $tenantId, int $bomId, float $qty): array
    {
        $bom = MrpBom::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($bomId);
        $flat = [];
        $this->explodeInto($tenantId, $bom, $qty, $flat, [], 0);
        $out = [];
        foreach ($flat as $variantId => $quantity) {
            $out[] = ['variant_id' => $variantId, 'quantity' => $quantity];
        }

        return $out;
    }

    /** @param array<int, float> $flat @param array<int, bool> $path */
    private function explodeInto(int $tenantId, MrpBom $bom, float $qty, array &$flat, array $path, int $depth): void
    {
        abort_if($depth > 10, 422, 'BOM explosion exceeds 10 levels.');
        abort_if(isset($path[$bom->finished_variant_id]), 422, 'Circular BOM dependency detected.');
        $path[$bom->finished_variant_id] = true;
        foreach ($bom->lines as $line) {
            $need = round($qty * (float) $line->quantity * (1 + (float) $line->scrap_rate), 3);
            $sub = MrpBom::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('finished_variant_id', $line->component_variant_id)->where('is_active', true)->first();
            if ($sub) {
                $this->explodeInto($tenantId, $sub, $need, $flat, $path, $depth + 1);
            } else {
                $flat[$line->component_variant_id] = round(($flat[$line->component_variant_id] ?? 0) + $need, 3);
            }
        }
    }

    public function createWorkOrder(int $tenantId, int $bomId, int $warehouseId, float $qtyPlanned, ?int $actorId = null, ?string $scheduledAt = null): MrpWorkOrder
    {
        $bom = MrpBom::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($bomId);
        abort_unless(Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists(), 422, 'Warehouse does not belong to tenant.');
        $qtyPlanned = round($qtyPlanned, 3);
        abort_if($qtyPlanned <= 0, 422, 'Planned quantity must be positive.');

        $order = MrpWorkOrder::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'number' => $this->nextNumber(),
            'bom_id' => $bom->id, 'finished_variant_id' => $bom->finished_variant_id,
            'warehouse_id' => $warehouseId, 'quantity_planned' => $qtyPlanned,
            'status' => MrpWorkOrder::DRAFT, 'scheduled_at' => $scheduledAt, 'created_by' => $actorId,
        ]);
        $this->audit->log($tenantId, $actorId, 'mrp.work_order.created', MrpWorkOrder::class, $order->id, null, ['number' => $order->number]);

        return $order;
    }

    public function release(MrpWorkOrder $order, ?int $actorId = null): MrpWorkOrder
    {
        return DB::transaction(function () use ($order, $actorId) {
            $locked = $this->locked($order->id);
            abort_if($locked->status !== MrpWorkOrder::DRAFT, 422, 'Only draft work orders can be released.');
            $shortages = $this->shortages($locked);
            abort_if($shortages !== [], 422, 'Insufficient materials: '.implode('; ', array_map(fn ($s) => "{$s['sku']} needs {$s['required']}, has {$s['available']}", $shortages)));
            $before = $locked->toArray();
            $locked->update(['status' => MrpWorkOrder::RELEASED]);
            $this->audit->log($locked->tenant_id, $actorId, 'mrp.work_order.released', MrpWorkOrder::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function start(MrpWorkOrder $order, ?int $actorId = null): MrpWorkOrder
    {
        $locked = $this->locked($order->id);
        abort_if($locked->status !== MrpWorkOrder::RELEASED, 422, 'Only released work orders can start.');
        $before = $locked->toArray();
        $locked->update(['status' => MrpWorkOrder::IN_PROGRESS, 'started_at' => now()]);
        $this->audit->log($locked->tenant_id, $actorId, 'mrp.work_order.started', MrpWorkOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function recordProduction(MrpWorkOrder $order, float $qtyGood, float $qtyScrap, ?int $actorId = null): MrpWorkOrder
    {
        return DB::transaction(function () use ($order, $qtyGood, $qtyScrap, $actorId) {
            $locked = $this->locked($order->id);
            abort_if($locked->status !== MrpWorkOrder::IN_PROGRESS, 422, 'Production can only be recorded on an in-progress work order.');
            $qtyGood = round($qtyGood, 3);
            $qtyScrap = round($qtyScrap, 3);
            abort_if($qtyGood < 0 || $qtyScrap < 0 || ($qtyGood == 0.0 && $qtyScrap == 0.0), 422, 'Production quantities must be non-negative with a positive total.');
            $newOutput = round($locked->cumulativeOutput() + $qtyGood + $qtyScrap, 3);
            abort_if($newOutput > (float) $locked->quantity_planned + 0.0005, 422, 'Production exceeds the planned quantity.');

            // Consume components for the not-yet-consumed output basis.
            $deltaBasis = round($newOutput - (float) $locked->consumed_basis, 3);
            $perUnit = [];
            foreach ($this->explode($locked->tenant_id, $locked->bom_id, 1) as $req) {
                $perUnit[$req['variant_id']] = $req['quantity'];
            }
            $deltaCost = 0.0;
            foreach ($perUnit as $variantId => $perOne) {
                $consume = round($deltaBasis * $perOne, 3);
                if ($consume <= 0) {
                    continue;
                }
                $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->findOrFail($variantId);
                $this->stock->decrease($locked->tenant_id, $locked->warehouse_id, $variantId, $consume, 'mrp_consume', $locked->id);
                $deltaCost = round($deltaCost + $consume * (float) $variant->purchase_price, 2);
            }
            $materialCost = round((float) $locked->material_cost + $deltaCost, 2);
            $produced = round((float) $locked->quantity_produced + $qtyGood, 3);
            $scrapped = round((float) $locked->quantity_scrapped + $qtyScrap, 3);
            $unitCost = $produced > 0 ? round($materialCost / $produced, 2) : $locked->unit_cost;
            if ($qtyGood > 0) {
                $this->stock->increase($locked->tenant_id, $locked->warehouse_id, $locked->finished_variant_id, $qtyGood, (float) ($unitCost ?? 0), 'mrp_produce', $locked->id);
            }
            $before = $locked->toArray();
            $locked->update([
                'quantity_produced' => $produced, 'quantity_scrapped' => $scrapped,
                'consumed_basis' => $newOutput, 'material_cost' => $materialCost, 'unit_cost' => $unitCost,
            ]);
            $this->audit->log($locked->tenant_id, $actorId, 'mrp.work_order.produced', MrpWorkOrder::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function finish(MrpWorkOrder $order, ?int $actorId = null): MrpWorkOrder
    {
        $locked = $this->locked($order->id);
        abort_if($locked->status !== MrpWorkOrder::IN_PROGRESS, 422, 'Only in-progress work orders can finish.');
        abort_if($locked->cumulativeOutput() <= 0, 422, 'Nothing was produced; cancel the work order instead.');
        $before = $locked->toArray();
        $locked->update(['status' => MrpWorkOrder::DONE, 'finished_at' => now()]);
        $this->audit->log($locked->tenant_id, $actorId, 'mrp.work_order.finished', MrpWorkOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function cancel(MrpWorkOrder $order, ?int $actorId = null): MrpWorkOrder
    {
        $locked = $this->locked($order->id);
        abort_unless(in_array($locked->status, [MrpWorkOrder::DRAFT, MrpWorkOrder::RELEASED], true), 422, 'Only draft or released work orders can be cancelled.');
        abort_if((float) $locked->consumed_basis > 0, 422, 'Materials were already consumed; finish the work order instead.');
        $before = $locked->toArray();
        $locked->update(['status' => MrpWorkOrder::CANCELLED]);
        $this->audit->log($locked->tenant_id, $actorId, 'mrp.work_order.cancelled', MrpWorkOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    /** @return array<int, array{sku:string,required:float,available:float}> */
    public function shortages(MrpWorkOrder $order): array
    {
        $locked = $order->exists ? $order->fresh() : $order;
        $short = [];
        foreach ($this->explode($locked->tenant_id, $locked->bom_id, (float) $locked->quantity_planned) as $req) {
            $available = $this->stock->onHand($locked->tenant_id, $locked->warehouse_id, $req['variant_id']);
            if ($available + 0.0005 < $req['quantity']) {
                $variant = ProductVariant::withoutGlobalScopes()->find($req['variant_id']);
                $short[] = ['sku' => $variant?->sku ?? ('#'.$req['variant_id']), 'required' => $req['quantity'], 'available' => round($available, 3)];
            }
        }

        return $short;
    }

    private function locked(int $id): MrpWorkOrder
    {
        return MrpWorkOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);
    }

    private function nextNumber(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $no = 'WO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            if (! MrpWorkOrder::withoutGlobalScopes()->where('number', $no)->exists()) {
                return $no;
            }
        }

        return 'WO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
