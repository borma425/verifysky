<?php

namespace App\Providers;

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

                return;
            }

            $billingStatus = app(TenantBillingStatusService::class)->forTenantId(
                (string) session('current_tenant_id', '')
            );

            $view->with('layoutBillingStatus', $billingStatus);
        });
    }
}
