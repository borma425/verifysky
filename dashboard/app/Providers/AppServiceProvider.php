<?php

namespace App\Providers;

use App\Models\TenantMembership;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Billing\Contracts\PaymentGatewayInterface;
use App\Services\Billing\Payments\PayPalGatewayService;
use App\Services\Billing\TenantBillingStatusService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, PayPalGatewayService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);

        View::composer('layouts.app', function ($view): void {
            if (! session()->get('is_authenticated') || session()->get('is_admin')) {
                $view->with('layoutBillingStatus', null);
                $view->with('layoutWorkspaces', collect());
                $view->with('layoutCurrentTenantId', null);

                return;
            }

            $tenantId = (string) session('current_tenant_id', '');
            $billingStatus = app(TenantBillingStatusService::class)->forTenantId(
                $tenantId
            );
            $userId = session('user_id');
            $workspaces = is_numeric($userId)
                ? TenantMembership::query()
                    ->with('tenant:id,name,slug')
                    ->where('user_id', (int) $userId)
                    ->orderBy('id')
                    ->get()
                    ->filter(fn (TenantMembership $membership): bool => $membership->tenant !== null)
                : collect();

            $view->with('layoutBillingStatus', $billingStatus);
            $view->with('layoutWorkspaces', $workspaces);
            $view->with('layoutCurrentTenantId', $tenantId);
        });
    }
}
