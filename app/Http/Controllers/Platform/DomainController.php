<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\TenantDomain;
use App\Services\TenantDomainService;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function store(Request $request, TenantDomainService $domains)
    {
        $this->authorizePlatform($request, 'platform.white_label.manage');
        $data = $request->validate(['tenant_id' => 'required|exists:tenants,id', 'domain' => 'required|string|max:255']);
        $domain = $domains->createForTenant((int) $data['tenant_id'], $data['domain'], (int) $request->user()->id);

        return redirect()->route('platform.tenants.show', $request->input('tenant_id'))
            ->with('status', "Domain {$domain->domain} pending. Verification token: {$domain->verification_token}");
    }

    public function verify(string $token, TenantDomainService $domains)
    {
        $domain = $domains->verifyByToken($token);

        return $domain
            ? view('platform.domains.verified', ['domain' => $domain])
            : abort(404, 'Invalid verification token.');
    }

    public function regenerate(Request $request, int $domainId, TenantDomainService $domains)
    {
        $this->authorizePlatform($request, 'platform.white_label.manage');
        $domain = TenantDomain::withoutGlobalScopes()->findOrFail($domainId);
        $token = $domains->rotateToken($domain);

        return redirect()->route('platform.tenants.show', $domain->tenant_id)
            ->with('status', "New verification token: {$token}");
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
