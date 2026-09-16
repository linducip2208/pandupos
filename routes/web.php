<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
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

Route::get('/platform/health', fn () => view('dashboard', [
    'tenant' => null, 'sales' => [], 'usage' => [], 'subscription' => null, 'plan' => null,
]))->name('platform.health')->middleware('auth');
