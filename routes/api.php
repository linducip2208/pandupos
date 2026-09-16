<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\PlatformTenantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return response()->json(['data' => $request->user()]);
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'throttle:300,1'])->group(function () {
    Route::get('/me', [MeController::class, 'show']);
    Route::get('/tenant', [MeController::class, 'tenant']);
    Route::get('/modules', [MetaController::class, 'modules']);
    Route::get('/entitlements', [MetaController::class, 'entitlements']);
    Route::get('/usage', [MetaController::class, 'usage']);
    Route::get('/subscription', [MetaController::class, 'subscription']);

    // Example entitlement-gated + module-gated route (disabled module must 403).
    Route::get('/pos/ping', [MetaController::class, 'posPing'])
        ->middleware(['entitlement:pos.access', 'module:pos']);

    // Catalog & inventory
    Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show']);
    Route::apiResource('contacts', ContactController::class)->only(['index', 'store']);
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::post('categories', [CatalogController::class, 'storeCategory']);
    Route::get('brands', [CatalogController::class, 'brands']);
    Route::post('brands', [CatalogController::class, 'storeBrand']);
    Route::get('units', [CatalogController::class, 'units']);
    Route::post('units', [CatalogController::class, 'storeUnit']);

    // Purchasing: stock increases ONLY on receive
    Route::apiResource('purchases', PurchaseController::class)->only(['index', 'store']);
    Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive']);

    // Sales / POS: atomic checkout + idempotency
    Route::apiResource('sales', SaleController::class)->only(['index', 'store']);
    Route::post('sales/{invoice}/void', [SaleController::class, 'void']);

    // Reports
    Route::get('reports/sales', [ReportController::class, 'sales']);
    Route::get('reports/stock', [ReportController::class, 'stock']);

    // Offline sync (real implementation, cursor-based)
    Route::get('sync/pull', [SyncController::class, 'pull']);
    Route::post('sync/push', [SyncController::class, 'push']);

    // Webhooks (tenant outgoing management)
    Route::get('webhooks', [WebhookController::class, 'index']);
});

// Platform admin (no tenant scope, gate platform-admin)
Route::prefix('v1/platform')->middleware(['auth:sanctum', 'can:platform-admin'])->group(function () {
    Route::get('tenants', [PlatformTenantController::class, 'index']);
    Route::post('tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend']);
    Route::get('health', [HealthController::class, 'show']);
});
