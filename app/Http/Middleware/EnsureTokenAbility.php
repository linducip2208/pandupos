<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Enforces Sanctum token abilities on token-authenticated API calls.
 * Session (web) authentication bypasses: interactive users are governed by
 * RBAC permissions instead. Tokens carrying '*' (legacy full access) pass.
 */
class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token !== null && ! $token->can($ability)) {
            abort(403, 'Token lacks the required scope.');
        }

        return $next($request);
    }
}
