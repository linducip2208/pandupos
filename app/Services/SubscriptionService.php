<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Trial / upgrade / downgrade / cancel / renew with immutable history. */
final class SubscriptionService
{
    public function __construct(private EntitlementService $entitlements, private BillingService $billing) {}

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
            if ($plan->trial_days <= 0) {
                $this->billing->createInvoice($tenantId, $sub->id, (float) $plan->price, 0, [
                    ['description' => $plan->name.' ('.$cycle.')', 'quantity' => 1, 'unit_price' => (float) $plan->price],
                ]);
            }

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

    public function startTrial(int $tenantId, int $planId, string $cycle = 'monthly'): Subscription
    {
        $plan = Plan::findOrFail($planId);
        if (($plan->trial_days ?? 0) <= 0) {
            return $this->subscribe($tenantId, $planId, $cycle);
        }

        return $this->subscribe($tenantId, $planId, $cycle);
    }

    public function activate(int $subscriptionId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $sub = Subscription::withoutGlobalScopes()->findOrFail($subscriptionId);
            $sub->update(['status' => 'active', 'current_period_start' => now(), 'current_period_end' => $this->periodEnd($sub->billing_cycle)]);
            $this->log($sub->tenant_id, $sub->id, 'activated', []);
            $this->entitlements->forget($sub->tenant_id);

            return $sub;
        });
    }

    public function upgrade(int $tenantId, int $planId, string $cycle = 'monthly'): Subscription
    {
        $sub = $this->subscribe($tenantId, $planId, $cycle);
        $this->log($tenantId, $sub->id, 'upgraded', ['plan_id' => $planId]);

        return $sub;
    }

    public function downgrade(int $tenantId, int $planId, string $cycle = 'monthly'): Subscription
    {
        $sub = $this->subscribe($tenantId, $planId, $cycle);
        $this->log($tenantId, $sub->id, 'downgraded', ['plan_id' => $planId]);

        return $sub;
    }

    public function suspend(int $subscriptionId, string $reason = ''): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $reason) {
            $sub = Subscription::withoutGlobalScopes()->findOrFail($subscriptionId);
            $sub->update(['status' => 'suspended']);
            $this->log($sub->tenant_id, $sub->id, 'suspended', ['reason' => $reason]);
            $this->entitlements->forget($sub->tenant_id);

            return $sub;
        });
    }

    /** active -> past_due when the current period lapses. Logged, cache-safe. */
    public function markPeriodOverdue(int $subscriptionId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $sub = Subscription::withoutGlobalScopes()->findOrFail($subscriptionId);
            abort_unless($sub->status === 'active', 422, 'Only active subscriptions become past_due.');
            $sub->update(['status' => 'past_due']);
            $this->log($sub->tenant_id, $sub->id, 'past_due', ['period_end' => $sub->fresh()->current_period_end]);
            $this->entitlements->forget($sub->tenant_id);

            return $sub;
        });
    }

    /** past_due -> grace_period after the grace window lapses. Logged, cache-safe. */
    public function markPastDueGrace(int $subscriptionId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $sub = Subscription::withoutGlobalScopes()->findOrFail($subscriptionId);
            abort_unless($sub->status === 'past_due', 422, 'Only past_due subscriptions enter grace_period.');
            $sub->update(['status' => 'grace_period']);
            $this->log($sub->tenant_id, $sub->id, 'grace_period', ['period_end' => $sub->fresh()->current_period_end]);
            $this->entitlements->forget($sub->tenant_id);

            return $sub;
        });
    }

    /** trialing -> expired when the trial lapses. Logged, cache-safe. */
    public function expireTrial(int $subscriptionId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $sub = Subscription::withoutGlobalScopes()->findOrFail($subscriptionId);
            abort_unless($sub->status === 'trialing', 422, 'Only trialing subscriptions expire as trial.');
            $sub->update(['status' => 'expired', 'ends_at' => now()]);
            $this->log($sub->tenant_id, $sub->id, 'trial_expired', ['trial_ends_at' => $sub->fresh()->trial_ends_at]);
            $this->entitlements->forget($sub->tenant_id);

            return $sub;
        });
    }

    public function expire(int $subscriptionId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $sub = Subscription::withoutGlobalScopes()->findOrFail($subscriptionId);
            // Historical rows are never destroyed — status transition only.
            $sub->update(['status' => 'expired', 'ends_at' => now()]);
            $this->log($sub->tenant_id, $sub->id, 'expired', []);
            $this->entitlements->forget($sub->tenant_id);

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
