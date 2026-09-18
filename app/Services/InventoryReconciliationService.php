<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\SerialNumber;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Illuminate\Support\Collection;

/**
 * Read-only integrity checks for the append-only inventory ledger.
 *
 * A reconciliation report deliberately does not repair data. The CLI is the
 * only caller allowed to repair the derived inventory_balances cache and must
 * opt in with --fix. All other anomalies require an operator investigation.
 */
final class InventoryReconciliationService
{
    private const EPSILON = 0.000001;

    /**
     * @return array{status: string, anomaly_count: int, anomalies: list<array<string, mixed>>}
     */
    public function inspect(?int $tenantId = null): array
    {
        $anomalies = collect()
            ->merge($this->ledgerBalanceDifferences($tenantId))
            ->merge($this->negativeBatchBalances($tenantId))
            ->merge($this->serialStateDifferences($tenantId))
            ->merge($this->locationDifferences($tenantId))
            ->merge($this->reservationDifferences($tenantId))
            ->merge($this->referenceIntegrityDifferences($tenantId))
            ->values();

        return [
            'status' => $anomalies->isEmpty() ? 'PASS' : 'FAIL',
            'anomaly_count' => $anomalies->count(),
            'anomalies' => $anomalies->all(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function ledgerBalanceDifferences(?int $tenantId = null): Collection
    {
        $rows = [];
        $ledgerQuery = StockMovement::withoutGlobalScopes()
            ->selectRaw("tenant_id, warehouse_id, product_variant_id, SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END) AS ledger_qty")
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id');
        $balanceQuery = InventoryBalance::withoutGlobalScopes();
        if ($tenantId !== null) {
            $ledgerQuery->where('tenant_id', $tenantId);
            $balanceQuery->where('tenant_id', $tenantId);
        }

        foreach ($ledgerQuery->get() as $ledger) {
            $key = $this->key($ledger->tenant_id, $ledger->warehouse_id, $ledger->product_variant_id);
            $rows[$key] = [
                'tenant_id' => (int) $ledger->tenant_id,
                'warehouse_id' => (int) $ledger->warehouse_id,
                'product_variant_id' => (int) $ledger->product_variant_id,
                'ledger_quantity' => $this->quantity($ledger->ledger_qty),
                'balance_quantity' => 0.0,
            ];
        }
        foreach ($balanceQuery->get() as $balance) {
            $key = $this->key($balance->tenant_id, $balance->warehouse_id, $balance->product_variant_id);
            $rows[$key] ??= [
                'tenant_id' => (int) $balance->tenant_id,
                'warehouse_id' => (int) $balance->warehouse_id,
                'product_variant_id' => (int) $balance->product_variant_id,
                'ledger_quantity' => 0.0,
                'balance_quantity' => 0.0,
            ];
            $rows[$key]['balance_quantity'] = $this->quantity($balance->quantity);
        }

        return collect($rows)->map(function (array $row) {
            $row['difference'] = $this->quantity($row['ledger_quantity'] - $row['balance_quantity']);

            return $row;
        })->filter(fn (array $row) => abs($row['difference']) >= self::EPSILON)
            ->map(fn (array $row) => $this->anomaly('ledger_balance_mismatch', $row));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function negativeBatchBalances(?int $tenantId): Collection
    {
        $query = StockMovement::withoutGlobalScopes()->whereNotNull('inventory_batch_id')
            ->selectRaw("tenant_id, warehouse_id, product_variant_id, inventory_batch_id, SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END) AS quantity")
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id', 'inventory_batch_id');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()->map(fn ($row) => [
            'tenant_id' => (int) $row->tenant_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'product_variant_id' => (int) $row->product_variant_id,
            'inventory_batch_id' => (int) $row->inventory_batch_id,
            'ledger_quantity' => $this->quantity($row->quantity),
        ])->filter(fn (array $row) => $row['ledger_quantity'] < -self::EPSILON)
            ->map(fn (array $row) => $this->anomaly('negative_batch_balance', $row));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function serialStateDifferences(?int $tenantId): Collection
    {
        $ledger = StockMovement::withoutGlobalScopes()->whereNotNull('serial_number_id')
            ->selectRaw("serial_number_id, SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END) AS quantity")
            ->groupBy('serial_number_id')->pluck('quantity', 'serial_number_id');
        $serials = SerialNumber::withoutGlobalScopes()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->get();

        return $serials->map(function (SerialNumber $serial) use ($ledger) {
            $expected = in_array($serial->status, ['available', 'returned'], true) ? 1.0 : 0.0;
            $actual = $this->quantity($ledger->get($serial->id, 0));

            return [
                'tenant_id' => $serial->tenant_id,
                'warehouse_id' => $serial->warehouse_id,
                'product_variant_id' => $serial->product_variant_id,
                'serial_number_id' => $serial->id,
                'serial_status' => $serial->status,
                'ledger_quantity' => $actual,
                'expected_quantity' => $expected,
            ];
        })->filter(fn (array $row) => abs($row['ledger_quantity'] - $row['expected_quantity']) >= self::EPSILON)
            ->map(fn (array $row) => $this->anomaly('serial_ledger_state_mismatch', $row));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function locationDifferences(?int $tenantId): Collection
    {
        $query = StockMovement::withoutGlobalScopes()->whereNotNull('warehouse_location_id')
            ->selectRaw("tenant_id, warehouse_id, product_variant_id, warehouse_location_id, SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END) AS quantity")
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id', 'warehouse_location_id');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()->map(fn ($row) => [
            'tenant_id' => (int) $row->tenant_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'product_variant_id' => (int) $row->product_variant_id,
            'warehouse_location_id' => (int) $row->warehouse_location_id,
            'ledger_quantity' => $this->quantity($row->quantity),
        ])->filter(fn (array $row) => $row['ledger_quantity'] < -self::EPSILON)
            ->map(fn (array $row) => $this->anomaly('negative_location_balance', $row));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function reservationDifferences(?int $tenantId): Collection
    {
        $query = StockReservation::withoutGlobalScopes()->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->selectRaw('tenant_id, warehouse_id, product_variant_id, SUM(quantity) AS reserved_quantity')
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $ledger = $this->ledgerQuantities($tenantId);

        return $query->get()->map(function ($row) use ($ledger) {
            $key = $this->key($row->tenant_id, $row->warehouse_id, $row->product_variant_id);

            return [
                'tenant_id' => (int) $row->tenant_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_variant_id' => (int) $row->product_variant_id,
                'reserved_quantity' => $this->quantity($row->reserved_quantity),
                'ledger_quantity' => $ledger[$key] ?? 0.0,
            ];
        })->filter(fn (array $row) => $row['reserved_quantity'] - $row['ledger_quantity'] >= self::EPSILON)
            ->map(fn (array $row) => $this->anomaly('reservation_exceeds_on_hand', $row));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function referenceIntegrityDifferences(?int $tenantId): Collection
    {
        $referenceQuery = StockMovement::withoutGlobalScopes()
            ->join('warehouse_locations', 'warehouse_locations.id', '=', 'stock_movements.warehouse_location_id')
            ->whereNotNull('stock_movements.warehouse_location_id')
            ->where(function ($query) {
                $query->whereColumn('stock_movements.tenant_id', '!=', 'warehouse_locations.tenant_id')
                    ->orWhereColumn('stock_movements.warehouse_id', '!=', 'warehouse_locations.warehouse_id');
            })
            ->select('stock_movements.id as stock_movement_id', 'stock_movements.tenant_id', 'stock_movements.warehouse_id', 'stock_movements.warehouse_location_id');
        if ($tenantId !== null) {
            $referenceQuery->where('stock_movements.tenant_id', $tenantId);
        }

        $duplicateSerialQuery = StockMovement::withoutGlobalScopes()->whereNotNull('serial_number_id')
            ->whereNotNull('reference_id')
            ->selectRaw('tenant_id, serial_number_id, reference_type, reference_id, movement_type, COUNT(*) AS occurrences')
            ->groupBy('tenant_id', 'serial_number_id', 'reference_type', 'reference_id', 'movement_type')
            ->havingRaw('COUNT(*) > 1');
        if ($tenantId !== null) {
            $duplicateSerialQuery->where('tenant_id', $tenantId);
        }

        return $referenceQuery->get()->map(fn ($row) => $this->anomaly('location_reference_mismatch', [
            'tenant_id' => (int) $row->tenant_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'warehouse_location_id' => (int) $row->warehouse_location_id,
            'stock_movement_id' => (int) $row->stock_movement_id,
        ]))->merge($duplicateSerialQuery->get()->map(fn ($row) => $this->anomaly('duplicate_serial_reference', [
            'tenant_id' => (int) $row->tenant_id,
            'serial_number_id' => (int) $row->serial_number_id,
            'reference_type' => $row->reference_type,
            'reference_id' => (int) $row->reference_id,
            'movement_type' => $row->movement_type,
            'occurrences' => (int) $row->occurrences,
        ])));
    }

    /** @return array<string, float> */
    private function ledgerQuantities(?int $tenantId): array
    {
        $query = StockMovement::withoutGlobalScopes()
            ->selectRaw("tenant_id, warehouse_id, product_variant_id, SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END) AS quantity")
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()->mapWithKeys(fn ($row) => [
            $this->key($row->tenant_id, $row->warehouse_id, $row->product_variant_id) => $this->quantity($row->quantity),
        ])->all();
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function anomaly(string $code, array $context): array
    {
        return ['status' => 'FAIL', 'code' => $code] + $context;
    }

    private function key(int|string $tenantId, int|string $warehouseId, int|string $variantId): string
    {
        return "{$tenantId}:{$warehouseId}:{$variantId}";
    }

    private function quantity(mixed $value): float
    {
        return round((float) $value, 6);
    }
}
