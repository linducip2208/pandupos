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

    // Catalog & inventory
    Route::apiResource('products', \App\Http\Controllers\Api\V1\ProductController::class)->only(['index', 'store', 'show']);
    Route::apiResource('contacts', \App\Http\Controllers\Api\V1\ContactController::class)->only(['index', 'store']);

    // Purchasing: stock increases ONLY on receive
    Route::apiResource('purchases', \App\Http\Controllers\Api\V1\PurchaseController::class)->only(['index', 'store']);
    Route::post('purchases/{purchase}/receive', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'receive']);

    // Sales / POS: atomic checkout + idempotency
    Route::apiResource('sales', \App\Http\Controllers\Api\V1\SaleController::class)->only(['index', 'store']);
    Route::post('sales/{invoice}/void', [\App\Http\Controllers\Api\V1\SaleController::class, 'void']);

    // Reports
    Route::get('reports/sales', [\App\Http\Controllers\Api\V1\ReportController::class, 'sales']);
    Route::get('reports/stock', [\App\Http\Controllers\Api\V1\ReportController::class, 'stock']);

    // Sync foundation (backend only, Flutter later)
    Route::get('sync/pull', function (Request $request) {
        $since = $request->get('since');
        $q = \DB::table('server_change_logs')->where('tenant_id', \App\Support\TenantContext::id())->orderBy('changed_at');
        if ($since) {
            $q->where('changed_at', '>', $since);
        }

        return response()->json($q->limit(200)->get());
    });
    Route::post('sync/push', function () {
        return response()->json(['ok' => true, 'note' => 'backend stub: validate client UUID + idempotency here']);
    });

    // Webhooks (tenant outgoing management)
    Route::get('webhooks', function () {
        return response()->json(
            \DB::table('webhook_endpoints')->where('tenant_id', \App\Support\TenantContext::id())->get()
        );
    });
});

// Platform superadmin (no tenant scope, gate platform-admin)
Route::prefix('v1/platform')->middleware(['auth:sanctum', 'can:platform-admin'])->group(function () {
    Route::get('tenants', [\App\Http\Controllers\SuperadminTenantController::class, 'index']);
    Route::post('tenants/{tenant}/suspend', [\App\Http\Controllers\SuperadminTenantController::class, 'suspend']);
    Route::get('health', function () {
        return response()->json([
            'app' => config('app.name'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ]);
    });
});
