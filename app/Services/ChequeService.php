<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\ChequeDeposit;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cheques: receipt/issue, deposit batches, clearance, bounce with
 * redeposit, cancellation, reconciliation, and accounting posting on
 * clearance when that module is enabled.
 */
final class ChequeService
{
    public function __construct(private AuditService $audit) {}

    public function receive(int $tenantId, array $data, ?int $actorId = null): Cheque
    {
        return $this->book($tenantId, 'receipt', $data, Cheque::RECEIVED, $actorId);
    }

    public function issue(int $tenantId, array $data, ?int $actorId = null): Cheque
    {
        return $this->book($tenantId, 'payment', $data, Cheque::ISSUED, $actorId);
    }

    public function deposit(int $tenantId, array $chequeIds, string $bankName, string $depositedOn, ?int $actorId = null): ChequeDeposit
    {
        return DB::transaction(function () use ($tenantId, $chequeIds, $bankName, $depositedOn, $actorId) {
            $bankName = trim($bankName);
            abort_if($bankName === '', 422, 'Bank name is required.');
            $chequeIds = array_values(array_unique(array_map('intval', $chequeIds)));
            abort_if($chequeIds === [], 422, 'Select at least one cheque to deposit.');
            $cheques = Cheque::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $chequeIds)->lockForUpdate()->get();
            abort_if($cheques->count() !== count($chequeIds), 422, 'Some cheques do not belong to this tenant.');
            foreach ($cheques as $cheque) {
                abort_unless(in_array($cheque->status, [Cheque::RECEIVED, Cheque::ISSUED, Cheque::BOUNCED], true), 422, "Cheque [{$cheque->cheque_no}] cannot be deposited from [{$cheque->status}].");
                abort_if($cheque->bank_name !== $bankName, 422, "Cheque [{$cheque->cheque_no}] belongs to a different bank.");
            }
            $deposit = ChequeDeposit::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'number' => $this->nextDepositNumber(),
                'bank_name' => $bankName, 'deposited_on' => $depositedOn,
                'status' => 'deposited', 'created_by' => $actorId,
            ]);
            foreach ($cheques as $cheque) {
                $cheque->update(['status' => Cheque::DEPOSITED, 'deposit_id' => $deposit->id, 'bounce_reason' => null]);
            }
            $this->audit->log($tenantId, $actorId, 'cheque.deposited', ChequeDeposit::class, $deposit->id, null, ['cheques' => $chequeIds]);

