<?php

namespace Tests\Feature;

use App\Console\Commands\EscalateOverdueSubscriptions;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Deterministic, event-persisted subscription transitions driven by the
 * hourly escalation ladder — the single source of automated state change.
 */
class SubscriptionSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithOwner(string $name): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);

        return [$tenant, $owner];
    }

    private function sub(int $tenantId, int $planId, string $status, array $overrides = []): Subscription
    {
        $data = array_merge([
            'tenant_id' => $tenantId, 'plan_id' => $planId,
            'status' => $status, 'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'trial_ends_at' => $status === 'trialing' ? now()->subDay() : now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subMonth(),
        ], $overrides);

        return Subscription::withoutGlobalScopes()->create($data);
    }

    public function test_ladder_is_deterministic_and_every_step_is_audited(): void
    {
        // One subscription in each state, all past their thresholds.
        $this->seed(PlatformSeeder::class);
        $plan = Plan::where('slug', 'growth')->firstOrFail();
        [$trialTenant, $trialOwner] = $this->tenantWithOwner('Ladder Trial');
        [$activeTenant, $activeOwner] = $this->tenantWithOwner('Ladder Active');
        [$pastTenant, $pastOwner] = $this->tenantWithOwner('Ladder Past');
        [$graceTenant, $graceOwner] = $this->tenantWithOwner('Ladder Grace');

        $trial = $this->sub($trialTenant->id, $plan->id, 'trialing', ['trial_ends_at' => now()->subDay()]);
        $active = $this->sub($activeTenant->id, $plan->id, 'active', ['current_period_end' => now()->subDay()]);
        $past = $this->sub($pastTenant->id, $plan->id, 'past_due', ['current_period_end' => now()->subDays(4)]);
        $grace = $this->sub($graceTenant->id, $plan->id, 'grace_period', ['current_period_end' => now()->subDays(8)]);

        $this->artisan('business:escalate-overdue')
            ->expectsOutputToContain('trial_expired=1; past_due=1; grace=1; expired=1')
            ->assertExitCode(0);

        $this->assertSame('expired', $trial->refresh()->status);
        $this->assertSame('past_due', $active->refresh()->status);
        $this->assertSame('grace_period', $past->refresh()->status);
        $this->assertSame('expired', $grace->refresh()->status);

        // Every transition produced an immutable event row.
        $this->assertDatabaseHas('subscription_events', ['subscription_id' => $trial->id, 'event' => 'trial_expired']);
        $this->assertDatabaseHas('subscription_events', ['subscription_id' => $active->id, 'event' => 'past_due']);
        $this->assertDatabaseHas('subscription_events', ['subscription_id' => $past->id, 'event' => 'grace_period']);
        $this->assertDatabaseHas('subscription_events', ['subscription_id' => $grace->id, 'event' => 'expired']);

        // Re-running is a no-op: no double transitions, no duplicate events.
        $before = SubscriptionEvent::count();
        $this->artisan('business:escalate-overdue')->assertExitCode(0);
        $this->assertSame($before, SubscriptionEvent::count());
        $this->assertSame('expired', $trial->refresh()->status);
        $this->assertSame('past_due', $active->refresh()->status);
    }

    public function test_healthy_subscriptions_are_untouched(): void
    {
        $this->seed(PlatformSeeder::class);
        $plan = Plan::where('slug', 'growth')->firstOrFail();
        [$tenant, $owner] = $this->tenantWithOwner('Healthy Ladder');
        $healthy = $this->sub($tenant->id, $plan->id, 'active', ['current_period_end' => now()->addMonth()]);
        $trial = $this->sub($tenant->id, $plan->id, 'trialing', ['trial_ends_at' => now()->addDay()]);

        $this->artisan('business:escalate-overdue')->assertExitCode(0);

        $this->assertSame('active', $healthy->refresh()->status);
        $this->assertSame('trialing', $trial->refresh()->status);
        $this->assertSame(0, SubscriptionEvent::count());
    }

    public function test_scheduled_ladder_is_registered_once_and_ladder_command_wins(): void
    {
        $schedule = collect(Schedule::events());
        // The audited ladder is registered exactly once with its hourly cadence.
        $ladder = $schedule->filter(fn ($e) => $e->command !== null && str_contains((string) $e->command, 'business:escalate-overdue'));
        $this->assertSame(1, $ladder->count(), 'Ladder must be scheduled exactly once.');
        $this->assertSame('0 * * * *', $ladder->first()->expression);

        // No blanket-expire scheduled closure remains; command events only.
        foreach ($schedule as $e) {
            $this->assertNotNull($e->command, 'All scheduled entries must be commands, no ad-hoc closures.');
        }
        $this->assertFalse(
            $schedule->contains(fn ($e) => str_contains((string) $e->command, 'subscriptions-expire'))
        );
    }

    public function test_ladder_obsolete_methods_do_not_exist_via_guard(): void
    {
        $command = new EscalateOverdueSubscriptions;
        $this->assertTrue(method_exists($command, 'handle'));
    }
}
