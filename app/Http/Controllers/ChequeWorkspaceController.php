<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Services\ChequeService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ChequeWorkspaceController extends Controller
{
    public function index(ChequeService $cheques)
    {
        $this->authorize('viewAny', Cheque::class);
        $tenantId = TenantContext::idOrFail();

        return view('cheque.index', [
            'cheques' => Cheque::query()->with('contact')->orderByDesc('id')->limit(200)->get(),
            'recon' => $cheques->reconcile($tenantId, now()->startOfMonth()->toDateString(), now()->toDateString()),
        ]);
    }

    public function store(Request $request, ChequeService $cheques)
    {
        $this->authorize('create', Cheque::class);
        $data = $request->validate([
            'type' => 'required|in:receipt,payment', 'contact_id' => 'nullable|integer',
            'bank_name' => 'required|string|max:128', 'cheque_no' => 'required|string|max:64',
            'amount' => 'required|numeric|gt:0', 'issue_date' => 'required|date', 'due_date' => 'required|date',
        ]);
        $cheque = $data['type'] === 'receipt'
            ? $cheques->receive(TenantContext::idOrFail(), $data, $request->user()->id)
            : $cheques->issue(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Cek '.$cheque->cheque_no.' dicatat.');
    }

    public function deposit(Request $request, ChequeService $cheques)
    {
        $this->authorize('create', Cheque::class);
        $data = $request->validate([
            'cheque_ids_raw' => 'required|string',
            'bank_name' => 'required|string|max:128', 'deposited_on' => 'required|date',
        ]);
        $ids = array_values(array_filter(array_map(fn ($v) => (int) trim($v), explode(',', $data['cheque_ids_raw'])), fn ($v) => $v > 0));
        $deposit = $cheques->deposit(TenantContext::idOrFail(), $ids, $data['bank_name'], $data['deposited_on'], $request->user()->id);

        return back()->with('status', 'Setoran '.$deposit->number.' dibuat.');
    }

    public function transition(Request $request, ChequeService $cheques, int $cheque)
    {
        $model = Cheque::query()->findOrFail($cheque);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'action' => 'required|in:clear,bounce,cancel',
            'reason' => 'nullable|string|max:255',
        ]);
        match ($data['action']) {
            'clear' => $cheques->clear($model, $request->user()->id),
            'bounce' => $cheques->bounce($model, (string) ($data['reason'] ?? ''), $request->user()->id),
            'cancel' => $cheques->cancel($model, $request->user()->id),
        };

        return back()->with('status', 'Status cek diperbarui.');
    }
}
