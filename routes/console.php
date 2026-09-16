<?php

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    // Expire past-due subscriptions; suspend trials ended. History preserved, entitlement cache invalidated.
    $expired = Subscription::withoutGlobalScopes()
        ->whereIn('status', ['trialing', 'active', 'past_due', 'grace_period'])
        ->where('current_period_end', '<', now())->get();
    foreach ($expired as $sub) {
        app(SubscriptionService::class)->expire($sub->id);
    }
})->daily()->name('subscriptions-expire');

Schedule::command('business:escalate-overdue')->hourly()->withoutOverlapping()->name('business-escalate-overdue');
Schedule::command('notifications:send-pending')->everyFiveMinutes()->withoutOverlapping()->name('notifications-send-pending');
Schedule::command('business:send-reminders')->dailyAt('08:00')->withoutOverlapping()->name('business-send-reminders');
Schedule::command('backup:database')->dailyAt('01:30')->withoutOverlapping()->name('backup-database');

Schedule::command('seo:indexnow')->dailyAt('02:45')->withoutOverlapping()->name('seo-indexnow');
