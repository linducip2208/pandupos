<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\EntitlementService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.plans.manage');
        $plans = Plan::with('entitlements')->orderBy('monthly_price')->get();

        $matrixKeys = ['pos.access', 'inventory.access', 'purchase.access', 'sales.access', 'inventory.multi_warehouse', 'pos.multi_register'];
        $numericKeys = ['users.max', 'branches.max', 'warehouses.max', 'products.max', 'monthly_transactions.max', 'storage.mb', 'api.requests.monthly', 'ai.credits.monthly'];

        return view('platform.plans.index', compact('plans', 'matrixKeys', 'numericKeys'));
    }

    public function store(Request $request)
    {
        $this->authorizePlatform($request, 'platform.plans.manage');
        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => 'required|string|max:64|unique:plans,slug',
            'description' => 'nullable|string', 'monthly_price' => 'required|numeric|min:0',
            'yearly_price' => 'required|numeric|min:0', 'currency' => 'nullable|string|max:8',
            'trial_days' => 'nullable|integer|min:0', 'is_active' => 'boolean',
            'is_public' => 'boolean', 'is_featured' => 'boolean',
        ]);
        Plan::create($data + ['currency' => $data['currency'] ?? 'IDR']);

        return back()->with('status', 'Plan created.');
    }

    public function updateEntitlements(Request $request, Plan $plan, EntitlementService $entitlements)
    {
        $this->authorizePlatform($request, 'platform.entitlements.manage');
        $data = $request->validate(['entitlements' => 'required|array', 'entitlements.*' => 'nullable|string|max:64']);
        foreach ($data['entitlements'] as $key => $value) {
            $plan->entitlements()->updateOrCreate(['entitlement' => $key], ['value' => $value === '' ? null : $value]);
        }

        // Invalidate entitlement cache for all tenants on this plan.
        foreach ($plan->subscriptions()->pluck('tenant_id')->unique() as $tenantId) {
            $entitlements->forget($tenantId);
        }

        return back()->with('status', 'Entitlements saved.');
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
