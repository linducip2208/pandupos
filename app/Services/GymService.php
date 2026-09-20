<?php

namespace App\Services;

use App\Models\GymAttendance;
use App\Models\GymMember;
use App\Models\GymMembership;
use App\Models\GymPackage;
use App\Models\GymTrainer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gym: packages, trainers, members, subscriptions with lazy expiry,
 * visit-capped check-ins, partial payments and renewal as new history.
 */
final class GymService
{
    public function __construct(private AuditService $audit) {}

    public function createPackage(int $tenantId, array $data, ?int $actorId = null): GymPackage
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Package name is required.');
        abort_if(GymPackage::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', $name)->exists(), 422, 'Package already exists.');
        $days = (int) ($data['duration_days'] ?? 0);
        abort_if($days <= 0, 422, 'Duration must be a positive number of days.');
        $price = round((float) ($data['price'] ?? 0), 2);
        abort_if($price < 0, 422, 'Price cannot be negative.');

        $package = GymPackage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'name' => $name, 'duration_days' => $days,
            'price' => $price, 'visits_limit' => isset($data['visits_limit']) ? max(1, (int) $data['visits_limit']) : null,
            'is_active' => true,
        ]);
        $this->audit->log($tenantId, $actorId, 'gym.package.created', GymPackage::class, $package->id, null, ['name' => $name]);

        return $package;
    }

    public function registerMember(int $tenantId, array $data, ?int $actorId = null): GymMember
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Member name is required.');
        $code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: $this->nextCode($tenantId);
        abort_if(GymMember::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists(), 422, 'Member code already exists.');

        $member = GymMember::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
            'phone' => $data['phone'] ?? null, 'contact_id' => $data['contact_id'] ?? null,
            'status' => 'active',
        ]);
        $this->audit->log($tenantId, $actorId, 'gym.member.registered', GymMember::class, $member->id, null, ['code' => $code]);

        return $member;
    }

    public function registerTrainer(int $tenantId, array $data, ?int $actorId = null): GymTrainer
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Trainer name is required.');
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        if ($userId) {
            abort_unless(User::withoutGlobalScopes()->whereKey($userId)->exists(), 422, 'User not found.');
        }

        return GymTrainer::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'name' => $name, 'specialization' => $data['specialization'] ?? null,
            'user_id' => $userId, 'is_active' => true,
        ]);
    }

    public function subscribe(int $tenantId, int $memberId, int $packageId, string $startsOn, ?int $actorId = null): GymMembership
    {
        return DB::transaction(function () use ($tenantId, $memberId, $packageId, $startsOn, $actorId) {
            $member = GymMember::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($memberId);
            abort_if($member->status !== 'active', 422, 'Member is not active.');
            $package = GymPackage::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($packageId);
            $active = GymMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('member_id', $memberId)->where('status', GymMembership::ACTIVE)->lockForUpdate()->first();
            abort_if($active, 422, 'Member already has an active subscription; renew after it ends.');
            $endsOn = date('Y-m-d', strtotime($startsOn.' +'.((int) $package->duration_days).' days'));
            $membership = GymMembership::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'member_id' => $memberId, 'package_id' => $package->id,
                'starts_on' => $startsOn, 'ends_on' => $endsOn,
                'visits_limit' => $package->visits_limit, 'visits_used' => 0,
                'price' => $package->price, 'paid' => 0, 'balance' => $package->price,
                'status' => GymMembership::ACTIVE,
            ]);
            $this->audit->log($tenantId, $actorId, 'gym.membership.subscribed', GymMembership::class, $membership->id, null, ['package' => $package->name]);

            return $membership;
        });
    }

    public function recordPayment(GymMembership $membership, float $amount, ?int $actorId = null): GymMembership
    {
        $locked = $this->locked($membership->id);
        abort_if($locked->status === GymMembership::CANCELLED, 422, 'Cancelled memberships cannot receive payment.');
        $amount = round($amount, 2);
        abort_if($amount <= 0 || $amount > (float) $locked->balance + 0.005, 422, 'Payment must be positive and cannot exceed the balance.');
        $before = $locked->toArray();
        $paid = round((float) $locked->paid + $amount, 2);
        $locked->update(['paid' => $paid, 'balance' => round((float) $locked->price - $paid, 2)]);
        $this->audit->log($locked->tenant_id, $actorId, 'gym.payment.recorded', GymMembership::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    /** Check-in enforces dates, visit caps and lazy expiry. */
    public function checkIn(GymMembership $membership, ?int $trainerId, ?int $actorId = null): GymAttendance
    {
        // Lazy expiry must persist even though the check-in itself is refused:
        // finalize it outside the attendance transaction below.
        $locked = $this->locked($membership->id);
        if ($locked->status === GymMembership::ACTIVE && $locked->ends_on->toDateString() < now()->toDateString()) {
            $locked->update(['status' => GymMembership::EXPIRED]);
            $this->audit->log($locked->tenant_id, $actorId, 'gym.membership.expired', GymMembership::class, $locked->id, ['status' => 'active'], ['status' => 'expired']);
            $locked = $locked->fresh();
        }
        abort_if($locked->status !== GymMembership::ACTIVE, 422, 'Membership is not active.');

        return DB::transaction(function () use ($locked, $trainerId, $actorId) {
            $fresh = $this->locked($locked->id);
            if ($fresh->visits_limit !== null) {
                abort_if((int) $fresh->visits_used >= (int) $fresh->visits_limit, 422, 'Visit quota exhausted; renew the membership.');
            }
            if ($trainerId) {
                abort_unless(GymTrainer::withoutGlobalScopes()->where('tenant_id', $fresh->tenant_id)->whereKey($trainerId)->where('is_active', true)->exists(), 422, 'Trainer is not available.');
            }
            $attendance = GymAttendance::withoutGlobalScopes()->create([
                'tenant_id' => $fresh->tenant_id, 'membership_id' => $fresh->id,
                'member_id' => $fresh->member_id, 'trainer_id' => $trainerId, 'checked_in_at' => now(),
            ]);
            $fresh->increment('visits_used');
            $this->audit->log($fresh->tenant_id, $actorId, 'gym.attendance.checkin', GymAttendance::class, $attendance->id, null, ['membership_id' => $fresh->id]);

            return $attendance;
        });
    }

    public function cancel(GymMembership $membership, ?int $actorId = null): GymMembership
    {
        $locked = $this->locked($membership->id);
        abort_if($locked->status !== GymMembership::ACTIVE, 422, 'Only active memberships can be cancelled.');
        $before = $locked->toArray();
        $locked->update(['status' => GymMembership::CANCELLED]);
        $this->audit->log($locked->tenant_id, $actorId, 'gym.membership.cancelled', GymMembership::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    private function locked(int $id): GymMembership
    {
        return GymMembership::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);
    }

    private function nextCode(int $tenantId): string
    {
        $n = GymMember::withoutGlobalScopes()->where('tenant_id', $tenantId)->count() + 1;

        return 'GYM-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
