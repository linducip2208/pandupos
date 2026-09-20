<?php

namespace App\Providers;

use App\Contracts\CostingStrategy;
use App\Http\Middleware\TenantMiddleware;
use App\Models\Account;
use App\Models\AiProviderConfig;
use App\Models\Asset;
use App\Models\BlogPost;
use App\Models\CrmLead;
use App\Models\CrmOpportunity;
use App\Models\EcommerceOrder;
use App\Models\FfTask;
use App\Models\GymMembership;
use App\Models\HmsPatient;
use App\Models\HrmEmployee;
use App\Models\JournalEntry;
use App\Models\MrpBom;
use App\Models\MrpWorkOrder;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\RepairOrder;
use App\Models\User;
use App\Models\WoConnection;
use App\Models\ZatcaDocument;
use App\Policies\AccountingPolicy;
use App\Policies\AiPolicy;
use App\Policies\AssetPolicy;
use App\Policies\CrmPolicy;
use App\Policies\EcommercePolicy;
use App\Policies\FieldForcePolicy;
use App\Policies\GymPolicy;
use App\Policies\HmsPolicy;
use App\Policies\HrmPolicy;
use App\Policies\MrpPolicy;
use App\Policies\PayrollPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RepairPolicy;
use App\Policies\WooPolicy;
use App\Policies\ZatcaPolicy;
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
        Gate::policy(AiProviderConfig::class, AiPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(JournalEntry::class, AccountingPolicy::class);
        Gate::policy(CrmLead::class, CrmPolicy::class);
        Gate::policy(CrmOpportunity::class, CrmPolicy::class);
        Gate::policy(EcommerceOrder::class, EcommercePolicy::class);
        Gate::policy(FfTask::class, FieldForcePolicy::class);
        Gate::policy(GymMembership::class, GymPolicy::class);
        Gate::policy(HrmEmployee::class, HrmPolicy::class);
        Gate::policy(HmsPatient::class, HmsPolicy::class);
        Gate::policy(MrpBom::class, MrpPolicy::class);
        Gate::policy(MrpWorkOrder::class, MrpPolicy::class);
        Gate::policy(PayrollRun::class, PayrollPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(RepairOrder::class, RepairPolicy::class);
        Gate::policy(WoConnection::class, WooPolicy::class);
        Gate::policy(ZatcaDocument::class, ZatcaPolicy::class);

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
