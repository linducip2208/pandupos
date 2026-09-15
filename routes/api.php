<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/me', function (Request $request) {
        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'email', 'current_tenant_id', 'is_platform_admin']),
            'tenant_id' => \App\Support\TenantContext::id(),
        ]);
    });

    Route::get('/tenant', function () {
        return response()->json(\App\Support\TenantContext::get());
    });

    Route::get('/modules', function (App\Services\ModuleRegistry $registry) {
        return response()->json($registry->all());
    });

    Route::get('/entitlements', function (App\Services\EntitlementService $entitlements) {
        $tenantId = \App\Support\TenantContext::id();
        return response()->json($entitlements->all($tenantId));
    });

    Route::get('/usage', function (App\Services\UsageLimitService $usage) {
        return response()->json($usage->snapshot(\App\Support\TenantContext::id()));
    });

    Route::get('/subscription', function () {
        $tenantId = \App\Support\TenantContext::id();
        $sub = \App\Models\Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', \App\Models\Subscription::ACTIVE_STATUSES)
            ->orderByDesc('current_period_end')
            ->first();

        return response()->json($sub);
    });

    // Example entitlement-gated + module-gated route (disabled module must 403).
    Route::get('/pos/ping', fn () => response()->json(['ok' => true]))
        ->middleware(['entitlement:pos.access', 'module:pos']);
});
