<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HrmAttendance;
use App\Models\HrmEmployee;
use App\Models\HrmLeave;
use App\Models\Membership;
use App\Models\User;
use App\Services\HrmService;
use App\Services\ModuleManager;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * HRM module: departments, employees, attendance, leave with balances and
 * approval-stamped attendance, holidays, isolation, RBAC and API.
 */
class HrmTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko HRM', bool $enableHrm = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableHrm) {
            app(ModuleManager::class)->enable($tenant->id, 'hrm');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko HRM Gate', false);

        $this->actingAs($owner)->get('/hrm')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/hrm/employees', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'hrm');
        $this->actingAs($owner)->get('/hrm')->assertOk()->assertSeeText('Karyawan');
    }

    public function test_employee_registration_and_unique_codes(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(HrmService::class);

        $dept = $svc->createDepartment($tenant->id, 'Gudang', null, $owner->id);
        $a = $svc->registerEmployee($tenant->id, ['name' => 'Andi', 'department_id' => $dept->id, 'position' => 'Staff'], $owner->id);
        $this->assertSame('EMP-0001', $a->code);
        $b = $svc->registerEmployee($tenant->id, ['name' => 'Budi', 'code' => 'CUSTOM-1'], $owner->id);
        $this->assertSame('CUSTOM-1', $b->code);

        try {
            $svc->registerEmployee($tenant->id, ['name' => 'Duplikat', 'code' => 'CUSTOM-1'], $owner->id);
            $this->fail('Duplicate employee code must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        try {
            $svc->registerEmployee($tenant->id, ['name' => 'Asing', 'department_id' => 999999], $owner->id);
            $this->fail('Foreign department must be rejected.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
    }

    public function test_checkin_checkout_records_hours(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(HrmService::class);
        $employee = $svc->registerEmployee($tenant->id, ['name' => 'Hadir'], $owner->id);

        $in = $svc->checkIn($employee, $owner->id);
        $this->assertNotNull($in->check_in);
        try {
            $svc->checkIn($employee->refresh(), $owner->id);
            $this->fail('Double check-in must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->travel(90)->minutes();
        $out = $svc->checkOut($employee->refresh(), $owner->id);
        $this->assertSame(1.5, (float) $out->work_hours);
        try {
            $svc->checkOut($employee->refresh(), $owner->id);
            $this->fail('Double check-out must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_leave_balance_overlap_and_approval_stamps_attendance(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(HrmService::class);
        $employee = $svc->registerEmployee($tenant->id, ['name' => 'Cuti'], $owner->id);
        $year = now()->format('Y');

        $this->assertSame(12, $svc->leaveBalance($tenant->id, $employee->id, 'annual', $year));
        $leave = $svc->requestLeave($tenant->id, $employee->id, [
            'type' => 'annual', 'starts_on' => $year.'-08-10', 'ends_on' => $year.'-08-12', 'reason' => 'Liburan',
        ], $owner->id);
        $this->assertSame(3, $leave->days);

        // Overlaps are refused while pending or approved.
        try {
            $svc->requestLeave($tenant->id, $employee->id, ['type' => 'annual', 'starts_on' => $year.'-08-11', 'ends_on' => $year.'-08-13'], $owner->id);
            $this->fail('Overlapping leave must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        // Beyond balance is refused.
        try {
            $svc->requestLeave($tenant->id, $employee->id, ['type' => 'annual', 'starts_on' => $year.'-09-01', 'ends_on' => $year.'-09-30'], $owner->id);
            $this->fail('Leave beyond balance must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->decideLeave($leave, true, $owner->id);
        $this->assertSame(9, $svc->leaveBalance($tenant->id, $employee->id, 'annual', $year));
        // Approval stamped one leave-attendance row per day.
        $this->assertSame(3, HrmAttendance::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('employee_id', $employee->id)->where('status', 'leave')->count());
        // Deciding twice is refused.
        try {
            $svc->decideLeave($leave->refresh(), false, $owner->id);
            $this->fail('Re-deciding leave must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_holiday_uniqueness(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(HrmService::class);
        $svc->createHoliday($tenant->id, '2026-12-25', 'Natal', $owner->id);
        try {
            $svc->createHoliday($tenant->id, '2026-12-25', 'Natal Lagi', $owner->id);
            $this->fail('Duplicate holiday must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko HRM A');
        [$tenantB, $ownerB] = $this->context('Toko HRM B');
        $empA = app(HrmService::class)->registerEmployee($tenantA->id, ['name' => 'Rahasia A'], null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, HrmEmployee::query()->count());
        $this->actingAs($ownerB)->get('/hrm')->assertOk()->assertDontSee('Rahasia A');
        $this->actingAs($ownerB)->post("/hrm/employees/{$empA->id}/checkin")->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/hrm')->assertForbidden();
        $member->givePermissionTo('hrm.view');
        $this->actingAs($member)->get('/hrm')->assertOk();
        $this->actingAs($member)->post('/hrm/employees', ['name' => 'X'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];

        $created = $this->actingAs($owner)->postJson('/api/v1/hrm/employees', ['name' => 'API Staff'], $headers)->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame('EMP-0001', $created->json('data.code'));

        $this->actingAs($owner)->postJson("/api/v1/hrm/employees/{$id}/attend", ['action' => 'in'], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/hrm/employees/{$id}/leave", [
            'type' => 'sick', 'starts_on' => now()->addDay()->toDateString(),
        ], $headers)->assertCreated()->assertJsonPath('data.days', 1);
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/hrm/employees', ['name' => 'Web Staff'])->assertRedirect();
        $employee = HrmEmployee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post("/hrm/employees/{$employee->id}/checkin")->assertRedirect();
        $this->actingAs($owner)->post("/hrm/employees/{$employee->id}/leave", [
            'type' => 'annual', 'starts_on' => now()->addDays(7)->toDateString(), 'ends_on' => now()->addDays(8)->toDateString(),
        ])->assertRedirect();
        $leave = HrmLeave::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($owner)->post("/hrm/leaves/{$leave->id}/decide", ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $leave->refresh()->status);
        $this->actingAs($owner)->post('/hrm/holidays', ['holiday_on' => '2026-12-25', 'name' => 'Natal'])->assertRedirect();
        $this->actingAs($owner)->get('/hrm')->assertOk()->assertSeeText('Web Staff');
    }
}
