<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Register;
use App\Services\AuditService;
use App\Services\RegisterSessionService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegisterWorkspaceController extends Controller
{
    public function index(Request $request, RegisterSessionService $sessions): View
    {
        $this->authorizeView($request);
        $tenantId = TenantContext::idOrFail();
        $registers = Register::query()->with('branch')->orderBy('name')->get();
        $cashSessions = CashSession::query()->with(['register.branch', 'openedBy', 'closedBy', 'movements.creator'])
            ->latest('id')->limit(40)->get();

        return view('registers.index', [
            'registers' => $registers,
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(),
            'sessions' => $cashSessions,
            'expectedAmounts' => $cashSessions->where('status', 'open')->mapWithKeys(fn (CashSession $session) => [$session->id => $sessions->expectedAmount($session)]),
            'denominations' => RegisterSessionService::DENOMINATIONS,
            'canManage' => $request->user()->can('register.manage'),
            'canOpen' => $request->user()->can('register.open'),
            'canClose' => $request->user()->can('register.close'),
            'tenantId' => $tenantId,
        ]);
    }

    public function storeRegister(Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()->can('register.manage'), 403);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
        ]);
        $register = Register::create($data + ['tenant_id' => $tenantId, 'is_active' => true]);
        $audit->log($tenantId, $request->user()->id, 'register.created', Register::class, $register->id, null, $register->toArray());

        return back()->with('status', 'Register aktif dibuat.');
    }

    public function open(Request $request, Register $register, RegisterSessionService $sessions): RedirectResponse
    {
        abort_unless($request->user()->can('register.open'), 403);
        $this->assertTenant($register->tenant_id);
        $data = $request->validate(['opening_amount' => ['required', 'numeric', 'min:0']]);
        $sessions->open($register->tenant_id, $register->id, $request->user()->id, (float) $data['opening_amount']);

        return back()->with('status', 'Sesi register dibuka.');
    }

    public function movement(Request $request, CashSession $session, RegisterSessionService $sessions): RedirectResponse
    {
        abort_unless($request->user()->can('register.open'), 403);
        $this->assertTenant($session->tenant_id);
        $data = $request->validate([
            'type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $sessions->movement($session, $request->user()->id, $data['type'], (float) $data['amount'], $data['reason']);

        return back()->with('status', 'Mutasi kas tercatat.');
    }

    public function close(Request $request, CashSession $session, RegisterSessionService $sessions): RedirectResponse
    {
        abort_unless($request->user()->can('register.close'), 403);
        $this->assertTenant($session->tenant_id);
        $data = $request->validate([
            'actual_amount' => ['required', 'numeric', 'min:0'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $sessions->close($session, $request->user()->id, (float) $data['actual_amount'], $data['denominations'] ?? [], $data['notes'] ?? null, $request->user()->can('register.manage'));

        return back()->with('status', 'Sesi register ditutup dan selisih kas dicatat.');
    }

    public function deactivate(Request $request, Register $register, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()->can('register.manage'), 403);
        $this->assertTenant($register->tenant_id);
        abort_if(CashSession::query()->where('register_id', $register->id)->where('status', 'open')->exists(), 422, 'Tutup sesi register sebelum menonaktifkan register.');
        $before = $register->toArray();
        $register->update(['is_active' => false]);
        $audit->log($register->tenant_id, $request->user()->id, 'register.deactivated', Register::class, $register->id, $before, $register->fresh()->toArray());

        return back()->with('status', 'Register dinonaktifkan; riwayat tetap tersimpan.');
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('register.manage') || $request->user()->can('register.open') || $request->user()->can('register.close'), 403);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === TenantContext::idOrFail(), 404);
    }
}
