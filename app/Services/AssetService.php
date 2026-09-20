<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Assets: registration, straight-line / declining-balance depreciation
 * schedules computed on demand, custodian/location transfers with history,
 * maintenance logs, and disposal with gain/loss from computed book value.
 */
final class AssetService
{
    public function __construct(private AuditService $audit) {}

    public function register(int $tenantId, array $data, ?int $actorId = null): Asset
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Asset name is required.');
        $cost = round((float) ($data['purchase_cost'] ?? 0), 2);
        abort_if($cost <= 0, 422, 'Purchase cost must be positive.');
        $salvage = round((float) ($data['salvage_value'] ?? 0), 2);
        abort_if($salvage < 0 || $salvage >= $cost, 422, 'Salvage value must be non-negative and below cost.');
        $life = (int) ($data['useful_life_months'] ?? 0);
        abort_if($life <= 0, 422, 'Useful life must be a positive number of months.');
        $method = $data['depreciation_method'] ?? 'straight_line';
        abort_unless(in_array($method, ['straight_line', 'declining_balance'], true), 422, 'Invalid depreciation method.');
        $code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: $this->nextCode();
        abort_if(Asset::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists(), 422, 'Asset code already exists.');

        $asset = Asset::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
            'category' => $data['category'] ?? null, 'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
            'purchase_cost' => $cost, 'salvage_value' => $salvage, 'useful_life_months' => $life,
            'depreciation_method' => $method, 'status' => Asset::ACTIVE,
            'location' => $data['location'] ?? null, 'custodian_id' => $data['custodian_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'asset.registered', Asset::class, $asset->id, null, ['code' => $code]);

        return $asset;
    }

    public function transfer(Asset $asset, ?int $toCustodianId, ?string $toLocation, ?int $actorId = null, ?string $notes = null): Asset
    {
        $locked = $this->locked($asset->id);
        abort_if($locked->status === Asset::DISPOSED, 422, 'Disposed assets cannot be transferred.');
        if ($toCustodianId !== null) {
            abort_unless(User::withoutGlobalScopes()->whereKey($toCustodianId)->exists(), 422, 'Custodian not found.');
        }
        $before = $locked->toArray();
        $locked->transfers()->create([
            'tenant_id' => $locked->tenant_id,
            'from_custodian_id' => $locked->custodian_id, 'to_custodian_id' => $toCustodianId,
            'from_location' => $locked->location, 'to_location' => $toLocation,
            'transferred_at' => now(), 'created_by' => $actorId, 'notes' => $notes,
        ]);
        $locked->update([
            'custodian_id' => $toCustodianId, 'location' => $toLocation,
            'status' => $toCustodianId ? Asset::ASSIGNED : $locked->status,
        ]);
        $this->audit->log($locked->tenant_id, $actorId, 'asset.transferred', Asset::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function logMaintenance(Asset $asset, array $data, ?int $actorId = null): Asset
    {
        $locked = $this->locked($asset->id);
        abort_if($locked->status === Asset::DISPOSED, 422, 'Disposed assets cannot be maintained.');
        $kind = $data['kind'] ?? 'preventive';
        abort_unless(in_array($kind, ['preventive', 'corrective'], true), 422, 'Invalid maintenance kind.');
        $cost = round((float) ($data['cost'] ?? 0), 2);
        abort_if($cost < 0, 422, 'Maintenance cost cannot be negative.');
        $locked->maintenances()->create([
            'tenant_id' => $locked->tenant_id,
            'maintained_on' => $data['maintained_on'] ?? now()->toDateString(),
            'kind' => $kind, 'cost' => $cost, 'next_due_on' => $data['next_due_on'] ?? null,
            'created_by' => $actorId, 'notes' => $data['notes'] ?? null,
        ]);
        $before = $locked->toArray();
        $locked->update(['status' => Asset::MAINTENANCE]);
        $this->audit->log($locked->tenant_id, $actorId, 'asset.maintained', Asset::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function returnFromMaintenance(Asset $asset, ?int $actorId = null): Asset
    {
        $locked = $this->locked($asset->id);
        abort_if($locked->status !== Asset::MAINTENANCE, 422, 'Only assets under maintenance can be returned.');
        $before = $locked->toArray();
        $locked->update(['status' => $locked->custodian_id ? Asset::ASSIGNED : Asset::ACTIVE]);
        $this->audit->log($locked->tenant_id, $actorId, 'asset.maintenance_returned', Asset::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function dispose(Asset $asset, float $proceeds, string $asOf, ?int $actorId = null): Asset
    {
        $locked = $this->locked($asset->id);
        abort_if($locked->status === Asset::DISPOSED, 422, 'Asset is already disposed.');
        $proceeds = round($proceeds, 2);
        abort_if($proceeds < 0, 422, 'Disposal proceeds cannot be negative.');
        $book = $this->bookValue($locked, $asOf);
        $before = $locked->toArray();
        $locked->update([
            'status' => Asset::DISPOSED, 'disposed_at' => now(),
            'disposal_proceeds' => $proceeds, 'disposal_gain_loss' => round($proceeds - $book, 2),
        ]);
        $this->audit->log($locked->tenant_id, $actorId, 'asset.disposed', Asset::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    /** @return array<int, array{period:int,depreciation:float,accumulated:float,book:float}> */
    public function schedule(Asset $asset, ?string $asOf = null): array
    {
        $cost = (float) $asset->purchase_cost;
        $salvage = (float) $asset->salvage_value;
        $life = max(1, (int) $asset->useful_life_months);
        $asOf ??= now()->toDateString();
        $elapsed = $this->elapsedMonths($asset->purchase_date->toDateString(), $asOf);
        $periods = min($elapsed, $life);
        $rows = [];
        $accumulated = 0.0;
        $book = $cost;
        for ($period = 1; $period <= $periods; $period++) {
            if ($asset->depreciation_method === 'declining_balance') {
                $dep = round($book * (2 / $life), 2);
                $dep = min($dep, round($book - $salvage, 2));
            } else {
                $dep = round(($cost - $salvage) / $life, 2);
                if ($period === $life) {
                    $dep = round($book - $salvage, 2); // absorb rounding on the final month
                }
            }
            $dep = max(0.0, $dep);
            $accumulated = round($accumulated + $dep, 2);
            $book = round($book - $dep, 2);
            $rows[] = ['period' => $period, 'depreciation' => $dep, 'accumulated' => $accumulated, 'book' => $book];
        }

        return $rows;
    }

    public function bookValue(Asset $asset, ?string $asOf = null): float
    {
        $rows = $this->schedule($asset, $asOf);
        if ($rows === []) {
            return round((float) $asset->purchase_cost, 2);
        }

        return end($rows)['book'];
    }

    private function elapsedMonths(string $from, string $to): int
    {
        if ($to < $from) {
            return 0;
        }
        [$fy, $fm] = array_map('intval', explode('-', substr($from, 0, 7)));
        [$ty, $tm] = array_map('intval', explode('-', substr($to, 0, 7)));

        return max(0, ($ty - $fy) * 12 + ($tm - $fm) + 1);
    }

    private function locked(int $id): Asset
    {
        return Asset::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);
    }

    private function nextCode(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = 'AST-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            if (! Asset::withoutGlobalScopes()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return 'AST-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
