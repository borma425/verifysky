<?php

use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\RedirectAdminFromCustomerShell;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.only' => AdminOnly::class,
            'tenant.active' => EnsureTenantActive::class,
            'redirect.admin.from.customer' => RedirectAdminFromCustomerShell::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/paypal',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
