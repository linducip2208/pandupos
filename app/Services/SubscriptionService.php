<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Trial / upgrade / downgrade / cancel / renew with immutable history. */
final class SubscriptionService
{
    public function __construct(private EntitlementService $entitlements) {}

    public function subscribe(int $tenantId, int $planId, string $cycle = 'monthly'): Subscription
    {
        return DB::transaction(function () use ($tenantId, $planId, $cycle) {
            Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', Subscription::ACTIVE_STATUSES)
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ends_at' => now()]);

            $plan = Plan::findOrFail($planId);

            $sub = Subscription::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'plan_id' => $planId,
                'status' => $plan->trial_days > 0 ? 'trialing' : 'active',
                'billing_cycle' => $cycle,
                'starts_at' => now(),
                'trial_ends_at' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
                'current_period_start' => now(),
                'current_period_end' => $this->periodEnd($cycle),
            ]);

            $this->log($tenantId, $sub->id, 'subscribed', ['plan_id' => $planId, 'cycle' => $cycle]);
            $this->entitlements->forget($tenantId);

            return $sub;
        });
    }

    public function cancel(int $tenantId, int $subscriptionId): Subscription
    {
        return DB::transaction(function () use ($tenantId, $subscriptionId) {
            $sub = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->findOrFail($subscriptionId);
            $sub->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ends_at' => now()->endOfDay()]);
            $this->log($tenantId, $sub->id, 'cancelled', []);
            $this->entitlements->forget($tenantId);

            return $sub;
        });
    }

    public function renew(int $subscriptionId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $sub = Subscription::withoutGlobalScopes()->findOrFail($subscriptionId);
            $sub->update([
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => $this->periodEnd($sub->billing_cycle),
            ]);
            $this->log($sub->tenant_id, $sub->id, 'renewed', []);
            $this->entitlements->forget($sub->tenant_id);

            return $sub;
        });
    }

    private function periodEnd(string $cycle): Carbon
    {
        return match ($cycle) {
            'yearly' => now()->addYear(),
            'quarterly' => now()->addMonths(3),
            'semiannual' => now()->addMonths(6),
            'lifetime' => now()->addYears(50),
            default => now()->addMonth(),
        };
    }

    private function log(int $tenantId, int $subId, string $event, array $payload): void
    {
        SubscriptionEvent::create([
            'subscription_id' => $subId, 'tenant_id' => $tenantId,
            'event' => $event, 'payload' => $payload,
            'actor_id' => auth()->id(),
        ]);
    }
}
