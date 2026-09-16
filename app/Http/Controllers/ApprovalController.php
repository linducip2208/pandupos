<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\SystemSetting;
use App\Services\ApprovalService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function index(Request $request)
    {
        $tenant = TenantContext::get() ?? $request->user()->currentTenant;

        return view('approvals.index', [
            'approvals' => ApprovalRequest::where('tenant_id', $tenant->id)->latest()->paginate(25),
            'threshold' => (float) SystemSetting::scalar($tenant->id, 'approval_threshold', 0),
        ]);
    }

    public function setting(Request $request)
    {
        $this->authorizeManager($request);
        $data = $request->validate(['approval_threshold' => ['required', 'numeric', 'min:0']]);
        $tenantId = TenantContext::id();
        SystemSetting::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => 'approval_threshold'],
            ['value' => (float) $data['approval_threshold']]
        );

        return back()->with('status', 'Batas approval diperbarui.');
    }

    public function approve(Request $request, ApprovalRequest $approval, ApprovalService $service)
    {
        $this->authorizeManager($request);
        abort_unless($approval->tenant_id === TenantContext::id(), 404);
        $service->approve($approval, $request->user()->id);

        return back()->with('status', 'Transaksi disetujui dan diproses.');
    }

    public function reject(Request $request, ApprovalRequest $approval, ApprovalService $service)
    {
        $this->authorizeManager($request);
        abort_unless($approval->tenant_id === TenantContext::id(), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->reject($approval, $request->user()->id, $data['reason']);

        return back()->with('status', 'Transaksi ditolak.');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()->is_platform_admin || $request->user()->hasAnyRole(['tenant-owner', 'tenant-admin', 'manager']) || $request->user()->can('transactions.approve'), 403);
    }
}
