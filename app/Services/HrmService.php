<?php

namespace App\Services;

use App\Models\HrmAttendance;
use App\Models\HrmDepartment;
use App\Models\HrmEmployee;
use App\Models\HrmHoliday;
use App\Models\HrmLeave;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * HRM: departments, employees, check-in/out attendance, leave requests with
 * yearly balances and approval that stamps leave attendance, and holidays.
 */
final class HrmService
{
    public const ANNUAL_ENTITLEMENT = 12;

    public const SICK_ENTITLEMENT = 12;

    public function __construct(private AuditService $audit) {}

    public function createDepartment(int $tenantId, string $name, ?int $managerId, ?int $actorId = null): HrmDepartment
    {
        $name = trim($name);
        abort_if($name === '', 422, 'Department name is required.');
        abort_if(HrmDepartment::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', $name)->exists(), 422, 'Department already exists.');
        if ($managerId) {
            abort_unless(User::withoutGlobalScopes()->whereKey($managerId)->exists(), 422, 'Manager not found.');
        }

        $dept = HrmDepartment::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'name' => $name, 'manager_id' => $managerId]);
        $this->audit->log($tenantId, $actorId, 'hrm.department.created', HrmDepartment::class, $dept->id, null, ['name' => $name]);

        return $dept;
    }

    public function registerEmployee(int $tenantId, array $data, ?int $actorId = null): HrmEmployee
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Employee name is required.');
        $code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: $this->nextCode($tenantId);
        abort_if(HrmEmployee::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists(), 422, 'Employee code already exists.');
        $deptId = isset($data['department_id']) ? (int) $data['department_id'] : null;
        if ($deptId) {
            abort_unless(HrmDepartment::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($deptId)->exists(), 422, 'Department does not belong to tenant.');
        }
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        if ($userId) {
            abort_unless(User::withoutGlobalScopes()->whereKey($userId)->exists(), 422, 'User not found.');
            abort_if(HrmEmployee::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $userId)->exists(), 422, 'User is already linked to an employee.');
        }

        $employee = HrmEmployee::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
            'department_id' => $deptId, 'position' => $data['position'] ?? null,
            'user_id' => $userId, 'join_date' => $data['join_date'] ?? now()->toDateString(),
            'status' => HrmEmployee::ACTIVE, 'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'hrm.employee.registered', HrmEmployee::class, $employee->id, null, ['code' => $code]);

        return $employee;
    }

    public function setEmployeeStatus(HrmEmployee $employee, string $to, ?int $actorId = null): HrmEmployee
    {
        abort_unless(in_array($to, [HrmEmployee::ACTIVE, HrmEmployee::INACTIVE, HrmEmployee::TERMINATED], true), 422, 'Invalid employee status.');
        $before = $employee->toArray();
        $employee->update(['status' => $to]);
        $this->audit->log($employee->tenant_id, $actorId, 'hrm.employee.status', HrmEmployee::class, $employee->id, $before, $employee->fresh()->toArray());

        return $employee->fresh();
    }

    public function checkIn(HrmEmployee $employee, ?int $actorId = null): HrmAttendance
    {
        return DB::transaction(function () use ($employee, $actorId) {
            $locked = $this->activeEmployee($employee->id);
            $today = now()->toDateString();
            $record = HrmAttendance::withoutGlobalScopes()->where('employee_id', $locked->id)->whereDate('worked_on', $today)->first();
            abort_if($record && $record->check_in !== null, 422, 'Already checked in today.');
            $now = now();
            // Late after 09:00 local check-in.
            $late = $now->format('H:i') > '09:00';
            if (! $record) {
                $record = HrmAttendance::withoutGlobalScopes()->create([
                    'tenant_id' => $locked->tenant_id, 'employee_id' => $locked->id,
                    'worked_on' => $today, 'check_in' => $now,
                    'status' => $late ? 'late' : 'present',
                ]);
            } else {
                $record->update(['check_in' => $now, 'status' => $late ? 'late' : 'present']);
            }
            $this->audit->log($locked->tenant_id, $actorId, 'hrm.attendance.checkin', HrmAttendance::class, $record->id, null, ['at' => $now->toDateTimeString()]);

            return $record->fresh();
        });
    }

    public function checkOut(HrmEmployee $employee, ?int $actorId = null): HrmAttendance
    {
        return DB::transaction(function () use ($employee, $actorId) {
            $locked = $this->activeEmployee($employee->id);
            $today = now()->toDateString();
            $record = HrmAttendance::withoutGlobalScopes()->where('employee_id', $locked->id)->whereDate('worked_on', $today)->lockForUpdate()->first();
            abort_unless($record && $record->check_in !== null, 422, 'Check in first.');
            abort_if($record->check_out !== null, 422, 'Already checked out today.');
            $now = now();
            $hours = round(abs($now->diffInMinutes($record->check_in)) / 60, 2);
            $before = $record->toArray();
            $record->update(['check_out' => $now, 'work_hours' => $hours]);
            $this->audit->log($locked->tenant_id, $actorId, 'hrm.attendance.checkout', HrmAttendance::class, $record->id, $before, $record->fresh()->toArray());

            return $record->fresh();
        });
    }

    public function requestLeave(int $tenantId, int $employeeId, array $data, ?int $actorId = null): HrmLeave
    {
        $employee = HrmEmployee::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($employeeId);
        abort_if($employee->status !== HrmEmployee::ACTIVE, 422, 'Only active employees can request leave.');
        $type = $data['type'] ?? null;
        abort_unless(in_array($type, HrmLeave::TYPES, true), 422, 'Invalid leave type.');
        $starts = $data['starts_on'] ?? null;
        $ends = $data['ends_on'] ?? $starts;
        abort_if(! $starts, 422, 'Leave start date is required.');
        abort_if($ends < $starts, 422, 'Leave end must not precede start.');
        $days = $this->inclusiveDays($starts, $ends);
        if (in_array($type, ['annual', 'sick'], true)) {
            $remaining = $this->leaveBalance($tenantId, $employeeId, $type, substr($starts, 0, 4));
            abort_if($days > $remaining, 422, "Insufficient {$type} balance: {$remaining} day(s) left.");
        }
        $overlap = HrmLeave::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->where('status', '!=', HrmLeave::REJECTED)
            ->where('starts_on', '<=', $ends)->where('ends_on', '>=', $starts)->exists();
        abort_if($overlap, 422, 'Leave overlaps an existing request.');

        $leave = HrmLeave::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'employee_id' => $employeeId, 'type' => $type,
            'starts_on' => $starts, 'ends_on' => $ends, 'days' => $days,
            'reason' => $data['reason'] ?? null, 'status' => HrmLeave::PENDING,
        ]);
        $this->audit->log($tenantId, $actorId, 'hrm.leave.requested', HrmLeave::class, $leave->id, null, ['days' => $days]);

        return $leave;
    }

    /** Approval stamps leave attendance rows so reports stay consistent. */
    public function decideLeave(HrmLeave $leave, bool $approve, ?int $actorId = null): HrmLeave
    {
        return DB::transaction(function () use ($leave, $approve, $actorId) {
            $locked = HrmLeave::withoutGlobalScopes()->lockForUpdate()->findOrFail($leave->id);
            abort_if($locked->status !== HrmLeave::PENDING, 422, 'Only pending leave can be decided.');
            $before = $locked->toArray();
            $locked->update(['status' => $approve ? HrmLeave::APPROVED : HrmLeave::REJECTED, 'decided_by' => $actorId, 'decided_at' => now()]);
            if ($approve) {
                $day = $locked->starts_on->toDateString();
                $end = $locked->ends_on->toDateString();
                while ($day <= $end) {
                    HrmAttendance::withoutGlobalScopes()->updateOrCreate(
                        ['employee_id' => $locked->employee_id, 'worked_on' => $day],
                        ['tenant_id' => $locked->tenant_id, 'status' => 'leave']
                    );
                    $day = date('Y-m-d', strtotime($day.' +1 day'));
                }
            }
            $this->audit->log($locked->tenant_id, $actorId, $approve ? 'hrm.leave.approved' : 'hrm.leave.rejected', HrmLeave::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function createHoliday(int $tenantId, string $date, string $name, ?int $actorId = null): HrmHoliday
    {
        $name = trim($name);
        abort_if($name === '', 422, 'Holiday name is required.');
        abort_if(HrmHoliday::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereDate('holiday_on', $date)->exists(), 422, 'Holiday already exists on this date.');

        $holiday = HrmHoliday::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'holiday_on' => $date, 'name' => $name]);
        $this->audit->log($tenantId, $actorId, 'hrm.holiday.created', HrmHoliday::class, $holiday->id, null, ['date' => $date]);

        return $holiday;
    }

    public function leaveBalance(int $tenantId, int $employeeId, string $type, string $year): int
    {
        $entitlement = $type === 'annual' ? self::ANNUAL_ENTITLEMENT : ($type === 'sick' ? self::SICK_ENTITLEMENT : PHP_INT_MAX);
        if ($entitlement === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        $used = (int) HrmLeave::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->where('type', $type)->where('status', HrmLeave::APPROVED)
            ->where('starts_on', '>=', $year.'-01-01')->where('starts_on', '<=', $year.'-12-31')
            ->sum('days');

        return max(0, $entitlement - $used);
    }

    private function activeEmployee(int $id): HrmEmployee
    {
        $employee = HrmEmployee::withoutGlobalScopes()->findOrFail($id);
        abort_if($employee->status !== HrmEmployee::ACTIVE, 422, 'Employee is not active.');

        return $employee;
    }

    private function inclusiveDays(string $starts, string $ends): int
    {
        return (int) (strtotime($ends) - strtotime($starts)) / 86400 + 1;
    }

    private function nextCode(int $tenantId): string
    {
        $n = HrmEmployee::withoutGlobalScopes()->where('tenant_id', $tenantId)->count() + 1;

        return 'EMP-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
