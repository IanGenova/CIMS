<?php

namespace App\Http\Middleware;

use Closure;

class IsActiveToken
{
    /**
     * Validate the current Laravel Passport access token without relying on
     * serialized model internals.
     */
    public function handle($request, Closure $next)
    {
        $user = $request->user('api');
        $token = $user ? $user->token() : null;

        if (!$user || !$token || $token->revoked || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json([
                'message' => 'Your access token is invalid or has expired. Please log in again.',
            ], 401);
        }

        return $next($request);
    }
}
