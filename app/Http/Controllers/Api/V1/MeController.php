<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'data' => [
                'user' => $request->user()->only(['id', 'name', 'email', 'current_tenant_id', 'is_platform_admin']),
                'tenant_id' => TenantContext::id(),
            ],
            'request_id' => $request->header('X-Request-ID', (string) \Str::uuid()),
        ]);
    }

    public function tenant()
    {
        return response()->json(['data' => TenantContext::get()]);
    }
}
