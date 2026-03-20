<?php

use App\Http\Controllers\ActionsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\DomainRulesController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\AdminAuth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(AdminAuth::class)->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/domains', [DomainsController::class, 'index'])->name('domains.index');
    Route::post('/domains', [DomainsController::class, 'store'])->name('domains.store');
    Route::post('/domains/{domain}/status', [DomainsController::class, 'updateStatus'])->name('domains.status');
    Route::post('/domains/{domain}/force-captcha', [DomainsController::class, 'toggleForceCaptcha'])->name('domains.force_captcha');
    Route::post('/domains/{domain}/security-mode', [DomainsController::class, 'updateSecurityMode'])->name('domains.security_mode');
    Route::post('/domains/{domain}/sync-route', [DomainsController::class, 'syncRoute'])->name('domains.sync_route');
    Route::delete('/domains/{domain}', [DomainsController::class, 'destroy'])->name('domains.destroy');
    Route::get('/domains/{domain}/rules', [DomainRulesController::class, 'index'])->name('domains.rules');
    Route::post('/domains/{domain}/rules', [DomainRulesController::class, 'storeFirewallRule'])->name('domains.rules.store');
    Route::post('/domains/{domain}/rules/{ruleId}/toggle', [DomainRulesController::class, 'toggleFirewallRule'])->name('domains.rules.toggle');
    Route::delete('/domains/{domain}/rules/{ruleId}', [DomainRulesController::class, 'destroyFirewallRule'])->name('domains.rules.destroy');

    Route::get('/logs', [LogsController::class, 'index'])->name('logs.index');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/actions', [ActionsController::class, 'index'])->name('actions.index');
    Route::post('/actions/run', [ActionsController::class, 'run'])->name('actions.run');
});
