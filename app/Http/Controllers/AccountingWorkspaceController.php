<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AccountingWorkspaceController extends Controller
{
    public function index(AccountingService $accounting)
    {
        $this->authorize('viewAny', Account::class);
        $tenantId = TenantContext::idOrFail();
        $accounting->ensureDefaultChart($tenantId);
        $asOf = now()->toDateString();

        return view('accounting.index', [
            'accounts' => Account::query()->orderBy('code')->get(),
            'trial' => $accounting->trialBalance($tenantId, $asOf),
            'asOf' => $asOf,
        ]);
    }

    public function storeAccount(Request $request, AccountingService $accounting)
    {
        $this->authorize('create', Account::class);
        $data = $request->validate([
            'code' => 'required|string|max:32', 'name' => 'required|string|max:128',
            'type' => 'required|in:asset,liability,equity,income,expense',
            'parent_id' => 'nullable|integer', 'is_cash' => 'nullable|boolean',
        ]);
        $accounting->createAccount(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Akun '.$data['code'].' dibuat.');
    }

    public function journals()
    {
        $this->authorize('viewAny', Account::class);
        $entries = JournalEntry::query()->with(['lines.account.variant'])->orderByDesc('id')->limit(100)->get();
        $accounts = Account::query()->where('is_active', true)->orderBy('code')->get();
        $variants = \App\Models\ProductVariant::query()->with('product')->where('tenant_id', TenantContext::idOrFail())->where('is_active', true)->orderBy('id')->get();

        return view('accounting.journals', ['entries' => $entries, 'accounts' => $accounts, 'variants' => $variants]);
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
            'lines.*.variant_id' => 'nullable|integer',
        ]);
        $entry = $accounting->createDraft(
            TenantContext::idOrFail(), $data['entry_date'], $data['description'] ?? null,
            $data['lines'], null, null, $request->user()->id
        );

        return back()->with('status', 'Jurnal '.$entry->entry_no.' tersimpan sebagai draft.');
    }

    public function postEntry(Request $request, AccountingService $accounting, int $entry)
    {
        $model = JournalEntry::query()->findOrFail($entry);
        $this->authorize('post', $model);
        $accounting->post($model, $request->user()->id);

        return back()->with('status', 'Jurnal '.$model->entry_no.' diposting.');
    }

    public function voidEntry(Request $request, AccountingService $accounting, int $entry)
    {
        $model = JournalEntry::query()->findOrFail($entry);
        $this->authorize('void', $model);
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $accounting->void($model, $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', 'Jurnal '.$model->entry_no.' dibatalkan via jurnal reversal.');
    }

    public function reports(Request $request, AccountingService $accounting)
    {
        $this->authorize('viewAny', Account::class);
        $tenantId = TenantContext::idOrFail();
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        return view('accounting.reports', [
            'from' => $from, 'to' => $to,
            'trial' => $accounting->trialBalance($tenantId, $to),
            'pnl' => $accounting->profitLoss($tenantId, $from, $to),
            'balance' => $accounting->balanceSheet($tenantId, $to),
            'cash' => $accounting->cashFlow($tenantId, $from, $to),
            'tax' => $accounting->taxSummary($tenantId, $from, $to),
            'receivables' => $accounting->receivables($tenantId),
            'payables' => $accounting->payables($tenantId),
            'cogs' => $accounting->cogsDetail($tenantId, $from, $to),
        ]);
    }

    public function periods(AccountingService $accounting)
    {
        $this->authorize('viewAny', Account::class);
        $periods = AccountingPeriod::query()->orderByDesc('starts_on')->get();

        return view('accounting.periods', ['periods' => $periods]);
    }

    public function closePeriod(Request $request, AccountingService $accounting)
    {
        $this->authorize('closePeriod', Account::class);
        $data = $request->validate(['starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on']);
        $period = $accounting->closePeriod(TenantContext::idOrFail(), $data['starts_on'], $data['ends_on'], $request->user()->id);

        return back()->with('status', 'Periode '.$period->starts_on->toDateString().' s.d. '.$period->ends_on->toDateString().' ditutup.');
    }
}
