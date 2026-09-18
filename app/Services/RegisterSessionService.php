<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\CashSessionMovement;
use App\Models\Register;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;

/** Cash-register lifecycle with a locked, tenant-scoped session ledger. */
final class RegisterSessionService
{
    public const DENOMINATIONS = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];

    public function __construct(private AuditService $audit) {}

    public function open(int $tenantId, int $registerId, int $actorId, float $openingAmount): CashSession
    {
        abort_if($openingAmount < 0, 422, 'Opening cash cannot be negative.');

        return DB::transaction(function () use ($tenantId, $registerId, $actorId, $openingAmount) {
            $register = Register::withoutGlobalScopes()->lockForUpdate()
                ->where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($registerId);
            abort_if(CashSession::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('register_id', $register->id)->where('status', 'open')->exists(), 422, 'Register already has an open session.');

            $session = CashSession::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'register_id' => $register->id, 'opened_by' => $actorId,
                'opening_amount' => round($openingAmount, 2), 'status' => 'open', 'opened_at' => now(),
            ]);
            $this->audit->log($tenantId, $actorId, 'register.session.opened', CashSession::class, $session->id, null, $session->toArray());

            return $session;
        });
    }

    public function movement(CashSession $session, int $actorId, string $type, float $amount, string $reason): CashSessionMovement
    {
        abort_unless(in_array($type, ['cash_in', 'cash_out'], true), 422, 'Unknown cash movement type.');
        abort_if($amount <= 0 || trim($reason) === '', 422, 'Cash movement amount and reason are required.');

        return DB::transaction(function () use ($session, $actorId, $type, $amount, $reason) {
            $locked = CashSession::withoutGlobalScopes()->lockForUpdate()->findOrFail($session->id);
            abort_unless($locked->status === 'open', 422, 'Cash session is closed.');
            $movement = CashSessionMovement::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id, 'cash_session_id' => $locked->id, 'created_by' => $actorId,
                'type' => $type, 'amount' => round($amount, 2), 'reason' => trim($reason),
            ]);
            $this->audit->log($locked->tenant_id, $actorId, 'register.cash.'.$type, CashSessionMovement::class, $movement->id, null, $movement->toArray());

            return $movement;
        });
    }

    /** @param array<int|string, mixed> $denominationCounts */
    public function close(CashSession $session, int $actorId, float $actualAmount, array $denominationCounts = [], ?string $notes = null, bool $canForceClose = false): CashSession
    {
        abort_if($actualAmount < 0, 422, 'Actual cash cannot be negative.');
        $normalized = $this->normalizeDenominations($denominationCounts);
        if ($normalized !== []) {
            $counted = array_sum(array_map(fn (int $denomination, int $count): float => $denomination * $count, array_keys($normalized), $normalized));
            abort_if(abs($counted - $actualAmount) > 0.009, 422, 'Actual cash must equal the denomination count.');
        }

        return DB::transaction(function () use ($session, $actorId, $actualAmount, $normalized, $notes, $canForceClose) {
            $locked = CashSession::withoutGlobalScopes()->lockForUpdate()->findOrFail($session->id);
            abort_unless($locked->status === 'open', 422, 'Cash session is already closed.');
            abort_unless($locked->opened_by === $actorId || $canForceClose, 403, 'Only the opener may close this register session.');

            $cashSales = (float) SalePayment::withoutGlobalScopes()
                ->where('tenant_id', $locked->tenant_id)->where('method', 'cash')
                ->whereHas('invoice', fn ($query) => $query->withoutGlobalScopes()->where('cash_session_id', $locked->id)->where('status', 'final'))
                ->sum('amount');
            $cashIn = (float) CashSessionMovement::withoutGlobalScopes()->where('cash_session_id', $locked->id)->where('type', 'cash_in')->sum('amount');
            $cashOut = (float) CashSessionMovement::withoutGlobalScopes()->where('cash_session_id', $locked->id)->where('type', 'cash_out')->sum('amount');
            $expected = round((float) $locked->opening_amount + $cashSales + $cashIn - $cashOut, 2);
            $actual = round($actualAmount, 2);
            $before = $locked->toArray();
            $locked->update([
                'status' => 'closed', 'closed_by' => $actorId, 'closing_amount' => $actual,
                'expected_amount' => $expected, 'variance_amount' => round($actual - $expected, 2),
                'denomination_counts' => $normalized ?: null, 'closing_notes' => $notes, 'closed_at' => now(),
            ]);
            $this->audit->log($locked->tenant_id, $actorId, 'register.session.closed', CashSession::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function expectedAmount(CashSession $session): float
    {
        $cashSales = (float) SalePayment::withoutGlobalScopes()->where('tenant_id', $session->tenant_id)->where('method', 'cash')
            ->whereHas('invoice', fn ($query) => $query->withoutGlobalScopes()->where('cash_session_id', $session->id)->where('status', 'final'))->sum('amount');
        $cashIn = (float) CashSessionMovement::withoutGlobalScopes()->where('cash_session_id', $session->id)->where('type', 'cash_in')->sum('amount');
        $cashOut = (float) CashSessionMovement::withoutGlobalScopes()->where('cash_session_id', $session->id)->where('type', 'cash_out')->sum('amount');

        return round((float) $session->opening_amount + $cashSales + $cashIn - $cashOut, 2);
    }

    /** @param array<int|string, mixed> $counts @return array<int, int> */
    private function normalizeDenominations(array $counts): array
    {
        $result = [];
        foreach ($counts as $denomination => $count) {
            $denomination = (int) $denomination;
            abort_unless(in_array($denomination, self::DENOMINATIONS, true), 422, 'Unsupported denomination.');
            abort_unless(is_numeric($count) && (float) $count >= 0 && floor((float) $count) === (float) $count, 422, 'Denomination count must be a whole non-negative number.');
            $count = (int) $count;
            if ($count > 0) {
                $result[$denomination] = $count;
            }
        }

        return $result;
    }
}
