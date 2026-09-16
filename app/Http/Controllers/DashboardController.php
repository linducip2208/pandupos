<?php

namespace App\Http\Controllers;

use App\Services\EntitlementService;
use App\Services\ReportService;
use App\Services\UsageLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, ReportService $reports, UsageLimitService $usage, EntitlementService $entitlements)
    {
        $tenant = TenantContext::get() ?? $request->user()->currentTenant;
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        return view('dashboard', [
            'tenant' => $tenant,
            'sales' => $tenant ? $reports->salesSummary($tenant->id, $from, $to) : [],
            'usage' => $tenant ? $usage->snapshot($tenant->id) : [],
            'subscription' => $tenant ? $tenant->activeSubscription : null,
            'plan' => $tenant?->activeSubscription?->plan,
        ]);
    }
}
