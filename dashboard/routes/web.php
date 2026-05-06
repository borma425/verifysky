<?php

use App\Http\Controllers\Admin\AdminCustomerMirrorController;
use App\Http\Controllers\Admin\AdminOverviewController;
use App\Http\Controllers\Admin\AdminSystemLogsController;
use App\Http\Controllers\Admin\AdminTenantConsoleController;
use App\Http\Controllers\Admin\AdminTenantDomainsController;
use App\Http\Controllers\Admin\AdminTenantsController;
use App\Http\Controllers\AccountActivationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\FirewallRulesController;
use App\Http\Controllers\IpFarmController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SensitivePathsController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\LogAdminCustomerMirrorAccess;
use App\Http\Middleware\NoIndexSensitivePages;
use App\Support\TenantLoginPath;
use Illuminate\Support\Facades\Route;

if (! function_exists('resolveAdminLoginPath')) {
    function resolveAdminLoginPath(): string
    {
        $candidate = trim((string) config('dashboard.login_path', 'wow/login'));

        $candidate = TenantLoginPath::normalize($candidate);

        if (TenantLoginPath::isReserved($candidate)) {
            return 'wow/login';
        }

        return $candidate;
    }
}

$adminLoginPath = resolveAdminLoginPath();

Route::get('/', [MarketingController::class, 'index'])->name('home');
Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('register.store');
Route::get('/register/check-email', [RegistrationController::class, 'pending'])->name('register.pending');
Route::get('/account/activate/{user}/{hash}', AccountActivationController::class)
    ->middleware([NoIndexSensitivePages::class, 'signed:relative', 'throttle:6,1'])
    ->name('account.activate');

Route::get('/'.$adminLoginPath, [AuthController::class, 'show'])
    ->middleware(NoIndexSensitivePages::class)
    ->name('admin.login');
