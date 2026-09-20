<?php

namespace App\Providers;

use App\Contracts\CostingStrategy;
use App\Http\Middleware\TenantMiddleware;
use App\Models\Account;
use App\Models\BlogPost;
use App\Models\CrmLead;
use App\Models\CrmOpportunity;
use App\Models\JournalEntry;
use App\Models\MrpBom;
use App\Models\MrpWorkOrder;
use App\Models\RepairOrder;
use App\Models\Product;
use App\Models\User;
use App\Policies\AccountingPolicy;
use App\Policies\CrmPolicy;
use App\Policies\MrpPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RepairPolicy;
use App\Services\Costing\WeightedAverageCostStrategy;
use App\Services\EntitlementService;
use App\Services\ModuleRegistry;
use App\Services\Seo\IndexNowService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;

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
        Gate::policy(Account::class, AccountingPolicy::class);
        Gate::policy(JournalEntry::class, AccountingPolicy::class);
        Gate::policy(CrmLead::class, CrmPolicy::class);
        Gate::policy(CrmOpportunity::class, CrmPolicy::class);
        Gate::policy(MrpBom::class, MrpPolicy::class);
        Gate::policy(MrpWorkOrder::class, MrpPolicy::class);
        Gate::policy(RepairOrder::class, RepairPolicy::class);

        BlogPost::saved(function (BlogPost $post): void {
            Cache::forget('seo.sitemap.index');
            if ($post->is_published && $post->published_at?->isPast() && ($post->wasRecentlyCreated || $post->wasChanged())) {
                app(IndexNowService::class)->submit([route('blog.show', $post->slug)]);
            }
        });

        BlogPost::deleted(fn () => Cache::forget('seo.sitemap.index'));

        // Livewire registers its update endpoint outside the web route groups,
        // so tenant-scoped components (POS) would lose TenantContext and fail
        // closed with 403 on every interaction. Resolve tenant context here too,
        // with the same fail-closed membership semantics as page loads.
        Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)
            ->middleware(['web', RequireLivewireHeaders::class, TenantMiddleware::class]));
    }
}
