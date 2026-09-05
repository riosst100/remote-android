<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a device via its Sanctum bearer token against the
 * dedicated "device" guard, keeping device credentials fully separate
 * from admin-user (web/Sanctum "users") authentication.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('device')->check()) {
            throw new AuthenticationException('Invalid or missing device token.');
        }

        $request->setUserResolver(fn () => auth('device')->user());

        return $next($request);
    }
}
