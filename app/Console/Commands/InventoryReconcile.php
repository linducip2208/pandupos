<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class InventoryReconcile extends Command
{
    protected $signature = 'inventory:reconcile {--tenant= : Limit to one tenant ID} {--fix : Explicitly replace cached quantities with ledger quantities}';

    protected $description = 'Compare append-only stock ledger quantities with cached inventory balances';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $ledgerQuery = StockMovement::withoutGlobalScopes()
            ->selectRaw("tenant_id, warehouse_id, product_variant_id, SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END) AS ledger_qty")
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id');
        $balanceQuery = InventoryBalance::withoutGlobalScopes();
        if ($tenantId !== null) {
            $ledgerQuery->where('tenant_id', (int) $tenantId);
            $balanceQuery->where('tenant_id', (int) $tenantId);
        }

        $rows = [];
        foreach ($ledgerQuery->get() as $ledger) {
            $key = "{$ledger->tenant_id}:{$ledger->warehouse_id}:{$ledger->product_variant_id}";
            $rows[$key] = [
                'tenant' => (int) $ledger->tenant_id,
                'warehouse' => (int) $ledger->warehouse_id,
                'variant' => (int) $ledger->product_variant_id,
                'ledger' => round((float) $ledger->ledger_qty, 6),
                'cached' => 0.0,
            ];
        }
        foreach ($balanceQuery->get() as $balance) {
            $key = "{$balance->tenant_id}:{$balance->warehouse_id}:{$balance->product_variant_id}";
            $rows[$key] ??= [
                'tenant' => $balance->tenant_id, 'warehouse' => $balance->warehouse_id,
                'variant' => $balance->product_variant_id, 'ledger' => 0.0, 'cached' => 0.0,
            ];
            $rows[$key]['cached'] = round((float) $balance->quantity, 6);
        }

        $differences = [];
        foreach ($rows as $row) {
            $row['difference'] = round($row['ledger'] - $row['cached'], 6);
            if (abs($row['difference']) < 0.000001) {
                continue;
            }
            $differences[] = $row;
            if ($this->option('fix')) {
                InventoryBalance::withoutGlobalScopes()->updateOrCreate([
                    'tenant_id' => $row['tenant'], 'warehouse_id' => $row['warehouse'],
                    'product_variant_id' => $row['variant'],
                ], ['quantity' => $row['ledger']]);
            }
        }

        $this->table(['Tenant', 'Warehouse', 'Variant', 'Ledger Qty', 'Cached Qty', 'Difference'], array_map(
            fn (array $row) => array_values($row), $differences
        ));
        if ($differences === []) {
            $this->info('Inventory ledger and cached balances are reconciled.');

            return self::SUCCESS;
        }
        $this->line(count($differences).' difference(s) found.'.($this->option('fix') ? ' Cache updated by explicit --fix request.' : ' No data changed.'));

        return $this->option('fix') ? self::SUCCESS : self::FAILURE;
    }
}