            return $deposit->load('cheques');
        });
    }

    public function clear(Cheque $cheque, ?int $actorId = null): Cheque
    {
        return DB::transaction(function () use ($cheque, $actorId) {
            $locked = Cheque::withoutGlobalScopes()->lockForUpdate()->findOrFail($cheque->id);
            abort_if($locked->status !== Cheque::DEPOSITED, 422, 'Only deposited cheques can clear.');
            $before = $locked->toArray();
            $locked->update(['status' => Cheque::CLEARED]);
            $this->postToAccounting($locked, $actorId);
            $this->closeDepositIfSettled($locked->tenant_id, $locked->deposit_id);
            $this->audit->log($locked->tenant_id, $actorId, 'cheque.cleared', Cheque::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function bounce(Cheque $cheque, string $reason, ?int $actorId = null): Cheque
    {
        $locked = Cheque::withoutGlobalScopes()->lockForUpdate()->findOrFail($cheque->id);
        abort_if($locked->status !== Cheque::DEPOSITED, 422, 'Only deposited cheques can bounce.');
        $reason = trim($reason);
        abort_if($reason === '', 422, 'Bounce reason is required.');
        $before = $locked->toArray();
        $depositId = $locked->deposit_id;
        $locked->update(['status' => Cheque::BOUNCED, 'deposit_id' => null, 'bounce_reason' => $reason]);
        $this->closeDepositIfSettled($locked->tenant_id, $depositId);
        $this->postBounceToAccounting($locked, $actorId);
        $this->audit->log($locked->tenant_id, $actorId, 'cheque.bounced', Cheque::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function cancel(Cheque $cheque, ?int $actorId = null): Cheque
    {
        abort_unless(in_array($cheque->status, [Cheque::RECEIVED, Cheque::ISSUED], true), 422, 'Only undeposited cheques can be cancelled.');
        $before = $cheque->toArray();
        $cheque->update(['status' => Cheque::CANCELLED]);
        $this->audit->log($cheque->tenant_id, $actorId, 'cheque.cancelled', Cheque::class, $cheque->id, $before, $cheque->fresh()->toArray());

        return $cheque->fresh();
    }

    /** @return array{cleared:float,outstanding:float,bounced:float,by_bank:array} */
    public function reconcile(int $tenantId, string $from, string $to): array
    {
        $cheques = Cheque::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)->get();
        $cleared = 0.0;
        $outstanding = 0.0;
        $bounced = 0.0;
        $byBank = [];
        foreach ($cheques as $cheque) {
            $signed = $cheque->type === 'receipt' ? (float) $cheque->amount : -(float) $cheque->amount;
            $bank = $cheque->bank_name;
            $byBank[$bank] ??= ['cleared' => 0.0, 'outstanding' => 0.0, 'bounced' => 0.0];
            match ($cheque->status) {
                Cheque::CLEARED => [$cleared += $signed, $byBank[$bank]['cleared'] = round($byBank[$bank]['cleared'] + $signed, 2)],
                Cheque::BOUNCED, Cheque::CANCELLED => [$bounced += $signed, $byBank[$bank]['bounced'] = round($byBank[$bank]['bounced'] + $signed, 2)],
                default => [$outstanding += $signed, $byBank[$bank]['outstanding'] = round($byBank[$bank]['outstanding'] + $signed, 2)],
            };
        }

        return [
            'cleared' => round($cleared, 2), 'outstanding' => round($outstanding, 2), 'bounced' => round($bounced, 2),
            'by_bank' => $byBank,
        ];
    }

    private function book(int $tenantId, string $type, array $data, string $status, ?int $actorId): Cheque
    {
        $bank = trim((string) ($data['bank_name'] ?? ''));
        $no = trim((string) ($data['cheque_no'] ?? ''));
        abort_if($bank === '' || $no === '', 422, 'Bank and cheque number are required.');
        $amount = round((float) ($data['amount'] ?? 0), 2);
        abort_if($amount <= 0, 422, 'Amount must be positive.');
        abort_if(empty($data['issue_date']) || empty($data['due_date']), 422, 'Issue and due dates are required.');
        abort_if($data['due_date'] < $data['issue_date'], 422, 'Due date must not precede issue date.');
        abort_if(Cheque::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('bank_name', $bank)->where('cheque_no', $no)->exists(), 422, 'Cheque number already recorded for this bank.');
        $contactId = isset($data['contact_id']) ? (int) $data['contact_id'] : null;
        if ($contactId) {
            abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($contactId)->exists(), 422, 'Contact does not belong to tenant.');
        }

        $cheque = Cheque::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'type' => $type, 'contact_id' => $contactId,
            'bank_name' => $bank, 'cheque_no' => $no, 'amount' => $amount,
            'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'],
            'status' => $status, 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, $type === 'receipt' ? 'cheque.received' : 'cheque.issued', Cheque::class, $cheque->id, null, ['cheque_no' => $no, 'amount' => $amount]);

        return $cheque;
    }

    /** Clear posts Dr Bank / Cr AR (receipts) or Dr AP / Cr Bank (payments). */
    private function postToAccounting(Cheque $cheque, ?int $actorId): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($cheque->tenant_id, 'accounting')) {
            return;
        }
        $accounting = app(AccountingService::class);
        $accounting->ensureDefaultChart($cheque->tenant_id);
        $amount = round((float) $cheque->amount, 2);
        $lines = $cheque->type === 'receipt'
            ? [
                ['account_code' => '1200', 'debit' => $amount, 'credit' => 0],
                ['account_code' => '1300', 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account_code' => '2100', 'debit' => $amount, 'credit' => 0],
                ['account_code' => '1200', 'debit' => 0, 'credit' => $amount],
            ];
        $existing = JournalEntry::withoutGlobalScopes()->where('tenant_id', $cheque->tenant_id)
            ->where('source_type', Cheque::class)->where('source_id', $cheque->id)
            ->where('status', JournalEntry::POSTED)->first();
        if ($existing) {
            return;
        }
        $entry = $accounting->createDraft($cheque->tenant_id, now()->toDateString(), 'Kliring cek '.$cheque->cheque_no, $lines, Cheque::class, $cheque->id, $actorId);
        $accounting->post($entry, $actorId);
    }

    /**
     * A bounced receipt reverses the clearance: Dr Piutang / Cr Bank. A bounced
     * payment reinstates the supplier liability: Dr Bank / Cr Hutang. The
     * reversal uses the bounce id as a distinct source reference so it never
     * collides with the original clearance entry.
     */
    private function postBounceToAccounting(Cheque $cheque, ?int $actorId): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($cheque->tenant_id, 'accounting')) {
            return;
        }
        $accounting = app(AccountingService::class);
        $accounting->ensureDefaultChart($cheque->tenant_id);
        $amount = round((float) $cheque->amount, 2);
        $sourceType = Cheque::class . '#bounce';
        $existing = JournalEntry::withoutGlobalScopes()->where('tenant_id', $cheque->tenant_id)
            ->where('source_type', $sourceType)->where('source_id', $cheque->id)
            ->where('status', JournalEntry::POSTED)->first();
        if ($existing) {
            return;
        }
        $lines = $cheque->type === 'receipt'
            ? [
                ['account_code' => '1300', 'debit' => $amount, 'credit' => 0],
                ['account_code' => '1200', 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account_code' => '1200', 'debit' => $amount, 'credit' => 0],
                ['account_code' => '2100', 'debit' => 0, 'credit' => $amount],
            ];
        $entry = $accounting->createDraft($cheque->tenant_id, now()->toDateString(), 'Bounce cek '.$cheque->cheque_no.' (reversal)', $lines, $sourceType, $cheque->id, $actorId);
        $accounting->post($entry, $actorId);
    }

    private function closeDepositIfSettled(int $tenantId, ?int $depositId): void
    {
        if (! $depositId) {
            return;
        }
        $remaining = Cheque::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('deposit_id', $depositId)->where('status', Cheque::DEPOSITED)->exists();
        if (! $remaining) {
            ChequeDeposit::withoutGlobalScopes()->whereKey($depositId)->update(['status' => 'cleared']);
        }
    }

    private function nextDepositNumber(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $no = 'DEP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            if (! ChequeDeposit::withoutGlobalScopes()->where('number', $no)->exists()) {
                return $no;
            }
        }

        return 'DEP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
