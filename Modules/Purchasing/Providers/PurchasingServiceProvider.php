<?php

namespace Modules\Purchasing\Providers;

use Illuminate\Support\ServiceProvider;

class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('modules.purchasing', fn () => ['slug' => 'purchasing', 'version' => '1.0.0']);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('database/migrations'));
    }
}
