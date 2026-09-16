<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.dashboard.view');

        $tenantsTotal = Tenant::count();
        $tenantsActive = Tenant::where('status', 'active')->count();
        $tenantsTrial = Tenant::where('status', 'trial')->count();
        $tenantsSuspended = Tenant::where('status', 'suspended')->count();
        $tenantsExpired = Tenant::whereIn('status', ['cancelled', 'archived'])->count();

        $subsActive = Subscription::withoutGlobalScopes()->whereIn('status', Subscription::ACTIVE_STATUSES)->count();
        $trialsEnding = Subscription::withoutGlobalScopes()
            ->where('status', 'trialing')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count();
        $expiringSoon = Subscription::withoutGlobalScopes()
            ->whereIn('status', ['active', 'trialing'])
            ->whereBetween('current_period_end', [now(), now()->addDays(14)])->count();

        $mrr = (float) Subscription::withoutGlobalScopes()
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN subscriptions.billing_cycle = ? THEN plans.monthly_price WHEN subscriptions.billing_cycle = ? THEN plans.yearly_price / 12 ELSE plans.monthly_price END),0) as mrr', ['monthly', 'yearly'])
            ->value('mrr');
        $arr = round($mrr * 12, 2);
        $monthRevenue = (float) BillingInvoice::withoutGlobalScopes()
            ->where('status', 'paid')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total');

        $latestTenants = Tenant::with('activeSubscription.plan')->orderByDesc('id')->limit(8)->get();
        $latestSubs = Subscription::withoutGlobalScopes()->with(['plan'])->orderByDesc('id')->limit(8)->get();
        $failedPayments = BillingInvoice::withoutGlobalScopes()
            ->whereIn('status', ['failed', 'overdue'])->orderByDesc('id')->limit(8)->get();

        $planDistribution = Subscription::withoutGlobalScopes()
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->selectRaw('plans.name as plan, COUNT(*) as total')
            ->groupBy('plans.name')->get();

        return view('platform.dashboard', compact(
            'tenantsTotal', 'tenantsActive', 'tenantsTrial', 'tenantsSuspended', 'tenantsExpired',
            'subsActive', 'trialsEnding', 'expiringSoon', 'mrr', 'arr', 'monthRevenue',
            'latestTenants', 'latestSubs', 'failedPayments', 'planDistribution'
        ));
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
