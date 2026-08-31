<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $userRole = $request->user()?->role?->name;

        if (! $userRole || ! in_array($userRole, $roles, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }

        return $next($request);
    }
}
