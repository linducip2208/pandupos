<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Deterministic subscription escalation ladder. Every state change goes
 * through SubscriptionService so an immutable subscription_events row is
 * written and the entitlement cache is invalidated for that tenant.
 *
 *   trialing  -> (trial_ends_at passed)      -> expired
 *   active    -> (period_end passed)          -> past_due
 *   past_due  -> (3 days past period_end)     -> grace_period
 *   grace_period -> (7 days past period_end)  -> expired
 */
class EscalateOverdueSubscriptions extends Command
{
    protected $signature = 'business:escalate-overdue';

    protected $description = 'Eskalasi subscription: trial-expired, active->past_due, past_due->grace, grace->expired (deterministic + audited)';

    public function handle(SubscriptionService $service): int
    {
        $trialExpired = 0;
        Subscription::withoutGlobalScopes()
            ->where('status', 'trialing')
            ->where('trial_ends_at', '<', now())
            ->pluck('id')->each(function ($id) use ($service, &$trialExpired) {
                $service->expireTrial($id);
                $trialExpired++;
            });

        $pastDue = 0;
        Subscription::withoutGlobalScopes()
            ->where('status', 'active')
            ->where('current_period_end', '<', now())
            ->pluck('id')->each(function ($id) use ($service, &$pastDue) {
                $service->markPeriodOverdue($id);
                $pastDue++;
            });

        $grace = 0;
        Subscription::withoutGlobalScopes()
            ->where('status', 'past_due')
            ->where('current_period_end', '<', now()->subDays(3))
            ->pluck('id')->each(function ($id) use ($service, &$grace) {
                $service->markPastDueGrace($id);
                $grace++;
            });

        $expired = 0;
        Subscription::withoutGlobalScopes()
            ->where('status', 'grace_period')
            ->where('current_period_end', '<', now()->subDays(7))
            ->pluck('id')->each(function ($id) use ($service, &$expired) {
                $service->expire($id);
                $expired++;
            });

        $this->info("trial_expired={$trialExpired}; past_due={$pastDue}; grace={$grace}; expired={$expired}");

        return self::SUCCESS;
    }
}
