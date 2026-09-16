<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.audit.view');
        $logs = AuditLog::withoutGlobalScopes()->orderByDesc('id')->paginate(25);

        return view('platform.audit.index', compact('logs'));
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
