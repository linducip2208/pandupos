<?php

use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\PlatformTenantController;
use App\Models\Subscription;
use App\Services\EntitlementService;
use App\Services\ModuleRegistry;
use App\Services\UsageLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/me', function (Request $request) {
        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'email', 'current_tenant_id', 'is_platform_admin']),
            'tenant_id' => TenantContext::id(),
        ]);
    });

    Route::get('/tenant', function () {
        return response()->json(TenantContext::get());
    });

    Route::get('/modules', function (ModuleRegistry $registry) {
        return response()->json($registry->all());
    });

    Route::get('/entitlements', function (EntitlementService $entitlements) {
        $tenantId = TenantContext::id();

        return response()->json($entitlements->all($tenantId));
    });

    Route::get('/usage', function (UsageLimitService $usage) {
        return response()->json($usage->snapshot(TenantContext::id()));
    });

    Route::get('/subscription', function () {
        $tenantId = TenantContext::id();
        $sub = Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->orderByDesc('current_period_end')
            ->first();

        return response()->json($sub);
    });

    // Example entitlement-gated + module-gated route (disabled module must 403).
    Route::get('/pos/ping', fn () => response()->json(['ok' => true]))
        ->middleware(['entitlement:pos.access', 'module:pos']);

    // Catalog & inventory
    Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show']);
    Route::apiResource('contacts', ContactController::class)->only(['index', 'store']);

    // Purchasing: stock increases ONLY on receive
    Route::apiResource('purchases', PurchaseController::class)->only(['index', 'store']);
    Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive']);

    // Sales / POS: atomic checkout + idempotency
    Route::apiResource('sales', SaleController::class)->only(['index', 'store']);
    Route::post('sales/{invoice}/void', [SaleController::class, 'void']);

    // Reports
    Route::get('reports/sales', [ReportController::class, 'sales']);
    Route::get('reports/stock', [ReportController::class, 'stock']);

    // Sync foundation (backend only, Flutter later)
    Route::get('sync/pull', function (Request $request) {
        $since = $request->get('since');
        $q = DB::table('server_change_logs')->where('tenant_id', TenantContext::id())->orderBy('changed_at');
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
            DB::table('webhook_endpoints')->where('tenant_id', TenantContext::id())->get()
        );
    });
});

// Platform admin (no tenant scope, gate platform-admin)
Route::prefix('v1/platform')->middleware(['auth:sanctum', 'can:platform-admin'])->group(function () {
    Route::get('tenants', [PlatformTenantController::class, 'index']);
    Route::post('tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend']);
    Route::get('health', function () {
        return response()->json([
            'app' => config('app.name'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ]);
    });
});
