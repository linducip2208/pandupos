<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\SalePayment;
use App\Models\SalesInvoice;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Double-entry accounting: chart of accounts, balanced journals with
 * draft/posted/void lifecycle (void via reversal, never delete), period
 * closing, and financial reports. All amounts decimal; every mutation
 * transactional and audited. Auto-posting from sales/purchases runs inside
 * the originating transaction so books can never diverge from documents.
 */
final class AccountingService
{
    public function __construct(private AuditService $audit) {}

    /** @return array<int, array{code:string,name:string,type:string,is_cash:bool}> */
    public static function defaultChart(): array
    {
        return [
            ['code' => '1100', 'name' => 'Kas', 'type' => 'asset', 'is_cash' => true],
            ['code' => '1200', 'name' => 'Bank', 'type' => 'asset', 'is_cash' => true],
            ['code' => '1300', 'name' => 'Piutang Usaha', 'type' => 'asset', 'is_cash' => false],
            ['code' => '1400', 'name' => 'Persediaan Barang', 'type' => 'asset', 'is_cash' => false],
            ['code' => '1500', 'name' => 'Pajak Masukan', 'type' => 'asset', 'is_cash' => false],
            ['code' => '2100', 'name' => 'Hutang Usaha', 'type' => 'liability', 'is_cash' => false],
            ['code' => '2150', 'name' => 'Hutang Gaji', 'type' => 'liability', 'is_cash' => false],
            ['code' => '2160', 'name' => 'Hutang Potongan Gaji', 'type' => 'liability', 'is_cash' => false],
            ['code' => '2200', 'name' => 'Pajak Keluaran', 'type' => 'liability', 'is_cash' => false],
            ['code' => '3100', 'name' => 'Modal Pemilik', 'type' => 'equity', 'is_cash' => false],
            ['code' => '4100', 'name' => 'Pendapatan Penjualan', 'type' => 'income', 'is_cash' => false],
            ['code' => '5100', 'name' => 'Harga Pokok Penjualan', 'type' => 'expense', 'is_cash' => false],
            ['code' => '5200', 'name' => 'Beban Operasional', 'type' => 'expense', 'is_cash' => false],
            ['code' => '5210', 'name' => 'Beban Gaji', 'type' => 'expense', 'is_cash' => false],
        ];
    }

