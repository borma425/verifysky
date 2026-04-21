<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminAuth
{
    public function __construct(private readonly TenantContextService $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! session()->get('is_authenticated') && ! session()->get('is_admin')) {
            if ($request->expectsJson()) {
                abort(401);
            }

            // Do not leak the hidden admin login route via redirects.
            throw new NotFoundHttpException;
        }

        $this->backfillTenantContext();

        return $next($request);
    }

    private function backfillTenantContext(): void
    {
        if (session()->has('current_tenant_id') || session()->get('is_admin')) {
            return;
        }

        $userId = session()->get('user_id');
        if (! $userId) {
            return;
        }

        $user = User::query()
            ->whereKey($userId)
            ->with('tenantMemberships:id,user_id,tenant_id')
            ->first();
        if (! $user instanceof User) {
            return;
        }

        $tenantId = $this->tenantContext->resolveTenantIdForUser($user);

        if ($tenantId !== null) {
            session()->put('current_tenant_id', (string) $tenantId);
        }
    }
}
