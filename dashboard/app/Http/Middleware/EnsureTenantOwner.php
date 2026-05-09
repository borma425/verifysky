<?php

namespace App\Http\Middleware;

use App\Models\TenantMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) session('is_admin')) {
            abort(404);
        }

        $userId = session('user_id');
        $tenantId = trim((string) session('current_tenant_id', ''));
        if (! is_numeric($userId) || $tenantId === '') {
            abort(403);
        }

        $isOwner = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $userId)
            ->where('role', 'owner')
            ->exists();

        abort_unless($isOwner, 403);

        return $next($request);
    }
}
