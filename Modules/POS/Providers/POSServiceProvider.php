<?php

namespace Modules\POS\Providers;

use Illuminate\Support\ServiceProvider;

class POSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('modules.pos', fn () => ['slug' => 'pos', 'version' => '1.0.0']);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('database/migrations'));
    }
}
