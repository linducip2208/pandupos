<?php

use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CrmController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryConfigurationController;
use App\Http\Controllers\Api\V1\InventoryControlController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
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
    Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('products/{product}/archive', [ProductController::class, 'archive']);
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
    Route::post('inventory/serials/{serial}/reserve', [InventoryConfigurationController::class, 'reserveSerial']);
    Route::post('inventory/serials/{serial}/release', [InventoryConfigurationController::class, 'releaseSerial']);
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
    Route::post('inventory/adjustments/{adjustment}/submit', [InventoryControlController::class, 'submitAdjustment']);
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
    Route::post('purchases/{purchase}/return-drafts', [PurchaseController::class, 'storeDraftReturn']);
    Route::post('purchase-returns/{purchaseReturn}/submit', [PurchaseController::class, 'submitDraftReturn']);
    Route::post('purchase-returns/{purchaseReturn}/approve', [PurchaseController::class, 'approveDraftReturn']);
    Route::post('purchase-returns/{purchaseReturn}/post', [PurchaseController::class, 'postDraftReturn']);

    // Sales / POS: atomic checkout + idempotency
    Route::get('sales', [SaleController::class, 'index'])->middleware('can:sales.view');
    Route::post('sales', [SaleController::class, 'store'])->middleware(['can:pos.sale.create', 'token-ability:sales:write']);
    Route::post('sales/{invoice}/void', [SaleController::class, 'void'])->middleware('can:pos.sale.void');
    Route::post('sales/{invoice}/returns', [SaleController::class, 'storeReturn'])->middleware('can:pos.sale.create');
    Route::post('sales-returns/{salesReturn}/refunds', [SaleController::class, 'storeRefund'])->middleware('can:pos.sale.void');
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
    Route::middleware('can:reports.view')->group(function () {
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/stock', [ReportController::class, 'stock']);
    });

    // Offline sync (real implementation, cursor-based)
    Route::get('sync/pull', [SyncController::class, 'pull']);
    Route::post('sync/push', [SyncController::class, 'push']);
    Route::get('devices', [DeviceController::class, 'index']);
    Route::post('devices/revoke', [DeviceController::class, 'revoke']);
    // Accounting (tenant-scoped; requires accounting.view/manage + enabled module)
    Route::prefix('accounting')->middleware('module:accounting')->group(function () {
        Route::get('accounts', [AccountingController::class, 'accounts']);
        Route::get('trial-balance', [AccountingController::class, 'trialBalance']);
        Route::get('profit-loss', [AccountingController::class, 'profitLoss']);
        Route::post('journals', [AccountingController::class, 'storeJournal']);
        Route::post('journals/{entry}/post', [AccountingController::class, 'postJournal']);
        Route::post('journals/{entry}/void', [AccountingController::class, 'voidJournal']);
    });
    // CRM (tenant-scoped; requires crm.view/manage + enabled module)
    Route::prefix('crm')->middleware('module:crm')->group(function () {
        Route::get('leads', [CrmController::class, 'leads']);
        Route::post('leads', [CrmController::class, 'storeLead']);
        Route::get('opportunities', [CrmController::class, 'opportunities']);
        Route::post('opportunities', [CrmController::class, 'storeOpportunity']);
        Route::post('opportunities/{opportunity}/advance', [CrmController::class, 'advanceOpportunity']);
    });

    // Webhooks (tenant outgoing management)
    Route::get('webhooks', [WebhookController::class, 'index']);
});

// Inbound payment webhooks: signature-authenticated, not session-auth (no auth middleware).
// Same gateway_ref is idempotent; replays are safe. Secret per gateway from env.
Route::post('v1/payments/webhooks/{gateway}', [PaymentWebhookController::class, 'handle']);

// Platform admin (no tenant scope, gate platform-admin)
Route::prefix('v1/platform')->middleware(['auth:sanctum', 'can:platform-admin'])->group(function () {
    Route::get('tenants', [PlatformTenantController::class, 'index']);
    Route::post('tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend']);
    Route::get('health', [HealthController::class, 'show']);
});
