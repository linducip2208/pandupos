<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves tenant from authenticated user's current_tenant_id,
 * X-Tenant-ID header, or `tenant` route parameter. Sets TenantContext.
 * NEVER trusts tenant_id from request body. Platform admin bypass is explicit.
 * Blocks suspended/cancelled/archived tenants (except platform admins).
 */
class TenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        TenantContext::clear();

        // SECURITY: ignore tenant_id from body/query — server context only.
        $tenantId = null;

        if ($request->user()) {
            $tenantId = $request->user()->current_tenant_id
                ?? $request->header('X-Tenant-ID')
                ?? $request->route('tenant');
        } else {
            // Guests: only explicit route binding, never header — prevents unauthenticated context spoofing.
            $tenantId = $request->route('tenant');
        }

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                abort(404, 'Tenant not found.');
            }

            if (! in_array($tenant->status, Tenant::STATUSES, true)) {
                abort(403, 'Invalid tenant status.');
            }

            // Archived tenants are never accessible, even to members (platform admin may inspect via platform routes without tenant scope).
            if ($tenant->status === 'archived' && ! ($request->user()?->is_platform_admin)) {
                abort(403, 'Tenant is [archived].');
            }

            // Membership check for non-platform users (explicit bypass only for platform admins).
            if ($request->user() && ! $request->user()->is_platform_admin) {
                $isMember = $request->user()->memberships()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->exists();
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
