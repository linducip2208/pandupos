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

Schedule::call(function () {
    // Mark queued announcement deliveries as sent (email channel handled by queue workers in prod).
    DB::table('announcement_deliveries')->where('status', 'queued')->limit(500)->update(['status' => 'sent', 'updated_at' => now()]);
})->everyFiveMinutes()->name('announcements-dispatch');
