<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\EntitlementService;
use App\Services\ModuleRegistry;
use App\Services\UsageLimitService;
use App\Support\TenantContext;

class MetaController extends Controller
{
    public function modules(ModuleRegistry $registry)
    {
        return response()->json(['data' => $registry->all()]);
    }

    public function entitlements(EntitlementService $entitlements)
    {
        return response()->json(['data' => $entitlements->all(TenantContext::id())]);
    }

    public function usage(UsageLimitService $usage)
    {
        return response()->json(['data' => $usage->snapshot(TenantContext::id())]);
    }

    public function subscription()
    {
        $sub = Subscription::withoutGlobalScopes()
            ->where('tenant_id', TenantContext::id())
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->orderByDesc('current_period_end')->first();

        return response()->json(['data' => $sub]);
    }

    public function posPing()
    {
        return response()->json(['data' => ['ok' => true]]);
    }
}
