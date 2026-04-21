<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! session()->get('is_admin')) {
            if ($request->expectsJson()) {
                abort(403);
            }

            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
