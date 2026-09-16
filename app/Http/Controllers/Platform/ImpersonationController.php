<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function start(Request $request, Tenant $tenant)
    {
        $this->authorizePlatform($request, 'platform.tenants.impersonate');
        $data = $request->validate(['target_user_id' => 'nullable|exists:users,id']);

        $session = ImpersonationSession::create([
            'platform_user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'target_user_id' => $data['target_user_id'] ?? null,
            'started_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        $target = isset($data['target_user_id'])
            ? User::whereHas('memberships', fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $data['target_user_id']))->find($data['target_user_id'])
            : User::whereHas('memberships', fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenant->id))->first();

        abort_unless($target, 422, 'Target user is not a member of this tenant.');
        $target->forceFill(['current_tenant_id' => $tenant->id])->save();
        $request->session()->put('impersonating', ['session_id' => $session->id, 'tenant_id' => $tenant->id, 'tenant_name' => $tenant->name]);

        app(AuditService::class)->log($tenant->id, $request->user()->id, 'impersonation.started', Tenant::class, $tenant->id, null, ['target' => $target->id]);

        // Login as target but keep impersonation flag; dangerous platform changes blocked via middleware/session flag.
        auth()->login($target);

        return redirect('/dashboard')->with('status', "Impersonating: {$tenant->name}");
    }

    public function stop(Request $request)
    {
        $flag = $request->session()->get('impersonating');
        if ($flag) {
            ImpersonationSession::where('id', $flag['session_id'])->update(['ended_at' => now()]);
            app(AuditService::class)->log($flag['tenant_id'], $request->user()->id, 'impersonation.ended', null, null, null, []);
        }
        $request->session()->forget('impersonating');

        return redirect('/platform/dashboard')->with('status', 'Impersonation ended.');
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
