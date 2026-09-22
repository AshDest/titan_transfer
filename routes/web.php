<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransferFeeRuleController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureApplicationAccess;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::middleware(EnsureApplicationAccess::class)->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->middleware('can:dashboard.view')->name('dashboard');
        Route::get('/transfers/people', [TransferController::class, 'people'])->name('transfers.people');
        Route::post('/transfers/preview', [TransferController::class, 'preview'])->name('transfers.preview');
        Route::post('/transfers/revise', [TransferController::class, 'revise'])->name('transfers.revise');
        Route::resource('transfers', TransferController::class)->only(['index', 'create', 'store', 'show']);
        Route::resource('customers', CustomerController::class)->only(['index', 'create', 'store']);
        Route::resource('beneficiaries', BeneficiaryController::class)->only(['index', 'create', 'store']);
        Route::resource('branches', BranchController::class)->only(['index', 'create', 'store']);
        Route::resource('exchange-rates', ExchangeRateController::class)->only(['index', 'create', 'store']);
        Route::resource('fee-rules', TransferFeeRuleController::class)->only(['index', 'create', 'store']);
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    });
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
