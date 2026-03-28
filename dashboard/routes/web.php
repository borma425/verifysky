<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\FirewallRulesController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\SensitivePathsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TrapNetworkController;
use App\Http\Controllers\IpFarmController;
use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\NoIndexSensitivePages;
use App\Models\DashboardSetting;
use Illuminate\Support\Facades\Route;

if (!function_exists('resolveAdminLoginPath')) {
    function resolveAdminLoginPath(): string
    {
        $fallback = trim((string) env('DASHBOARD_LOGIN_PATH', 'wow/login'));
        $candidate = $fallback;

        try {
            $fromDb = DashboardSetting::query()
                ->where('key', 'admin_login_path')
                ->value('value');
            if (is_string($fromDb) && trim($fromDb) !== '') {
                $candidate = $fromDb;
            }
        } catch (\Throwable) {
            // Table may not exist yet during first bootstrap.
        }

        $candidate = trim(strtolower($candidate));
        $candidate = ltrim($candidate, '/');
        $candidate = preg_replace('/[^a-z0-9\/_-]/', '', $candidate) ?? '';
        $candidate = preg_replace('#/+#', '/', $candidate) ?? '';
        $candidate = trim($candidate, '/');

        $reserved = [
            '',
            'login',
            'logout',
            'dashboard',
            'domains',
            'logs',
            'settings',
            'actions',
            'api',
        ];
        if (in_array($candidate, $reserved, true)) {
            return 'wow/login';
        }

        return $candidate;
    }
}

$adminLoginPath = resolveAdminLoginPath();

Route::get('/', [MarketingController::class, 'index'])->name('home');
Route::post('/contact/interest', [MarketingController::class, 'storeLead'])
    ->middleware('throttle:20,1')
    ->name('marketing.lead');

Route::get('/'.$adminLoginPath, [AuthController::class, 'show'])
    ->middleware(NoIndexSensitivePages::class)
    ->name('login');
Route::post('/'.$adminLoginPath, [AuthController::class, 'login'])
    ->middleware(NoIndexSensitivePages::class)
    ->middleware('throttle:10,1')
    ->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware([AdminAuth::class, NoIndexSensitivePages::class])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/domains', [DomainsController::class, 'index'])->name('domains.index');
    Route::post('/domains', [DomainsController::class, 'store'])->name('domains.store');
    Route::post('/domains/{domain}/status', [DomainsController::class, 'updateStatus'])->name('domains.status');
    Route::post('/domains/{domain}/force-captcha', [DomainsController::class, 'toggleForceCaptcha'])->name('domains.force_captcha');
    Route::post('/domains/{domain}/security-mode', [DomainsController::class, 'updateSecurityMode'])->name('domains.security_mode');
    Route::post('/domains/{domain}/sync-route', [DomainsController::class, 'syncRoute'])->name('domains.sync_route');
    Route::delete('/domains/{domain}', [DomainsController::class, 'destroy'])->name('domains.destroy');
    Route::get('/domains/{domain}/tuning', [DomainsController::class, 'tuning'])->name('domains.tuning');
    Route::post('/domains/{domain}/tuning', [DomainsController::class, 'updateTuning'])->name('domains.update_tuning');

    Route::get('/firewall', [FirewallRulesController::class, 'index'])->name('firewall.index');
    Route::post('/firewall', [FirewallRulesController::class, 'store'])->name('firewall.store');
    Route::get('/firewall/{domain}/{ruleId}/edit', [FirewallRulesController::class, 'edit'])->name('firewall.edit');
    Route::put('/firewall/{domain}/{ruleId}', [FirewallRulesController::class, 'update'])->name('firewall.update');
    Route::post('/firewall/{domain}/{ruleId}/toggle', [FirewallRulesController::class, 'toggle'])->name('firewall.toggle');
    Route::delete('/firewall/bulk', [FirewallRulesController::class, 'bulkDestroy'])->name('firewall.bulk_destroy');
    Route::delete('/firewall/{domain}/{ruleId}', [FirewallRulesController::class, 'destroy'])->name('firewall.destroy');

    Route::get('/sensitive-paths', [SensitivePathsController::class, 'index'])->name('sensitive_paths.index');
    Route::post('/sensitive-paths', [SensitivePathsController::class, 'store'])->name('sensitive_paths.store');
    Route::delete('/sensitive-paths/bulk', [SensitivePathsController::class, 'bulkDestroy'])->name('sensitive_paths.bulk_destroy');
    Route::delete('/sensitive-paths/{pathId}', [SensitivePathsController::class, 'destroy'])->name('sensitive_paths.destroy');

    Route::get('/logs', [LogsController::class, 'index'])->name('logs.index');
    Route::post('/logs/allow-ip', [LogsController::class, 'allowIp'])->name('logs.allow_ip');
    Route::post('/logs/block-ip', [LogsController::class, 'blockIp'])->name('logs.block_ip');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/trap-network', [TrapNetworkController::class, 'index'])->name('trap_network.index');
    Route::delete('/trap-network/{lead}', [TrapNetworkController::class, 'destroy'])->name('trap_network.destroy');

    Route::get('/ip-farm', [IpFarmController::class, 'index'])->name('ip_farm.index');
});
