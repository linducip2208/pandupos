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
            'purchaseManagerThreshold' => (float) SystemSetting::scalar($tenant->id, 'purchase_manager_approval_threshold', 0),
            'purchaseOwnerThreshold' => (float) SystemSetting::scalar($tenant->id, 'purchase_owner_approval_threshold', 0),
        ]);
    }

    public function setting(Request $request)
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'approval_threshold' => ['required', 'numeric', 'min:0'],
            'purchase_manager_approval_threshold' => ['required', 'numeric', 'min:0'],
            'purchase_owner_approval_threshold' => ['required', 'numeric', 'min:0'],
        ]);
        if ($data['purchase_manager_approval_threshold'] > 0 && $data['purchase_owner_approval_threshold'] > 0
            && $data['purchase_owner_approval_threshold'] < $data['purchase_manager_approval_threshold']) {
            return back()->withErrors(['purchase_owner_approval_threshold' => 'Batas owner harus sama atau lebih besar dari batas manager.']);
        }
        $tenantId = TenantContext::id();
        foreach ($data as $key => $value) {
            SystemSetting::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key], ['value' => (float) $value]
            );
        }

        return back()->with('status', 'Batas approval diperbarui.');
    }

    public function approve(Request $request, ApprovalRequest $approval, ApprovalService $service)
    {
        $this->authorizeManager($request);
        abort_unless($approval->tenant_id === TenantContext::id(), 404);
        $this->authorizeApprovalLevel($request, $approval);
        $service->approve($approval, $request->user()->id);

        return back()->with('status', 'Transaksi disetujui dan diproses.');
    }

    public function reject(Request $request, ApprovalRequest $approval, ApprovalService $service)
    {
        $this->authorizeManager($request);
        abort_unless($approval->tenant_id === TenantContext::id(), 404);
        $this->authorizeApprovalLevel($request, $approval);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->reject($approval, $request->user()->id, $data['reason']);

        return back()->with('status', 'Transaksi ditolak.');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()->is_platform_admin || $request->user()->hasAnyRole(['tenant-owner', 'tenant-admin', 'manager']) || $request->user()->can('transactions.approve'), 403);
    }

    private function authorizeApprovalLevel(Request $request, ApprovalRequest $approval): void
    {
        $required = $approval->metadata['required_level'] ?? 'manager';
        if ($required === 'owner') {
            abort_unless($request->user()->is_platform_admin || $request->user()->hasRole('tenant-owner'), 403);
        }
    }
}
