<?php

use App\Support\Readiness\ReadinessScoreService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('readiness:score {--json : Emit structured JSON}', function (ReadinessScoreService $scores) {
    $dimensions = $scores->dimensions();
    if ($this->option('json')) {
        $this->line(json_encode($dimensions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
    $this->table(['Dimension', 'Score'], collect($dimensions)->map(fn (array $dimension, string $name) => [strtoupper($name), $dimension['score']])->all());

    return self::SUCCESS;
})->purpose('Show the canonical, evidence-weighted product readiness scores');

Schedule::command('business:escalate-overdue')->hourly()->withoutOverlapping()->name('business-escalate-overdue');
Schedule::command('notifications:send-pending')->everyFiveMinutes()->withoutOverlapping()->name('notifications-send-pending');
Schedule::command('business:send-reminders')->dailyAt('08:00')->withoutOverlapping()->name('business-send-reminders');
Schedule::command('backup:database')->dailyAt('01:30')->withoutOverlapping()->name('backup-database');
Schedule::command('inventory:expire-reservations')->everyFiveMinutes()->withoutOverlapping()->name('inventory-expire-reservations');

Schedule::command('seo:indexnow')->dailyAt('02:45')->withoutOverlapping()->name('seo-indexnow');
