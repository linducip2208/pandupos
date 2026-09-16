<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function index()
    {
        return response()->json(['data' => DB::table('webhook_endpoints')->where('tenant_id', TenantContext::id())->get()]);
    }
}
