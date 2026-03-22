<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!session()->get('is_admin')) {
            if ($request->expectsJson()) {
                abort(401);
            }

            // Do not leak the hidden admin login route via redirects.
            throw new NotFoundHttpException();
        }

        return $next($request);
    }
}
