<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\AuditService;
use App\Services\EntitlementService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.tenants.view');
        $q = Tenant::with(['activeSubscription.plan'])->orderByDesc('id');
        if ($s = $request->get('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('slug', 'like', "%{$s}%"));
        }
        if ($status = $request->get('status')) {
            $q->where('status', $status);
        }
        $tenants = $q->paginate(15)->withQueryString();

        return view('platform.tenants.index', compact('tenants'));
    }

    public function show(Request $request, Tenant $tenant)
    {
        $this->authorizePlatform($request, 'platform.tenants.view');
        $tenant->load(['branches', 'warehouses', 'memberships', 'subscriptions.plan', 'activeSubscription.plan']);

        return view('platform.tenants.show', compact('tenant'));
    }

    public function activate(Request $request, Tenant $tenant)
    {
        $this->authorizePlatform($request, 'platform.tenants.activate');
        $tenant->update(['status' => 'active']);
        app(AuditService::class)->log(null, $request->user()->id, 'tenant.activated', Tenant::class, $tenant->id, null, []);

        return back()->with('status', "Tenant {$tenant->name} activated.");
    }

    public function suspend(Request $request, Tenant $tenant)
    {
        $this->authorizePlatform($request, 'platform.tenants.suspend');
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $tenant->update(['status' => 'suspended']);
        app(AuditService::class)->log($tenant->id, $request->user()->id, 'tenant.suspended', Tenant::class, $tenant->id, null, $data);

        return back()->with('status', "Tenant {$tenant->name} suspended.");
    }

    public function archive(Request $request, Tenant $tenant)
    {
        $this->authorizePlatform($request, 'platform.tenants.suspend');
        $tenant->update(['status' => 'archived']);
        app(AuditService::class)->log($tenant->id, $request->user()->id, 'tenant.archived', Tenant::class, $tenant->id, null, []);

        return back()->with('status', "Tenant {$tenant->name} archived.");
    }

    public function changePlan(Request $request, Tenant $tenant, SubscriptionService $subs, EntitlementService $entitlements)
    {
        $this->authorizePlatform($request, 'platform.subscriptions.manage');
        $data = $request->validate(['plan_id' => 'required|exists:plans,id', 'billing_cycle' => 'nullable|in:monthly,quarterly,semiannual,yearly,lifetime']);
        $subs->subscribe($tenant->id, (int) $data['plan_id'], $data['billing_cycle'] ?? 'monthly');
        $entitlements->forget($tenant->id);

        return back()->with('status', 'Plan changed, entitlement cache invalidated.');
    }

    public function extend(Request $request, Tenant $tenant)
    {
        $this->authorizePlatform($request, 'platform.subscriptions.manage');
        $data = $request->validate(['days' => 'required|integer|min:1|max:365']);
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereIn('status', Subscription::ACTIVE_STATUSES)->orderByDesc('current_period_end')->firstOrFail();
        $sub->update(['current_period_end' => $sub->current_period_end->addDays((int) $data['days'])]);
        app(AuditService::class)->log($tenant->id, $request->user()->id, 'subscription.extended', Subscription::class, $sub->id, null, $data);

        return back()->with('status', 'Subscription extended.');
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