    public function ensureDefaultChart(int $tenantId): void
    {
        foreach (self::defaultChart() as $row) {
            Account::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $row['code']],
                ['name' => $row['name'], 'type' => $row['type'], 'is_system' => true, 'is_cash' => $row['is_cash'], 'is_active' => true]
            );
        }
    }

    public function createAccount(int $tenantId, array $data, ?int $actorId = null): Account
    {
        abort_unless(in_array($data['type'] ?? null, Account::TYPES, true), 422, 'Invalid account type.');
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        abort_if($code === '', 422, 'Account code is required.');
        abort_if(Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists(), 422, 'Account code already exists.');
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($parentId) {
            $parent = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($parentId);
            abort_if($parent->type !== $data['type'], 422, 'Child account must share the parent type.');
        }

        $account = Account::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'code' => $code, 'name' => trim((string) ($data['name'] ?? '')),
            'type' => $data['type'], 'parent_id' => $parentId,
            'is_system' => false, 'is_cash' => (bool) ($data['is_cash'] ?? false), 'is_active' => true,
        ]);
        $this->audit->log($tenantId, $actorId, 'accounting.account.created', Account::class, $account->id, null, $account->toArray());

        return $account;
    }

    /**
     * @param  array<int, array{account_code:string,debit:float,credit:float,description?:string}>  $lines
     */
    public function createDraft(int $tenantId, string $date, ?string $description, array $lines, ?string $sourceType = null, ?int $sourceId = null, ?int $actorId = null): JournalEntry
    {
        abort_if(count($lines) < 2, 422, 'A journal needs at least two lines.');
        $this->assertPeriodOpen($tenantId, $date);

        return DB::transaction(function () use ($tenantId, $date, $description, $lines, $sourceType, $sourceId, $actorId) {
            $entry = JournalEntry::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'entry_no' => $this->nextEntryNo(),
                'entry_date' => $date, 'description' => $description,
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'status' => JournalEntry::DRAFT, 'created_by' => $actorId,
            ]);
            $debit = 0.0;
            $credit = 0.0;
            foreach ($lines as $line) {
                $account = $this->activeAccount($tenantId, $line['account_code']);
                $d = round((float) ($line['debit'] ?? 0), 2);
                $c = round((float) ($line['credit'] ?? 0), 2);
                abort_if($d < 0 || $c < 0 || ($d > 0 && $c > 0), 422, 'Each line must carry a debit or a credit amount, not both.');
                $debit += $d;
                $credit += $c;
                $entry->lines()->create([
                    'tenant_id' => $tenantId, 'account_id' => $account->id,
                    'debit' => $d, 'credit' => $c, 'description' => $line['description'] ?? null,
                ]);
            }
            abort_if(round($debit, 2) <= 0 || abs(round($debit, 2) - round($credit, 2)) > 0.009, 422, 'Journal must balance: total debit must equal total credit.');
            $this->audit->log($tenantId, $actorId, 'accounting.journal.drafted', JournalEntry::class, $entry->id, null, ['entry_no' => $entry->entry_no]);

            return $entry->load('lines');
        });
    }

    public function post(JournalEntry $entry, ?int $actorId = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actorId) {
            $locked = JournalEntry::withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->id);
            abort_if($locked->status !== JournalEntry::DRAFT, 422, 'Only draft journals can be posted.');
            $locked->load('lines.account');
            abort_if(! $locked->isBalanced(), 422, 'Unbalanced journal cannot be posted.');
            foreach ($locked->lines as $line) {
                abort_if($line->account->tenant_id !== $locked->tenant_id || ! $line->account->is_active, 422, 'Journal references an inactive or foreign account.');
            }
            $this->assertPeriodOpen($locked->tenant_id, $locked->entry_date->toDateString());
            $before = $locked->toArray();
            $locked->update(['status' => JournalEntry::POSTED, 'posted_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'accounting.journal.posted', JournalEntry::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh('lines');
        });
    }

    /** Void via reversal entry: the voided original stays in the ledger (status
     * is a lifecycle flag only) so original + reversal always net to zero and
     * history is never deleted or edited. */
    public function void(JournalEntry $entry, ?int $actorId = null, ?string $reason = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actorId, $reason) {
            $locked = JournalEntry::withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->id);
            abort_if($locked->status !== JournalEntry::POSTED, 422, 'Only posted journals can be voided.');
            $today = now()->toDateString();
            $this->assertPeriodOpen($locked->tenant_id, $today);
            $locked->load('lines');
            $reversal = JournalEntry::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id, 'entry_no' => $this->nextEntryNo(),
                'entry_date' => $today,
                'description' => 'Reversal of '.$locked->entry_no.($reason ? ': '.$reason : ''),
                'source_type' => $locked->source_type, 'source_id' => $locked->source_id,
                'status' => JournalEntry::POSTED, 'posted_at' => now(), 'created_by' => $actorId,
            ]);
            foreach ($locked->lines as $line) {
                $reversal->lines()->create([
                    'tenant_id' => $locked->tenant_id, 'account_id' => $line->account_id,
                    'debit' => $line->credit, 'credit' => $line->debit,
                    'description' => 'Reversal: '.($line->description ?? $locked->entry_no),
                ]);
            }
            $before = $locked->toArray();
            $locked->update(['status' => JournalEntry::VOID, 'voided_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'accounting.journal.voided', JournalEntry::class, $locked->id, $before, [
                'status' => JournalEntry::VOID, 'reversal_entry_no' => $reversal->entry_no, 'reason' => $reason,
            ]);

            return $locked->fresh('lines');
        });
    }

    // ---- Source document posting (idempotent by source reference) ----

    public function postSalesInvoice(SalesInvoice $invoice, ?int $actorId = null): ?JournalEntry
    {
        $tenantId = $invoice->tenant_id;
        $existing = $this->existingPosted($tenantId, SalesInvoice::class, $invoice->id);
        if ($existing) {
            return $existing;
        }
        $this->ensureDefaultChart($tenantId);
        $revenue = round((float) $invoice->subtotal - (float) $invoice->discount, 2);
        $tax = round((float) $invoice->tax, 2);
        $lines = [['account_code' => '1300', 'debit' => (float) $invoice->total, 'credit' => 0]];
        if ($revenue > 0) {
            $lines[] = ['account_code' => '4100', 'debit' => 0, 'credit' => $revenue];
        }
        if ($tax > 0) {
            $lines[] = ['account_code' => '2200', 'debit' => 0, 'credit' => $tax];
        }
        $entry = $this->createDraft($tenantId, $invoice->created_at->toDateString(), 'Penjualan '.$invoice->invoice_no, $lines, SalesInvoice::class, $invoice->id, $actorId);

        return $this->post($entry, $actorId);
    }

    public function postSupplierInvoice(SupplierInvoice $invoice, ?int $actorId = null): ?JournalEntry
    {
        $tenantId = $invoice->tenant_id;
        $existing = $this->existingPosted($tenantId, SupplierInvoice::class, $invoice->id);
        if ($existing) {
            return $existing;
        }
        $this->ensureDefaultChart($tenantId);
        $goods = round((float) $invoice->subtotal - (float) $invoice->discount + (float) $invoice->shipping, 2);
        $tax = round((float) $invoice->tax, 2);
        $lines = [['account_code' => '2100', 'debit' => 0, 'credit' => (float) $invoice->total]];
        if ($goods > 0) {
            $lines[] = ['account_code' => '1400', 'debit' => $goods, 'credit' => 0];
        }
        if ($tax > 0) {
            $lines[] = ['account_code' => '1500', 'debit' => $tax, 'credit' => 0];
        }
        $entry = $this->createDraft($tenantId, $invoice->invoice_date, 'Hutang '.$invoice->invoice_number, $lines, SupplierInvoice::class, $invoice->id, $actorId);

        return $this->post($entry, $actorId);
    }

    public function recordCustomerReceipt(SalePayment $payment, ?int $actorId = null): ?JournalEntry
    {
        $tenantId = $payment->tenant_id;
        $existing = $this->existingPosted($tenantId, SalePayment::class, $payment->id);
        if ($existing) {
            return $existing;
        }
        $this->ensureDefaultChart($tenantId);
        $cash = $this->cashAccountForMethod($tenantId, $payment->method);
        $entry = $this->createDraft($tenantId, $payment->created_at->toDateString(), 'Terima pembayaran invoice #'.$payment->sales_invoice_id, [
            ['account_code' => $cash, 'debit' => (float) $payment->amount, 'credit' => 0],
            ['account_code' => '1300', 'debit' => 0, 'credit' => (float) $payment->amount],
        ], SalePayment::class, $payment->id, $actorId);

        return $this->post($entry, $actorId);
    }

    public function recordSupplierPayment(SupplierPayment $payment, ?int $actorId = null): ?JournalEntry
    {
        $tenantId = $payment->tenant_id;
        $existing = $this->existingPosted($tenantId, SupplierPayment::class, $payment->id);
        if ($existing) {
            return $existing;
        }
        $this->ensureDefaultChart($tenantId);
        $cash = $this->cashAccountForMethod($tenantId, $payment->method);
        $entry = $this->createDraft($tenantId, $payment->created_at->toDateString(), 'Bayar hutang invoice #'.$payment->supplier_invoice_id, [
            ['account_code' => '2100', 'debit' => (float) $payment->amount, 'credit' => 0],
            ['account_code' => $cash, 'debit' => 0, 'credit' => (float) $payment->amount],
        ], SupplierPayment::class, $payment->id, $actorId);

        return $this->post($entry, $actorId);
    }

    // ---- Periods ----

    public function closePeriod(int $tenantId, string $startsOn, string $endsOn, ?int $actorId = null): AccountingPeriod
    {
        abort_if($endsOn < $startsOn, 422, 'Period end must not precede period start.');
        $period = AccountingPeriod::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'starts_on' => $startsOn],
            ['ends_on' => $endsOn, 'status' => AccountingPeriod::OPEN]
        );
        abort_if($period->status === AccountingPeriod::CLOSED, 422, 'Period is already closed.');
        if ($period->ends_on->toDateString() !== $endsOn) {
            $period->update(['ends_on' => $endsOn]);
        }
        $before = $period->toArray();
        $period->update(['status' => AccountingPeriod::CLOSED, 'closed_by' => $actorId, 'closed_at' => now()]);
        $this->audit->log($tenantId, $actorId, 'accounting.period.closed', AccountingPeriod::class, $period->id, $before, $period->fresh()->toArray());

        return $period->fresh();
    }

    // ---- Reports (drafts excluded; voided originals stay in the ledger and
    // net against their posted reversals, so history is complete and balanced) ----

    /** @return array{rows:array,total_debit:float,total_credit:float,balanced:bool} */
    public function trialBalance(int $tenantId, string $asOf): array
    {
        $accounts = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('code')->get();
        $sums = $this->lineSums($tenantId, null, $asOf);
        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($accounts as $account) {
            $sum = $sums[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            $debit = round($sum['debit'], 2);
            $credit = round($sum['credit'], 2);
            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }
            $balance = $account->isDebitNormal() ? round($debit - $credit, 2) : round($credit - $debit, 2);
            if ($balance == 0.0) {
                continue;
            }
            // Contra balances flip columns (a credit balance on an asset shows
            // as a credit), keeping every displayed amount non-negative.
            if ($account->isDebitNormal()) {
                $d = $balance >= 0 ? $balance : 0.0;
                $c = $balance < 0 ? -$balance : 0.0;
            } else {
                $c = $balance >= 0 ? $balance : 0.0;
                $d = $balance < 0 ? -$balance : 0.0;
            }
            $rows[] = [
                'code' => $account->code, 'name' => $account->name, 'type' => $account->type,
                'debit' => $d,
                'credit' => $c,
            ];
            $totalDebit = round($totalDebit + $d, 2);
            $totalCredit = round($totalCredit + $c, 2);
        }

        return ['rows' => $rows, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit, 'balanced' => abs($totalDebit - $totalCredit) < 0.01];
    }

    /** @return array{income:array,expenses:array,total_income:float,total_expense:float,net_income:float} */
    public function profitLoss(int $tenantId, string $from, string $to): array
    {
        $accounts = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->whereIn('type', ['income', 'expense'])->orderBy('code')->get();
        $sums = $this->lineSums($tenantId, $from, $to);
        $income = [];
        $expenses = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        foreach ($accounts as $account) {
            $sum = $sums[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            $balance = $account->type === 'income'
                ? round($sum['credit'] - $sum['debit'], 2)
                : round($sum['debit'] - $sum['credit'], 2);
            if ($balance == 0.0) {
                continue;
            }
            if ($account->type === 'income') {
                $income[] = ['code' => $account->code, 'name' => $account->name, 'amount' => $balance];
                $totalIncome = round($totalIncome + $balance, 2);
            } else {
                $expenses[] = ['code' => $account->code, 'name' => $account->name, 'amount' => $balance];
                $totalExpense = round($totalExpense + $balance, 2);
            }
        }

        return ['income' => $income, 'expenses' => $expenses, 'total_income' => $totalIncome, 'total_expense' => $totalExpense, 'net_income' => round($totalIncome - $totalExpense, 2)];
    }

    /** @return array{assets:array,liabilities:array,equity:array,total_assets:float,total_liabilities:float,total_equity:float,current_earnings:float,balanced:bool} */
    public function balanceSheet(int $tenantId, string $asOf): array
    {
        $accounts = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->whereIn('type', ['asset', 'liability', 'equity'])->orderBy('code')->get();
        $sums = $this->lineSums($tenantId, null, $asOf);
        $groups = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $totals = ['assets' => 0.0, 'liabilities' => 0.0, 'equity' => 0.0];
        foreach ($accounts as $account) {
            $sum = $sums[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            $balance = $account->isDebitNormal() ? round($sum['debit'] - $sum['credit'], 2) : round($sum['credit'] - $sum['debit'], 2);
            if ($balance == 0.0) {
                continue;
            }
            $key = $account->type === 'asset' ? 'assets' : ($account->type === 'liability' ? 'liabilities' : 'equity');
            $groups[$key][] = ['code' => $account->code, 'name' => $account->name, 'amount' => $balance];
            $totals[$key] = round($totals[$key] + $balance, 2);
        }
        // Current earnings close into equity so the equation balances.
        $earnings = $this->profitLoss($tenantId, '1970-01-01', $asOf)['net_income'];
        $equityTotal = round($totals['equity'] + $earnings, 2);

        return [
            'assets' => $groups['assets'], 'liabilities' => $groups['liabilities'], 'equity' => $groups['equity'],
            'total_assets' => $totals['assets'], 'total_liabilities' => $totals['liabilities'],
            'total_equity' => $totals['equity'], 'current_earnings' => $earnings,
            'balanced' => abs($totals['assets'] - ($totals['liabilities'] + $equityTotal)) < 0.01,
        ];
    }

    /** @return array{cash_accounts:array,total_inflow:float,total_outflow:float,net:float} */
    public function cashFlow(int $tenantId, string $from, string $to): array
    {
        $cashAccounts = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_cash', true)->where('is_active', true)->orderBy('code')->get();
        $sums = $this->lineSums($tenantId, $from, $to);
        $rows = [];
        $inflow = 0.0;
        $outflow = 0.0;
        foreach ($cashAccounts as $account) {
            $sum = $sums[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            $in = round($sum['debit'], 2);
            $out = round($sum['credit'], 2);
            if ($in == 0.0 && $out == 0.0) {
                continue;
            }
            $rows[] = ['code' => $account->code, 'name' => $account->name, 'inflow' => $in, 'outflow' => $out, 'net' => round($in - $out, 2)];
            $inflow = round($inflow + $in, 2);
            $outflow = round($outflow + $out, 2);
        }

        return ['cash_accounts' => $rows, 'total_inflow' => $inflow, 'total_outflow' => $outflow, 'net' => round($inflow - $outflow, 2)];
    }

    /** @return array{output_tax:float,input_tax:float,net_payable:float} */
    public function taxSummary(int $tenantId, string $from, string $to): array
    {
        $out = $this->accountMovement($tenantId, '2200', $from, $to);
        $in = $this->accountMovement($tenantId, '1500', $from, $to);

        return ['output_tax' => $out, 'input_tax' => $in, 'net_payable' => round($out - $in, 2)];
    }

    /** @return array<int, array> */
    public function receivables(int $tenantId): array
    {
        return SalesInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('status', 'final')->where('payment_status', '!=', 'paid')
            ->orderBy('id')->get()->map(function ($inv) {
                $paid = round((float) $inv->payments()->sum('amount'), 2);

                return [
                    'invoice_no' => $inv->invoice_no, 'total' => (float) $inv->total,
                    'paid' => $paid, 'balance' => round((float) $inv->total - $paid, 2),
                    'payment_status' => $inv->payment_status,
                ];
            })->all();
    }

    /** @return array<int, array> */
    public function payables(int $tenantId): array
    {
        return SupplierInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('balance', '>', 0)->orderBy('id')->get()->map(fn ($inv) => [
                'invoice_number' => $inv->invoice_number, 'supplier_id' => $inv->supplier_id,
                'total' => (float) $inv->total, 'paid' => (float) $inv->paid, 'balance' => (float) $inv->balance,
            ])->all();
    }

    // ---- internals ----

    private function activeAccount(int $tenantId, ?string $code): Account
    {
        $account = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->where('is_active', true)->first();
        abort_if(! $account, 422, "Account [{$code}] is not available. Run the default chart setup first.");

        return $account;
    }

    private function existingPosted(int $tenantId, string $sourceType, int $sourceId): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('status', JournalEntry::POSTED)->first();
    }

    private function assertPeriodOpen(int $tenantId, string $date): void
    {
        $closed = AccountingPeriod::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('status', AccountingPeriod::CLOSED)
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->exists();
        abort_if($closed, 422, 'Target date falls inside a closed accounting period.');
    }

    private function nextEntryNo(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $no = 'JE-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            $exists = JournalEntry::withoutGlobalScopes()->where('entry_no', $no)->exists();
            if (! $exists) {
                return $no;
            }
        }

        return 'JE-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }

    /** @return array<int, array{debit:float,credit:float}> */
    private function lineSums(int $tenantId, ?string $from, ?string $to): array
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->whereIn('journal_entries.status', [JournalEntry::POSTED, JournalEntry::VOID]);
        if ($from !== null) {
            $query->whereDate('journal_entries.entry_date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('journal_entries.entry_date', '<=', $to);
        }
        $rows = $query->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')->get();
        $sums = [];
        foreach ($rows as $row) {
            $sums[(int) $row->account_id] = ['debit' => (float) $row->debit, 'credit' => (float) $row->credit];
        }

        return $sums;
    }

    private function accountMovement(int $tenantId, string $code, string $from, string $to): float
    {
        $account = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->first();
        if (! $account) {
            return 0.0;
        }
        $sum = $this->lineSums($tenantId, $from, $to)[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];

        return $account->isDebitNormal() ? round($sum['debit'] - $sum['credit'], 2) : round($sum['credit'] - $sum['debit'], 2);
    }

    private function cashAccountForMethod(int $tenantId, ?string $method): string
    {
        if (in_array($method, ['transfer', 'qris', 'ewallet', 'card', 'bank_transfer'], true)) {
            return '1200';
        }

        return '1100';
    }
}
