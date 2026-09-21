<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cheque;
use App\Services\ChequeService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ChequeController extends Controller
{
    public function index(ChequeService $cheques)
    {
        $this->authorize('viewAny', Cheque::class);

        return response()->json([
            'data' => Cheque::query()->orderByDesc('id')->limit(200)->get(),
            'reconciliation' => $cheques->reconcile(TenantContext::idOrFail(), now()->startOfMonth()->toDateString(), now()->toDateString()),
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

        return response()->json(['data' => $cheque], 201);
    }

    public function transition(Request $request, ChequeService $cheques, int $cheque)
    {
        $model = Cheque::query()->findOrFail($cheque);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'action' => 'required|in:deposit,clear,bounce,cancel',
            'reason' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:128', 'deposited_on' => 'nullable|date',
        ]);
        $result = match ($data['action']) {
            'deposit' => $cheques->deposit(TenantContext::idOrFail(), [$model->id], (string) ($data['bank_name'] ?? $model->bank_name), $data['deposited_on'] ?? now()->toDateString(), $request->user()->id),
            'clear' => $cheques->clear($model, $request->user()->id),
            'bounce' => $cheques->bounce($model, (string) ($data['reason'] ?? ''), $request->user()->id),
            'cancel' => $cheques->cancel($model, $request->user()->id),
        };

        return response()->json(['data' => $result]);
    }
}
