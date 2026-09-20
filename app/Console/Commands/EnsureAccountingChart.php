<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AccountingService;
use App\Services\ModuleRegistry;
use Illuminate\Console\Command;

class EnsureAccountingChart extends Command
{
    protected $signature = 'accounting:ensure-chart {--tenant= : Tenant ID (default: all tenants with accounting enabled)}';

    protected $description = 'Bootstrap the default chart of accounts (idempotent).';

    public function handle(AccountingService $accounting, ModuleRegistry $modules): int
    {
        $query = Tenant::query()->orderBy('id');
        if ($this->option('tenant')) {
            $query->whereKey((int) $this->option('tenant'));
        }
        $count = 0;
        foreach ($query->pluck('id') as $tenantId) {
            if (! $modules->isEnabled((int) $tenantId, 'accounting')) {
                continue;
            }
            $accounting->ensureDefaultChart((int) $tenantId);
            $this->line("Chart ensured for tenant {$tenantId}.");
            $count++;
        }
        $this->info("Done: {$count} tenant(s).");

        return self::SUCCESS;
    }
}
