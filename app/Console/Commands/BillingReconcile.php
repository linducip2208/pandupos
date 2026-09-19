<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class BillingReconcile extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Konsiliasi invoice billing: tutup invoice issued yang sudah lunas dari transaksi sukses';

    public function handle(BillingService $billing): int
    {
        $result = $billing->reconcileAll();
        $this->info("reconciled={$result['reconciled']}; already_paid={$result['already_paid']}");

        return self::SUCCESS;
    }
}
