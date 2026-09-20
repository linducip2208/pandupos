<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    public function accounts()
    {
        $this->authorize('viewAny', Account::class);

        return response()->json(['data' => Account::query()->where('is_active', true)->orderBy('code')->get()]);
    }

    public function trialBalance(Request $request, AccountingService $accounting)
    {
        $this->authorize('viewAny', Account::class);
        $asOf = $request->input('as_of', now()->toDateString());

        return response()->json(['data' => $accounting->trialBalance(TenantContext::idOrFail(), $asOf)]);
    }

    public function profitLoss(Request $request, AccountingService $accounting)
    {
        $this->authorize('viewAny', Account::class);
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        return response()->json(['data' => $accounting->profitLoss(TenantContext::idOrFail(), $from, $to)]);
    }

    public function storeJournal(Request $request, AccountingService $accounting)
    {
        $this->authorize('create', Account::class);
        $data = $request->validate([
            'entry_date' => 'required|date', 'description' => 'nullable|string|max:255',
            'lines' => 'required|array|min:2',
            'lines.*.account_code' => 'required|string',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'post' => 'nullable|boolean',
        ]);
        $entry = $accounting->createDraft(
            TenantContext::idOrFail(), $data['entry_date'], $data['description'] ?? null,
            $data['lines'], null, null, $request->user()->id
        );
        if ($request->boolean('post')) {
            $entry = $accounting->post($entry, $request->user()->id);
        }

        return response()->json(['data' => $entry->load('lines')], 201);
    }

    public function postJournal(Request $request, AccountingService $accounting, int $entry)
    {
        $model = JournalEntry::query()->findOrFail($entry);
        $this->authorize('post', $model);

        return response()->json(['data' => $accounting->post($model, $request->user()->id)->load('lines')]);
    }

    public function voidJournal(Request $request, AccountingService $accounting, int $entry)
    {
        $model = JournalEntry::query()->findOrFail($entry);
        $this->authorize('void', $model);
        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        return response()->json(['data' => $accounting->void($model, $request->user()->id, $data['reason'] ?? null)->load('lines')]);
    }
}
