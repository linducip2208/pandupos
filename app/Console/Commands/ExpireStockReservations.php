<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\StockReservationService;
use Illuminate\Console\Command;

class ExpireStockReservations extends Command
{
    protected $signature = 'inventory:expire-reservations {--tenant= : Limit cleanup to one tenant ID}';

    protected $description = 'Expire due stock reservations and write an audit trail; no stock movement is created.';

    public function handle(StockReservationService $reservations): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $tenantId) => $query->whereKey($tenantId))->pluck('id');
        $expired = 0;
        foreach ($tenants as $tenantId) {
            $expired += $reservations->expireDue((int) $tenantId);
        }
        $this->info("Expired {$expired} stock reservation(s).");

        return self::SUCCESS;
    }
}
