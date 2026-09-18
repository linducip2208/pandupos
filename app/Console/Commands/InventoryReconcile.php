<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Services\InventoryReconciliationService;
use Illuminate\Console\Command;

class InventoryReconcile extends Command
{
    protected $signature = 'inventory:reconcile {--tenant= : Limit to one tenant ID} {--fix : Explicitly replace cached quantities with ledger quantities}';

    protected $description = 'Read-only inventory integrity audit; --fix repairs only the derived balance cache';

    public function handle(InventoryReconciliationService $reconciliation): int
    {
        $tenantId = $this->option('tenant') === null ? null : (int) $this->option('tenant');
        $report = $reconciliation->inspect($tenantId);
        $balanceDifferences = $reconciliation->ledgerBalanceDifferences($tenantId);

        if ($this->option('fix')) {
            foreach ($balanceDifferences as $difference) {
                InventoryBalance::withoutGlobalScopes()->updateOrCreate([
                    'tenant_id' => $difference['tenant_id'],
                    'warehouse_id' => $difference['warehouse_id'],
                    'product_variant_id' => $difference['product_variant_id'],
                ], ['quantity' => $difference['ledger_quantity']]);
            }
            $report = $reconciliation->inspect($tenantId);
        }

        $this->table(['Status', 'Check', 'Tenant', 'Warehouse', 'Variant', 'Detail'], collect($report['anomalies'])->map(fn (array $row) => [
            $row['status'],
            $row['code'],
            $row['tenant_id'] ?? '-',
            $row['warehouse_id'] ?? '-',
            $row['product_variant_id'] ?? '-',
            json_encode(collect($row)->except(['status', 'code', 'tenant_id', 'warehouse_id', 'product_variant_id'])->all()),
        ])->all());
        if ($report['anomaly_count'] === 0) {
            $this->info('Inventory ledger and cached balances are reconciled.');
            if ($this->option('fix')) {
                $this->line('Cache repair completed by explicit --fix request.');
            }

            return self::SUCCESS;
        }
        $this->line($report['anomaly_count'].' integrity anomaly/anomalies found.'.($this->option('fix') ? ' Cache updated by explicit --fix request; unresolved anomalies remain read-only.' : ' No data changed.'));

        return self::FAILURE;
    }
}
