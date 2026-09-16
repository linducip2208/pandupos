<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Validation\ValidationException;

final class UnitConversionService
{
    public function define(int $tenantId, int $fromUnitId, int $toUnitId, string|float|int $factor): UnitConversion
    {
        if ($fromUnitId === $toUnitId || (float) $factor <= 0) {
            throw ValidationException::withMessages(['factor' => 'Conversion units must differ and factor must be greater than zero.']);
        }

        $unitCount = Unit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', [$fromUnitId, $toUnitId])
            ->count();

        if ($unitCount !== 2) {
            throw ValidationException::withMessages(['unit' => 'Both units must belong to the active tenant.']);
        }

        return UnitConversion::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'from_unit_id' => $fromUnitId, 'to_unit_id' => $toUnitId],
            ['factor' => round((float) $factor, 8)]
        );
    }

    public function convert(int $tenantId, string|float|int $quantity, int $fromUnitId, int $toUnitId): float
    {
        if ((float) $quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity cannot be negative.']);
        }
        if ($fromUnitId === $toUnitId) {
            return round((float) $quantity, 6);
        }

        $edges = [];
        foreach (UnitConversion::withoutGlobalScopes()->where('tenant_id', $tenantId)->get() as $conversion) {
            $factor = (float) $conversion->factor;
            $edges[$conversion->from_unit_id][] = [$conversion->to_unit_id, $factor];
            $edges[$conversion->to_unit_id][] = [$conversion->from_unit_id, 1 / $factor];
        }

        $queue = [[$fromUnitId, 1.0]];
        $visited = [$fromUnitId => true];
        while ($queue !== []) {
            [$unitId, $factor] = array_shift($queue);
            foreach ($edges[$unitId] ?? [] as [$nextId, $edgeFactor]) {
                if (isset($visited[$nextId])) {
                    continue;
                }
                $nextFactor = $factor * $edgeFactor;
                if ($nextId === $toUnitId) {
                    return round((float) $quantity * $nextFactor, 6);
                }
                $visited[$nextId] = true;
                $queue[] = [$nextId, $nextFactor];
            }
        }

        throw ValidationException::withMessages(['unit' => 'No conversion path exists between the selected units.']);
    }
}
