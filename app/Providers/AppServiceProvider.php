<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\EntitlementService::class);
        $this->app->singleton(\App\Services\ModuleRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('platform-admin', fn (\App\Models\User $user) => $user->is_platform_admin);

        Gate::policy(\App\Models\Product::class, \App\Policies\ProductPolicy::class);
    }
}
