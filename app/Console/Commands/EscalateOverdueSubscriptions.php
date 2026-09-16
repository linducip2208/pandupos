<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class EscalateOverdueSubscriptions extends Command
{
    protected $signature = 'business:escalate-overdue';

    protected $description = 'Eskalasi subscription jatuh tempo dari active ke past_due, grace, lalu expired';

    public function handle(SubscriptionService $service): int
    {
        $pastDue = Subscription::withoutGlobalScopes()->where('status', 'active')->where('current_period_end', '<', now())->update(['status' => 'past_due', 'updated_at' => now()]);
        $grace = Subscription::withoutGlobalScopes()->where('status', 'past_due')->where('current_period_end', '<', now()->subDays(3))->update(['status' => 'grace_period', 'updated_at' => now()]);
        $expired = 0;
        Subscription::withoutGlobalScopes()->where('status', 'grace_period')->where('current_period_end', '<', now()->subDays(7))->pluck('id')->each(function ($id) use ($service, &$expired) {
            $service->expire($id);
            $expired++;
        });
        $this->info("past_due={$pastDue}; grace={$grace}; expired={$expired}");

        return self::SUCCESS;
    }
}
