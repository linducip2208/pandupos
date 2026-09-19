<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarcodeWorkspaceController;
use App\Http\Controllers\BatchWorkspaceController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BundleWorkspaceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\InventoryWorkspaceController;
use App\Http\Controllers\Platform\AffiliateController;
use App\Http\Controllers\Platform\AnnouncementController;
use App\Http\Controllers\Platform\AuditController;
use App\Http\Controllers\Platform\BillingController;
use App\Http\Controllers\Platform\BlogAdminController;
use App\Http\Controllers\Platform\CouponController;
use App\Http\Controllers\Platform\HealthController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\IntegrationProviderController;
use App\Http\Controllers\Platform\ModuleController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\SubscriptionAdminController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Portal\AuthController as PortalAuthController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboardController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\OrderController as PortalOrderController;
use App\Http\Controllers\Portal\PaymentProofController as PortalPaymentProofController;
use App\Http\Controllers\PriceListWorkspaceController;
use App\Http\Controllers\ProductMasterController;
use App\Http\Controllers\ProgrammaticSeoController;
use App\Http\Controllers\PurchasingWorkspaceController;
use App\Http\Controllers\RegisterWorkspaceController;
use App\Http\Controllers\ReportPageController;
use App\Http\Controllers\SalesDocumentWorkspaceController;
use App\Http\Controllers\SalesOrderWorkspaceController;
use App\Http\Controllers\SalesReturnWorkspaceController;
use App\Http\Controllers\SerialWorkspaceController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('welcome'))->name('home');
Route::get('/docs', [DocsController::class, 'index'])->name('docs');
Route::view('/faq', 'public.faq')->name('faq');
Route::view('/contact', 'public.contact')->name('contact');
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/feed.xml', [BlogController::class, 'feed'])->name('blog.feed');
Route::get('/blog/category/{category:slug}', [BlogController::class, 'index'])->name('blog.category');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap/content.xml', [SitemapController::class, 'content'])->name('sitemap.content');
Route::get('/sitemap/pseo-{chunk}.xml', [SitemapController::class, 'pseo'])->whereNumber('chunk')->name('sitemap.pseo');

Route::get('/best-{category}-{year}', [ProgrammaticSeoController::class, 'best'])
    ->where(['category' => '[a-z0-9-]+', 'year' => '[0-9]{4}'])->name('pseo.best.year');
Route::get('/best-{category}', [ProgrammaticSeoController::class, 'best'])
    ->where('category', '[a-z0-9-]+')->name('pseo.best');
Route::get('/alternatives-to-{slug}', [ProgrammaticSeoController::class, 'alternatives'])
    ->where('slug', '[a-z0-9-]+')->name('pseo.alternatives');
Route::get('/compare/{a}-vs-{b}', [ProgrammaticSeoController::class, 'compare'])
    ->where(['a' => '[a-z0-9-]+', 'b' => '[a-z0-9-]+'])->name('pseo.compare');
Route::get('/source-code-pos/{industry}/{city}/{feature}', [ProgrammaticSeoController::class, 'sourceCode'])
    ->where(['industry' => '[a-z0-9-]+', 'city' => '[a-z0-9-]+', 'feature' => '[a-z0-9-]+'])->name('pseo.source-code');
Route::get('/{intent}-pos/{industry}/{city}/{feature}', [ProgrammaticSeoController::class, 'growth'])
    ->where(['intent' => 'aplikasi|software|sistem|platform|solusi|rekomendasi|panduan|otomasi|digitalisasi|manajemen', 'industry' => '[a-z0-9-]+', 'city' => '[a-z0-9-]+', 'feature' => '[a-z0-9-]+'])->name('pseo.growth');

