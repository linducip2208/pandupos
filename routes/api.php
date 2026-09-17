<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryConfigurationController;
use App\Http\Controllers\Api\V1\InventoryControlController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SalesDocumentController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\StockTransferController;
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
    Route::get('unit-conversions', [CatalogController::class, 'unitConversions']);
    Route::post('unit-conversions', [CatalogController::class, 'storeUnitConversion']);
    Route::post('barcode-profiles', [InventoryConfigurationController::class, 'storeBarcodeProfile']);
    Route::post('barcodes/parse', [InventoryConfigurationController::class, 'parseBarcode']);
    Route::get('price-lists', [InventoryConfigurationController::class, 'priceLists']);
    Route::post('price-lists', [InventoryConfigurationController::class, 'storePriceList']);
    Route::put('products/{product}/bundle-items', [InventoryConfigurationController::class, 'setBundleItems']);
    Route::post('inventory/batches', [InventoryConfigurationController::class, 'storeBatch']);
    Route::get('inventory/expiry', [InventoryConfigurationController::class, 'expiry']);
    Route::get('inventory/serials', [InventoryConfigurationController::class, 'serials']);
    Route::post('inventory/serials', [InventoryConfigurationController::class, 'storeSerial']);
    Route::get('inventory/locations', [InventoryConfigurationController::class, 'locations']);
    Route::post('inventory/locations', [InventoryConfigurationController::class, 'storeLocation']);
    Route::get('inventory/reservations', [InventoryConfigurationController::class, 'reservations']);
    Route::post('inventory/reservations', [InventoryConfigurationController::class, 'storeReservation']);
    Route::post('inventory/reservations/{reservation}/release', [InventoryConfigurationController::class, 'releaseReservation']);
    Route::post('inventory/reservations/{reservation}/consume', [InventoryConfigurationController::class, 'consumeReservation']);
    Route::apiResource('inventory/transfers', StockTransferController::class)->only(['index', 'store', 'show']);
    Route::post('inventory/transfers/{transfer}/approve', [StockTransferController::class, 'approve']);
    Route::post('inventory/transfers/{transfer}/ship', [StockTransferController::class, 'ship']);
    Route::post('inventory/transfers/{transfer}/in-transit', [StockTransferController::class, 'inTransit']);
    Route::post('inventory/transfers/{transfer}/receive', [StockTransferController::class, 'receive']);
    Route::post('inventory/transfers/{transfer}/cancel', [StockTransferController::class, 'cancel']);
    Route::get('inventory/adjustments', [InventoryControlController::class, 'adjustments']);
    Route::post('inventory/adjustments', [InventoryControlController::class, 'storeAdjustment']);
    Route::post('inventory/adjustments/{adjustment}/approve', [InventoryControlController::class, 'approveAdjustment']);
    Route::post('inventory/adjustments/{adjustment}/post', [InventoryControlController::class, 'postAdjustment']);
    Route::get('inventory/counts', [InventoryControlController::class, 'counts']);
    Route::post('inventory/counts', [InventoryControlController::class, 'storeCount']);
    Route::put('inventory/counts/{count}/quantities', [InventoryControlController::class, 'recordCount']);
    Route::post('inventory/counts/{count}/approve', [InventoryControlController::class, 'approveCount']);
    Route::post('inventory/counts/{count}/post', [InventoryControlController::class, 'postCount']);

    // Purchasing: stock increases ONLY on receive
    Route::apiResource('purchases', PurchaseController::class)->only(['index', 'store']);
    Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive']);
    Route::post('supplier-invoices', [PurchaseController::class, 'storeSupplierInvoice']);
    Route::post('supplier-invoices/{invoice}/payments', [PurchaseController::class, 'paySupplierInvoice']);
    Route::post('purchases/{purchase}/returns', [PurchaseController::class, 'storeReturn']);

    // Sales / POS: atomic checkout + idempotency
    Route::apiResource('sales', SaleController::class)->only(['index', 'store']);
    Route::post('sales/{invoice}/void', [SaleController::class, 'void']);
    Route::middleware(['entitlement:sales.access', 'module:sales'])->prefix('sales-documents')->group(function () {
        Route::get('quotations', [SalesDocumentController::class, 'index']);
        Route::post('quotations', [SalesDocumentController::class, 'store']);
        Route::post('quotations/{quotation}/transition', [SalesDocumentController::class, 'transition']);
        Route::post('quotations/{quotation}/proforma', [SalesDocumentController::class, 'proforma']);
        Route::get('orders', [SalesOrderController::class, 'index']);
        Route::post('orders', [SalesOrderController::class, 'store']);
        Route::post('orders/{order}/confirm', [SalesOrderController::class, 'confirm']);
        Route::post('orders/{order}/deliveries', [SalesOrderController::class, 'deliver']);
        Route::post('orders/{order}/cancel', [SalesOrderController::class, 'cancel']);
    });

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
