<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) session('is_admin')) {
            return $next($request);
        }

        if ($request->routeIs('account.suspended') || $request->routeIs('logout')) {
            return $next($request);
        }

        $tenantId = trim((string) session('current_tenant_id', ''));
        if ($tenantId === '') {
            return $next($request);
        }

        if (! Schema::hasTable('tenants')) {
            return $next($request);
        }

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant instanceof Tenant && (string) $tenant->status === 'suspended') {
            return new RedirectResponse(route('account.suspended'));
        }

        return $next($request);
    }
}
