<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EntitlementMiddleware
{
    public function handle(Request $request, Closure $next, string $entitlement)
    {
        $tenantId = TenantContext::id() ?? $request->user()?->current_tenant_id;

        if (! $tenantId) {
            abort(403, 'Tenant context required.');
        }

        if (! app(EntitlementService::class)->allowed($tenantId, $entitlement)) {
            abort(403, "Entitlement [{$entitlement}] is not available on your plan.");
        }

        return $next($request);
    }
}
