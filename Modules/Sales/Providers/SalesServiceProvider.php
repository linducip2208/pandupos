<?php

namespace Modules\Sales\Providers;

use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('modules.sales', fn () => ['slug' => 'sales', 'version' => '1.0.0']);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('database/migrations'));
    }
}
