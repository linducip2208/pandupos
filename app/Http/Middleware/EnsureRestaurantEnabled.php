<?php

namespace App\Http\Middleware;

use App\Services\RestaurantService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend enforcement for the optional Restaurant business feature.
 * Tenant-aware: reads the tenant's own `features.restaurant` setting —
 * never a global boolean. Hiding menus alone is not sufficient; every
 * restaurant route and API sits behind this middleware plus policies.
 */
class EnsureRestaurantEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = TenantContext::id() ?? $request->user()?->current_tenant_id;

        if (! $tenantId || ! app(RestaurantService::class)->isEnabled((int) $tenantId)) {
            abort(403, 'Restaurant features are disabled for this tenant.');
        }

        return $next($request);
    }
}
