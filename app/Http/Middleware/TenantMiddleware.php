<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves tenant from: authenticated user's current_tenant_id,
 * X-Tenant-ID header, or `tenant` route parameter. Sets TenantContext.
 * Blocks suspended/cancelled/archived tenants (except platform admins).
 */
class TenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        TenantContext::clear();

        $tenantId = null;

        if ($request->user()) {
            $tenantId = $request->user()->current_tenant_id
                ?? $request->header('X-Tenant-ID')
                ?? $request->route('tenant');
        } else {
            $tenantId = $request->header('X-Tenant-ID') ?? $request->route('tenant');
        }

        if ($tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);

            if (! $tenant) {
                abort(404, 'Tenant not found.');
            }

            // Membership check for non-platform users.
            if ($request->user() && ! $request->user()->is_platform_admin) {
                $isMember = $request->user()->memberships()->where('tenant_id', $tenant->getKey())->exists();
                if (! $isMember) {
                    abort(403, 'Not a member of this tenant.');
                }
            }

            if ($tenant->isBlocked() && ! ($request->user()?->is_platform_admin)) {
                abort(403, "Tenant is [{$tenant->status}].");
            }

            TenantContext::set($tenant);
        }

        return $next($request);
    }
}
