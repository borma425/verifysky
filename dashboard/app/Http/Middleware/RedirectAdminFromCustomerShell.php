<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectAdminFromCustomerShell
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) session('is_admin') && ! $request->routeIs('admin.*')) {
            return new RedirectResponse(route('admin.overview'));
        }

        return $next($request);
    }
}
