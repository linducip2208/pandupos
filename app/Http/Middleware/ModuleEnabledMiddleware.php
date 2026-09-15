<?php

namespace App\Http\Middleware;

use App\Services\ModuleRegistry;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ModuleEnabledMiddleware
{
    public function handle(Request $request, Closure $next, string $slug)
    {
        $tenantId = TenantContext::id() ?? $request->user()?->current_tenant_id;

        if (! $tenantId || ! app(ModuleRegistry::class)->isEnabled((int) $tenantId, $slug)) {
            abort(403, "Module [{$slug}] is disabled for this tenant.");
        }

        return $next($request);
    }
}
