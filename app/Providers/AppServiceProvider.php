<?php

namespace App\Providers;

use App\Contracts\CostingStrategy;
use App\Models\BlogPost;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Services\Costing\WeightedAverageCostStrategy;
use App\Services\EntitlementService;
use App\Services\ModuleRegistry;
use App\Services\Seo\IndexNowService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EntitlementService::class);
        $this->app->singleton(ModuleRegistry::class);
        $this->app->bind(CostingStrategy::class, WeightedAverageCostStrategy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('platform-admin', fn (User $user) => $user->is_platform_admin);

        Gate::policy(Product::class, ProductPolicy::class);

        BlogPost::saved(function (BlogPost $post): void {
            Cache::forget('seo.sitemap.index');
            if ($post->is_published && $post->published_at?->isPast() && ($post->wasRecentlyCreated || $post->wasChanged())) {
                app(IndexNowService::class)->submit([route('blog.show', $post->slug)]);
            }
        });

        BlogPost::deleted(fn () => Cache::forget('seo.sitemap.index'));
    }
}
