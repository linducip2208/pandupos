<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Platform\AuditController;
use App\Http\Controllers\Platform\HealthController;
use App\Http\Controllers\Platform\ModuleController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'))->name('home');

Route::get('/login', [AuthController::class, 'login'])->name('login');
Route::post('/login', [AuthController::class, 'attempt'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/pos', fn () => view('pos.index'))->name('pos.index')
        ->middleware(['entitlement:pos.access', 'module:pos']);
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
    Route::get('/billing', fn () => view('platform.simple', ['title' => 'Billing']))->name('billing.index');
    Route::get('/subscriptions', fn () => view('platform.simple', ['title' => 'Subscriptions']))->name('subscriptions.index');
    Route::get('/coupons', fn () => view('platform.simple', ['title' => 'Coupons']))->name('coupons.index');
    Route::get('/affiliates', fn () => view('platform.simple', ['title' => 'Affiliates']))->name('affiliates.index');
    Route::get('/announcements', fn () => view('platform.simple', ['title' => 'Announcements']))->name('announcements.index');
    Route::get('/settings', fn () => view('platform.simple', ['title' => 'Settings']))->name('settings.index');
    Route::get('/entitlements', fn () => view('platform.simple', ['title' => 'Entitlements']))->name('entitlements.index');
});
