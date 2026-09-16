<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;

class SuperadminTenantController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->is_platform_admin, 403);

        return response()->json(
            Tenant::with('activeSubscription.plan')->orderByDesc('id')->paginate(15)
        );
    }

    public function suspend(Tenant $tenant, Request $request)
    {
        abort_unless($request->user()->is_platform_admin, 403);
        $data = $request->validate(['reason' => 'required|string']);

        $tenant->update(['status' => 'suspended']);
        app(\App\Services\AuditService::class)->log(
            $tenant->id, $request->user()->id, 'tenant.suspended',
            Tenant::class, $tenant->id, null, ['reason' => $data['reason']]
        );

        return response()->json($tenant);
    }
}