Route::post('/'.$adminLoginPath, [AuthController::class, 'login'])
    ->middleware(NoIndexSensitivePages::class)
    ->middleware('throttle:10,1')
    ->name('admin.login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::post('/webhooks/payments/paypal', [PaymentWebhookController::class, 'paypal'])->name('webhooks.payments.paypal');

Route::middleware([AdminAuth::class, NoIndexSensitivePages::class])->group(function () {
    Route::get('/account-suspended', fn () => view('account-suspended'))->name('account.suspended');

    Route::middleware(['redirect.admin.from.customer', 'tenant.active'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
        Route::post('/billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
        Route::get('/billing/checkout/success', [BillingController::class, 'success'])->name('billing.checkout.success');
        Route::post('/billing/subscription/cancel', [BillingController::class, 'cancelSubscription'])->name('billing.subscription.cancel');

        Route::get('/domains', [DomainsController::class, 'index'])->name('domains.index');
        Route::get('/domains/statuses', [DomainsController::class, 'statuses'])->name('domains.statuses');
        Route::post('/domains', [DomainsController::class, 'store'])->name('domains.store');
        Route::post('/domains/{domain}/sync-group', [DomainsController::class, 'syncGroup'])->name('domains.sync_group');
        Route::delete('/domains/{domain}/group', [DomainsController::class, 'destroyGroup'])->name('domains.destroy_group');
        Route::post('/domains/{domain}/sync-route', [DomainsController::class, 'syncRoute'])->name('domains.sync_route');
        Route::post('/domains/{domain}/security-mode', [DomainsController::class, 'updateSecurityMode'])->name('domains.security_mode');
        Route::post('/domains/{domain}/status', [DomainsController::class, 'updateStatus'])->name('domains.status');
        Route::post('/domains/{domain}/force-captcha', [DomainsController::class, 'toggleForceCaptcha'])->name('domains.force_captcha');
        Route::delete('/domains/{domain}', [DomainsController::class, 'destroy'])->name('domains.destroy');
        Route::post('/domains/{domain}/origin', [DomainsController::class, 'updateOrigin'])->name('domains.update_origin');
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
        Route::post('/logs/clear', [LogsController::class, 'clearLogs'])->name('logs.clear');

        Route::get('/ip-farm', [IpFarmController::class, 'index'])->name('ip_farm.index');
        Route::post('/ip-farm', [IpFarmController::class, 'store'])->name('ip_farm.store');
        Route::put('/ip-farm/{ruleId}', [IpFarmController::class, 'update'])->name('ip_farm.update');
        Route::post('/ip-farm/{ruleId}/append', [IpFarmController::class, 'append'])->name('ip_farm.append');
        Route::post('/ip-farm/{ruleId}/toggle', [IpFarmController::class, 'toggle'])->name('ip_farm.toggle');
        Route::post('/ip-farm/{ruleId}/remove-ips', [IpFarmController::class, 'removeIps'])->name('ip_farm.remove_ips');
        Route::delete('/ip-farm/bulk', [IpFarmController::class, 'bulkDestroy'])->name('ip_farm.bulk_destroy');
        Route::delete('/ip-farm/{ruleId}', [IpFarmController::class, 'destroy'])->name('ip_farm.destroy');
    });

    Route::middleware('redirect.admin.from.customer')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    Route::middleware('admin.only')->group(function () {
        Route::get('/admin', [AdminOverviewController::class, 'index'])->name('admin.overview');
        Route::get('/admin/tenants', [AdminTenantsController::class, 'index'])->name('admin.tenants.index');
        Route::get('/admin/tenants/{tenant}', [AdminTenantsController::class, 'show'])->name('admin.tenants.show');
        Route::get('/admin/tenants/{tenant}/domains', [AdminTenantDomainsController::class, 'index'])->name('admin.tenants.domains.index');
        Route::get('/admin/tenants/{tenant}/domains/statuses', [AdminTenantDomainsController::class, 'statuses'])->name('admin.tenants.domains.statuses');
        Route::post('/admin/tenants/{tenant}/domains', [AdminTenantsController::class, 'storeDomain'])->name('admin.tenants.domains.store');
        Route::post('/admin/tenants/{tenant}/force-cycle-reset', [AdminTenantsController::class, 'forceCycleReset'])->name('admin.tenants.force_cycle_reset');
        Route::post('/admin/tenants/{tenant}/manual-grants', [AdminTenantsController::class, 'grantPlan'])->name('admin.tenants.manual_grants.store');
        Route::post('/admin/tenants/{tenant}/manual-grants/{grant}/revoke', [AdminTenantsController::class, 'revokePlanGrant'])->name('admin.tenants.manual_grants.revoke');
        Route::get('/admin/tenants/{tenant}/firewall', [AdminTenantConsoleController::class, 'firewall'])->name('admin.tenants.firewall.index');
        Route::post('/admin/tenants/{tenant}/firewall', [AdminTenantConsoleController::class, 'storeFirewall'])->name('admin.tenants.firewall.store');
        Route::put('/admin/tenants/{tenant}/firewall/{domain}/{ruleId}', [AdminTenantConsoleController::class, 'updateFirewall'])->name('admin.tenants.firewall.update');
        Route::post('/admin/tenants/{tenant}/firewall/{domain}/{ruleId}/toggle', [AdminTenantConsoleController::class, 'toggleFirewall'])->name('admin.tenants.firewall.toggle');
        Route::delete('/admin/tenants/{tenant}/firewall/{domain}/{ruleId}', [AdminTenantConsoleController::class, 'destroyFirewall'])->name('admin.tenants.firewall.destroy');
        Route::get('/admin/tenants/{tenant}/sensitive-paths', [AdminTenantConsoleController::class, 'sensitivePaths'])->name('admin.tenants.sensitive_paths.index');
        Route::post('/admin/tenants/{tenant}/sensitive-paths', [AdminTenantConsoleController::class, 'storeSensitivePath'])->name('admin.tenants.sensitive_paths.store');
        Route::delete('/admin/tenants/{tenant}/sensitive-paths/{pathId}', [AdminTenantConsoleController::class, 'destroySensitivePath'])->name('admin.tenants.sensitive_paths.destroy');
        Route::get('/admin/tenants/{tenant}/ip-farm', [AdminTenantConsoleController::class, 'ipFarm'])->name('admin.tenants.ip_farm.index');
        Route::post('/admin/tenants/{tenant}/ip-farm', [AdminTenantConsoleController::class, 'storeIpFarm'])->name('admin.tenants.ip_farm.store');
        Route::put('/admin/tenants/{tenant}/ip-farm/{ruleId}', [AdminTenantConsoleController::class, 'updateIpFarm'])->name('admin.tenants.ip_farm.update');
        Route::post('/admin/tenants/{tenant}/ip-farm/{ruleId}/append', [AdminTenantConsoleController::class, 'appendIpFarm'])->name('admin.tenants.ip_farm.append');
        Route::post('/admin/tenants/{tenant}/ip-farm/{ruleId}/toggle', [AdminTenantConsoleController::class, 'toggleIpFarm'])->name('admin.tenants.ip_farm.toggle');
        Route::post('/admin/tenants/{tenant}/ip-farm/{ruleId}/remove-ips', [AdminTenantConsoleController::class, 'removeIpFarmIpsFromRule'])->name('admin.tenants.ip_farm.remove_ips');
        Route::post('/admin/tenants/{tenant}/ip-farm/remove', [AdminTenantConsoleController::class, 'removeIpFarmIps'])->name('admin.tenants.ip_farm.remove');
        Route::delete('/admin/tenants/{tenant}/ip-farm/bulk', [AdminTenantConsoleController::class, 'bulkDestroyIpFarm'])->name('admin.tenants.ip_farm.bulk_destroy');
        Route::delete('/admin/tenants/{tenant}/ip-farm/{ruleId}', [AdminTenantConsoleController::class, 'destroyIpFarm'])->name('admin.tenants.ip_farm.destroy');
        Route::post('/admin/tenants/{tenant}/account/suspend', [AdminTenantConsoleController::class, 'suspend'])->name('admin.tenants.account.suspend');
        Route::post('/admin/tenants/{tenant}/account/resume', [AdminTenantConsoleController::class, 'resume'])->name('admin.tenants.account.resume');
        Route::delete('/admin/tenants/{tenant}/account/delete', [AdminTenantConsoleController::class, 'delete'])->name('admin.tenants.account.delete');
        Route::middleware(LogAdminCustomerMirrorAccess::class)->prefix('/admin/tenants/{tenant}/customer')->name('admin.tenants.customer.')->group(function () {
            Route::get('/', [AdminCustomerMirrorController::class, 'overview'])->name('overview');
            Route::get('/billing', [AdminCustomerMirrorController::class, 'billing'])->name('billing.index');
            Route::get('/domains', [AdminCustomerMirrorController::class, 'domains'])->name('domains.index');
            Route::get('/domains/{domain}/tuning', [AdminCustomerMirrorController::class, 'tuning'])->name('domains.tuning');
            Route::get('/firewall', [AdminCustomerMirrorController::class, 'firewall'])->name('firewall.index');
            Route::get('/logs', [AdminCustomerMirrorController::class, 'logs'])->name('logs.index');
            Route::match(['post', 'put', 'patch', 'delete'], '/{path?}', [AdminCustomerMirrorController::class, 'rejectMutation'])
                ->where('path', '.*')
                ->name('read_only_block');
        });
        Route::get('/admin/tenants/{tenant}/domains/{domain}', [AdminTenantDomainsController::class, 'show'])->name('admin.tenants.domains.show');
        Route::delete('/admin/tenants/{tenant}/domains/{domain}/group', [AdminTenantDomainsController::class, 'destroyGroup'])->name('admin.tenants.domains.destroy_group');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/sync-group', [AdminTenantDomainsController::class, 'syncGroup'])->name('admin.tenants.domains.sync_group');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/origin', [AdminTenantDomainsController::class, 'updateOrigin'])->name('admin.tenants.domains.origin.update');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/security-mode', [AdminTenantDomainsController::class, 'updateSecurityMode'])->name('admin.tenants.domains.security_mode.update');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/status', [AdminTenantDomainsController::class, 'updateStatus'])->name('admin.tenants.domains.status.update');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/force-captcha', [AdminTenantDomainsController::class, 'toggleForceCaptcha'])->name('admin.tenants.domains.force_captcha.update');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/tuning', [AdminTenantDomainsController::class, 'updateTuning'])->name('admin.tenants.domains.tuning.update');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/sync-route', [AdminTenantDomainsController::class, 'syncRoute'])->name('admin.tenants.domains.sync_route');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/purge-runtime-cache', [AdminTenantDomainsController::class, 'purgeRuntimeCache'])->name('admin.tenants.domains.runtime_cache.purge');
        Route::get('/admin/tenants/{tenant}/domains/{domain}/firewall', [AdminTenantConsoleController::class, 'firewall'])->name('admin.tenants.domains.firewall.index');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/firewall', [AdminTenantConsoleController::class, 'storeFirewall'])->name('admin.tenants.domains.firewall.store');
        Route::put('/admin/tenants/{tenant}/domains/{domain}/firewall/{ruleId}', [AdminTenantConsoleController::class, 'updateFirewall'])->name('admin.tenants.domains.firewall.update');
        Route::post('/admin/tenants/{tenant}/domains/{domain}/firewall/{ruleId}/toggle', [AdminTenantConsoleController::class, 'toggleFirewall'])->name('admin.tenants.domains.firewall.toggle');
        Route::delete('/admin/tenants/{tenant}/domains/{domain}/firewall/{ruleId}', [AdminTenantConsoleController::class, 'destroyFirewall'])->name('admin.tenants.domains.firewall.destroy');
        Route::get('/admin/settings', [SettingsController::class, 'index'])->name('admin.settings.index');
        Route::post('/admin/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
        Route::get('/admin/logs/security', [AdminSystemLogsController::class, 'security'])->name('admin.logs.security');
        Route::get('/admin/logs/platform', [AdminSystemLogsController::class, 'platform'])->name('admin.logs.platform');
    });
});

Route::get('/{tenantLoginPath}', [AuthController::class, 'showTenantLogin'])
    ->where('tenantLoginPath', '.+')
    ->middleware(NoIndexSensitivePages::class)
    ->name('tenant.login');
Route::post('/{tenantLoginPath}', [AuthController::class, 'loginTenant'])
    ->where('tenantLoginPath', '.+')
    ->middleware(NoIndexSensitivePages::class)
    ->middleware('throttle:10,1')
    ->name('tenant.login.submit');
