<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFromGoSession
{
    /**
     * Authenticate user from Go session token in Redis if secsys_session cookie or bearer token exists.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionToken = $request->cookie('secsys_session')
            ?? $request->bearerToken()
            ?? $request->header('X-Session-Token');

        if (! $sessionToken && $cookieHeader = $request->header('Cookie')) {
            if (preg_match('/(?:^|;\s*)secsys_session=([^;]+)/', $cookieHeader, $matches)) {
                $sessionToken = urldecode($matches[1]);
            }
        }

        if ($sessionToken) {
            try {
                $client = Redis::connection()->client();
                $oldPrefix = $client->getOption(\Redis::OPT_PREFIX);
                $client->setOption(\Redis::OPT_PREFIX, '');
                $userId = $client->get("session:{$sessionToken}");
                $client->setOption(\Redis::OPT_PREFIX, $oldPrefix);

                if ($userId) {
                    $user = \App\Models\User::with('role')->find($userId);
                    if ($user && $user->status === 'active') {
                        Auth::setUser($user);
                        $request->setUserResolver(fn() => $user);
                        return $next($request);
                    }
                }
            } catch (\Throwable $e) {
                // Fallback
            }
        }

        // Fallback to Sanctum if legacy bearer token or Laravel session exists
        if (Auth::guard('sanctum')->check()) {
            $user = Auth::guard('sanctum')->user();
            Auth::setUser($user);
            $request->setUserResolver(fn() => $user);
            return $next($request);
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
