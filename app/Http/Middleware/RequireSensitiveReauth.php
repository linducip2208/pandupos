<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require a fresh password confirmation before sensitive platform mutations.
 *
 * - Impersonated sessions may never perform sensitive platform mutations,
 *   even with a fresh confirmation (the impersonation flag is authoritative).
 * - Otherwise the session must carry `sensitive_auth_at` within
 *   `auth.sensitive_reauth_timeout` seconds.
 */
class RequireSensitiveReauth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('impersonating')) {
            abort(403, 'Sensitive actions are disabled while impersonating.');
        }

        $confirmedAt = (int) $request->session()->get('sensitive_auth_at', 0);
        $timeout = (int) config('auth.sensitive_reauth_timeout', 600);

        if ($confirmedAt <= 0 || (time() - $confirmedAt) > $timeout) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sensitive re-authentication required.',
                    'confirm_url' => route('reauth.confirm'),
                ], 423);
            }

            return redirect()->guest(route('reauth.confirm'));
        }

        return $next($request);
    }
}
