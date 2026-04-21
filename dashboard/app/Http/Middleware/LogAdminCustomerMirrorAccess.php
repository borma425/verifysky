<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Admin\AdminCustomerMirrorAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAdminCustomerMirrorAccess
{
    public function __construct(private readonly AdminCustomerMirrorAuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');

        if ($tenant instanceof Tenant) {
            $this->audit->record($request, $tenant);
        }

        return $next($request);
    }
}
