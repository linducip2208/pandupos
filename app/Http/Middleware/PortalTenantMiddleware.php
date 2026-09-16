<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class PortalTenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        TenantContext::clear();
        $customer = auth('customer')->user();

        abort_unless($customer, 401);

        $tenant = Tenant::find($customer->tenant_id);
        abort_unless($tenant && ! $tenant->isBlocked(), 403, 'Portal toko sedang tidak tersedia.');

        TenantContext::set($tenant);

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