Route::get('/login', [AuthController::class, 'login'])->name('login');
Route::post('/login', [AuthController::class, 'attempt'])->middleware('throttle:10,1')->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:customer')->group(function () {
        Route::get('/login', [PortalAuthController::class, 'create'])->name('login');
        Route::post('/login', [PortalAuthController::class, 'store'])->middleware('throttle:10,1')->name('login.attempt');
    });
    Route::middleware(['auth:customer', 'portal.tenant'])->group(function () {
        Route::post('/logout', [PortalAuthController::class, 'destroy'])->name('logout');
        Route::get('/', PortalDashboardController::class)->name('dashboard');
        Route::get('/orders', [PortalOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{invoice}', [PortalOrderController::class, 'show'])->whereNumber('invoice')->name('orders.show');
        Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [PortalInvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', [PortalInvoiceController::class, 'pdf'])->whereNumber('invoice')->name('invoices.pdf');
        Route::post('/invoices/{invoice}/payment-proof', [PortalPaymentProofController::class, 'store'])->whereNumber('invoice')->middleware('throttle:20,1')->name('payment-proofs.store');
        Route::get('/invoices/{invoice}/payment-proof/{proof}', [PortalPaymentProofController::class, 'download'])->whereNumber(['invoice', 'proof'])->name('payment-proofs.download');
    });
});

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::prefix('products')->name('product-master.')->middleware(['entitlement:inventory.access', 'module:inventory'])->group(function () {
        Route::get('/', [ProductMasterController::class, 'index'])->name('index');
        Route::get('/create', [ProductMasterController::class, 'create'])->name('create');
        Route::post('/', [ProductMasterController::class, 'store'])->name('store');
        Route::post('/categories', [ProductMasterController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [ProductMasterController::class, 'updateCategory'])->name('categories.update');
        Route::post('/brands', [ProductMasterController::class, 'storeBrand'])->name('brands.store');
        Route::put('/brands/{brand}', [ProductMasterController::class, 'updateBrand'])->name('brands.update');
        Route::post('/units', [ProductMasterController::class, 'storeUnit'])->name('units.store');
        Route::put('/units/{unit}', [ProductMasterController::class, 'updateUnit'])->name('units.update');
        Route::post('/unit-conversions', [ProductMasterController::class, 'storeUnitConversion'])->name('unit-conversions.store');
        Route::delete('/unit-conversions/{conversion}', [ProductMasterController::class, 'destroyUnitConversion'])->name('unit-conversions.destroy');
        Route::post('/masters/{type}/{id}/archive', [ProductMasterController::class, 'archiveMaster'])->where('type', 'category|brand|unit')->name('masters.archive');
        Route::get('/{product}', [ProductMasterController::class, 'show'])->name('show');
        Route::get('/{product}/edit', [ProductMasterController::class, 'edit'])->name('edit');
        Route::put('/{product}', [ProductMasterController::class, 'update'])->name('update');
        Route::post('/{product}/archive', [ProductMasterController::class, 'archive'])->name('archive');
    });
    Route::prefix('batches')->name('batches.')->middleware(['entitlement:inventory.access', 'module:inventory'])->group(function () {
        Route::get('/', [BatchWorkspaceController::class, 'index'])->name('index');
        Route::post('/receive', [BatchWorkspaceController::class, 'receive'])->name('receive');
    });
    Route::prefix('serials')->name('serials.')->middleware(['entitlement:inventory.access', 'module:inventory'])->group(function () {
        Route::get('/', [SerialWorkspaceController::class, 'index'])->name('index');
        Route::post('/receive', [SerialWorkspaceController::class, 'receive'])->name('receive');
        Route::post('/reserve', [SerialWorkspaceController::class, 'reserve'])->name('reserve');
        Route::post('/{serial}/release', [SerialWorkspaceController::class, 'release'])->name('release');
    });
    Route::get('/pos', fn () => view('pos.index'))->name('pos.index')
        ->middleware(['entitlement:pos.access', 'module:pos']);
    Route::prefix('registers')->name('registers.')->middleware(['entitlement:pos.access', 'module:pos'])->group(function () {
        Route::get('/', [RegisterWorkspaceController::class, 'index'])->name('index');
        Route::post('/', [RegisterWorkspaceController::class, 'storeRegister'])->name('store');
        Route::post('/{register}/open', [RegisterWorkspaceController::class, 'open'])->name('open');
        Route::post('/{register}/deactivate', [RegisterWorkspaceController::class, 'deactivate'])->name('deactivate');
        Route::post('/sessions/{session}/movements', [RegisterWorkspaceController::class, 'movement'])->name('movements.store');
        Route::post('/sessions/{session}/close', [RegisterWorkspaceController::class, 'close'])->name('close');
    });
    Route::prefix('barcodes')->name('barcodes.')->middleware(['entitlement:inventory.access', 'module:inventory'])->group(function () {
        Route::get('/', [BarcodeWorkspaceController::class, 'index'])->name('index');
        Route::post('/profiles', [BarcodeWorkspaceController::class, 'storeProfile'])->name('profiles.store');
        Route::put('/profiles/{profile}', [BarcodeWorkspaceController::class, 'updateProfile'])->name('profiles.update');
        Route::post('/profiles/{profile}/archive', [BarcodeWorkspaceController::class, 'archiveProfile'])->name('profiles.archive');
        Route::get('/labels', [BarcodeWorkspaceController::class, 'labels'])->name('labels');
    });
    Route::prefix('bundles')->name('bundles.')->middleware(['entitlement:inventory.access', 'module:inventory'])->group(function () {
        Route::get('/', [BundleWorkspaceController::class, 'index'])->name('index');
        Route::post('/{product}/components', [BundleWorkspaceController::class, 'sync'])->name('sync');
    });
    Route::prefix('sales-orders')->name('sales-orders.')->middleware(['entitlement:sales.access', 'module:sales'])->group(function () {
        Route::get('/', [SalesOrderWorkspaceController::class, 'index'])->name('index');
        Route::post('/', [SalesOrderWorkspaceController::class, 'store'])->name('store');
        Route::post('/{order}/confirm', [SalesOrderWorkspaceController::class, 'confirm'])->name('confirm');
        Route::post('/{order}/deliver', [SalesOrderWorkspaceController::class, 'deliver'])->name('deliver');
        Route::post('/{order}/invoice', [SalesOrderWorkspaceController::class, 'invoice'])->name('invoice');
        Route::post('/invoices/{invoice}/payments', [SalesOrderWorkspaceController::class, 'payInvoice'])->name('invoices.payments.store');
        Route::post('/{order}/cancel', [SalesOrderWorkspaceController::class, 'cancel'])->name('cancel');
    });
    Route::prefix('sales-returns')->name('sales-returns.')->middleware(['entitlement:sales.access', 'module:sales'])->group(function () {
        Route::get('/', [SalesReturnWorkspaceController::class, 'index'])->name('index');
        Route::post('/invoices/{invoice}/returns', [SalesReturnWorkspaceController::class, 'store'])->name('returns.store');
        Route::post('/returns/{salesReturn}/refunds', [SalesReturnWorkspaceController::class, 'refund'])->name('refunds.store');
        Route::post('/invoices/{invoice}/void', [SalesReturnWorkspaceController::class, 'void'])->name('void.store');
    });
    Route::prefix('sales-documents')->name('sales-documents.')->middleware(['entitlement:sales.access', 'module:sales'])->group(function () {
        Route::get('/', [SalesDocumentWorkspaceController::class, 'index'])->name('index');
        Route::post('/quotations', [SalesDocumentWorkspaceController::class, 'store'])->name('quotations.store');
        Route::post('/quotations/{quotation}/transition', [SalesDocumentWorkspaceController::class, 'transition'])->name('quotations.transition');
        Route::get('/quotations/{quotation}/print', [SalesDocumentWorkspaceController::class, 'print'])->name('quotations.print');
    });
    Route::prefix('price-lists')->name('price-lists.')->middleware(['entitlement:inventory.access', 'module:inventory'])->group(function () {
        Route::get('/', [PriceListWorkspaceController::class, 'index'])->name('index');
        Route::post('/', [PriceListWorkspaceController::class, 'store'])->name('store');
        Route::put('/{priceList}', [PriceListWorkspaceController::class, 'update'])->name('update');
        Route::post('/{priceList}/archive', [PriceListWorkspaceController::class, 'archive'])->name('archive');
    });
    Route::middleware('can:reports.view')->group(function () {
        Route::get('/reports/{type}', [ReportPageController::class, 'show'])->name('reports.show');
        Route::get('/reports/{type}/csv', [ReportPageController::class, 'csv'])->name('reports.csv');
        Route::get('/reports/{type}/xlsx', [ReportPageController::class, 'xlsx'])->name('reports.xlsx');
        Route::get('/reports/{type}/pdf', [ReportPageController::class, 'pdf'])->name('reports.pdf');
    });
    Route::get('/tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
    Route::post('/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/settings', [ApprovalController::class, 'setting'])->name('approvals.setting');
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
    Route::prefix('inventory')->name('inventory.')->middleware(['entitlement:inventory.access', 'module:inventory'])->group(function () {
        Route::get('/', [InventoryWorkspaceController::class, 'index'])->name('index');
        Route::post('/locations', [InventoryWorkspaceController::class, 'storeLocation'])->name('locations.store');
        Route::put('/locations/{location}', [InventoryWorkspaceController::class, 'updateLocation'])->name('locations.update');
        Route::post('/locations/{location}/deactivate', [InventoryWorkspaceController::class, 'deactivateLocation'])->name('locations.deactivate');
        Route::post('/reservations', [InventoryWorkspaceController::class, 'storeReservation'])->name('reservations.store');
        Route::post('/reservations/{reservation}/release', [InventoryWorkspaceController::class, 'releaseReservation'])->name('reservations.release');
        Route::post('/transfers', [InventoryWorkspaceController::class, 'storeTransfer'])->name('transfers.store');
        Route::post('/transfers/{transfer}/receive', [InventoryWorkspaceController::class, 'receiveTransfer'])->name('transfers.receive');
        Route::post('/transfers/{transfer}/{action}', [InventoryWorkspaceController::class, 'transferAction'])->where('action', 'approve|ship|transit|cancel')->name('transfers.action');
        Route::post('/adjustments', [InventoryWorkspaceController::class, 'storeAdjustment'])->name('adjustments.store');
        Route::post('/adjustments/{adjustment}/{action}', [InventoryWorkspaceController::class, 'adjustmentAction'])->where('action', 'submit|approve|post')->name('adjustments.action');
        Route::post('/counts', [InventoryWorkspaceController::class, 'storeCount'])->name('counts.store');
        Route::post('/counts/{count}/record', [InventoryWorkspaceController::class, 'recordCount'])->name('counts.record');
        Route::post('/counts/{count}/{action}', [InventoryWorkspaceController::class, 'countAction'])->where('action', 'approve|post')->name('counts.action');
    });
    Route::prefix('purchasing')->name('purchasing.')->middleware(['entitlement:purchase.access', 'module:purchasing'])->group(function () {
        Route::get('/', [PurchasingWorkspaceController::class, 'index'])->name('index');
        Route::get('/orders/create', [PurchasingWorkspaceController::class, 'createPurchase'])->name('orders.create');
        Route::post('/orders', [PurchasingWorkspaceController::class, 'storePurchase'])->name('orders.store');
        Route::get('/orders/{purchase}/edit', [PurchasingWorkspaceController::class, 'editPurchase'])->name('orders.edit');
        Route::put('/orders/{purchase}', [PurchasingWorkspaceController::class, 'updatePurchase'])->name('orders.update');
        Route::post('/orders/{purchase}/submit', [PurchasingWorkspaceController::class, 'submitPurchase'])->name('orders.submit');
        Route::post('/orders/{purchase}/cancel', [PurchasingWorkspaceController::class, 'cancelPurchase'])->name('orders.cancel');
        Route::get('/orders/{purchase}/print', [PurchasingWorkspaceController::class, 'printPurchase'])->name('orders.print');
        Route::post('/orders/{purchase}/receive', [PurchasingWorkspaceController::class, 'receive'])->name('orders.receive');
        Route::get('/orders/{purchase}/returns/create', [PurchasingWorkspaceController::class, 'createReturn'])->name('returns.create');
        Route::post('/orders/{purchase}/returns', [PurchasingWorkspaceController::class, 'storeReturn'])->name('returns.store');
        Route::post('/orders/{purchase}/returns/draft', [PurchasingWorkspaceController::class, 'storeDraftReturn'])->name('returns.draft');
        Route::get('/returns/{purchaseReturn}', [PurchasingWorkspaceController::class, 'showReturn'])->name('returns.show');
        Route::post('/returns/{purchaseReturn}/submit', [PurchasingWorkspaceController::class, 'submitReturn'])->name('returns.submit');
        Route::post('/returns/{purchaseReturn}/approve', [PurchasingWorkspaceController::class, 'approveReturn'])->name('returns.approve');
        Route::post('/returns/{purchaseReturn}/post', [PurchasingWorkspaceController::class, 'postReturn'])->name('returns.post');
        Route::post('/invoices', [PurchasingWorkspaceController::class, 'storeInvoice'])->name('invoices.store');
        Route::get('/invoices/{invoice}/print', [PurchasingWorkspaceController::class, 'printInvoice'])->name('invoices.print');
        Route::post('/invoices/{invoice}/payments', [PurchasingWorkspaceController::class, 'pay'])->name('payments.store');
    });
});

Route::prefix('platform')->name('platform.')->middleware(['auth', 'can:platform-admin'])->group(function () {
    Route::get('/dashboard', [App\Http\Controllers\Platform\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
    Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('/tenants/{tenant}/archive', [TenantController::class, 'archive'])->name('tenants.archive');
    Route::post('/tenants/{tenant}/plan', [TenantController::class, 'changePlan'])->name('tenants.plan');
    Route::post('/tenants/{tenant}/extend', [TenantController::class, 'extend'])->name('tenants.extend');
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
    Route::post('/plans/{plan}/entitlements', [PlanController::class, 'updateEntitlements'])->name('plans.entitlements');
    Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/health', [HealthController::class, 'index'])->name('health');
    Route::post('/tenants/{tenant}/impersonate', [ImpersonationController::class, 'start'])->name('tenants.impersonate');
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->withoutMiddleware('can:platform-admin')->name('impersonation.stop');
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('/subscriptions', [SubscriptionAdminController::class, 'index'])->name('subscriptions.index');
    Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::get('/affiliates', [AffiliateController::class, 'index'])->name('affiliates.index');
    Route::post('/affiliates/payout', [AffiliateController::class, 'payout'])->name('affiliates.payout');
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::post('/announcements/{announcement}/send', [AnnouncementController::class, 'send'])->name('announcements.send');
    Route::get('/blog', [BlogAdminController::class, 'index'])->name('blog.index');
    Route::post('/blog', [BlogAdminController::class, 'store'])->name('blog.store');
    Route::put('/blog/{post}', [BlogAdminController::class, 'update'])->name('blog.update');
    Route::delete('/blog/{post}', [BlogAdminController::class, 'destroy'])->name('blog.destroy');
    Route::get('/integrations', [IntegrationProviderController::class, 'index'])->name('integrations.index');
    Route::post('/integrations', [IntegrationProviderController::class, 'store'])->name('integrations.store');
    Route::put('/integrations/{provider}', [IntegrationProviderController::class, 'update'])->name('integrations.update');
    Route::delete('/integrations/{provider}', [IntegrationProviderController::class, 'destroy'])->name('integrations.destroy');
    Route::post('/integrations/{provider}/discover', [IntegrationProviderController::class, 'discover'])->name('integrations.discover');
    Route::post('/integration-assignments', [IntegrationProviderController::class, 'assign'])->name('integrations.assign');
    Route::get('/settings', fn () => view('platform.simple', ['title' => 'Settings']))->name('settings.index');
    Route::get('/entitlements', fn () => view('platform.simple', ['title' => 'Entitlements']))->name('entitlements.index');
});
