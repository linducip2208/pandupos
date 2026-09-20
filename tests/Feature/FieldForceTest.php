<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FfTask;
use App\Models\FfVisit;
use App\Models\Membership;
use App\Models\User;
use App\Services\FieldForceService;
use App\Services\ModuleManager;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Field force module: task lifecycle, GPS visits with distance, photo
 * evidence, idempotent offline retries, isolation, RBAC and API.
 */
class FieldForceTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Lapangan', bool $enableFf = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableFf) {
            app(ModuleManager::class)->enable($tenant->id, 'fieldforce');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko FF Gate', false);

        $this->actingAs($owner)->get('/fieldforce')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/fieldforce/tasks', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'fieldforce');
        $this->actingAs($owner)->get('/fieldforce')->assertOk()->assertSeeText('Tugas lapangan');
    }

    public function test_visit_lifecycle_measures_distance_and_guards(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(FieldForceService::class);
        $worker = User::factory()->create();

        $task = $svc->createTask($tenant->id, ['title' => 'Kunjungan toko', 'assignee_id' => $worker->id, 'address' => 'Jl. Mawar'], $owner->id);
        // Non-assignee cannot check in.
        try {
            $svc->checkIn($task, $owner->id, -6.2, 106.8, null, $owner->id);
            $this->fail('Non-assignee check-in must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        // Invalid coordinates refused.
        try {
            $svc->checkIn($task, $worker->id, -95, 106.8, null, $owner->id);
            $this->fail('Invalid latitude must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $visit = $svc->checkIn($task, $worker->id, -6.2000, 106.8000, 'offline-key-1', $owner->id);
        $this->assertSame('checked_in', $task->refresh()->status);
        // Offline retry with the same key returns the original visit.
        $again = $svc->checkIn($task->refresh(), $worker->id, -6.2000, 106.8000, 'offline-key-1', $owner->id);
        $this->assertSame($visit->id, $again->id);
        $this->assertSame(1, FfVisit::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // ~1.1 km east: distance recorded.
        $done = $svc->checkOut($visit, -6.2000, 106.8100, 'Selesai', null, $owner->id);
        $this->assertGreaterThan(1000, $done->distance_m);
        $this->assertLessThan(1200, $done->distance_m);
        try {
            $svc->checkOut($done->refresh(), -6.2, 106.81, null, null, $owner->id);
            $this->fail('Double check-out must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Completion requires a checked-out visit.
        $this->assertSame('completed', $svc->completeTask($task->refresh(), $owner->id)->status);
    }

    public function test_photo_evidence_validated_and_private(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(FieldForceService::class);
        $worker = User::factory()->create();
        $task = $svc->createTask($tenant->id, ['title' => 'Foto'], $owner->id);
        $visit = $svc->checkIn($task, $worker->id, -6.2, 106.8, null, $owner->id);

        Storage::fake('local');
        $photo = UploadedFile::fake()->image('bukti.jpg', 800, 600);
        $done = $svc->checkOut($visit, -6.2, 106.8, null, $photo, $owner->id);
        $this->assertNotNull($done->evidence_photo);
        Storage::disk('local')->assertExists($done->evidence_photo);

        $visit2 = $svc->checkIn($svc->createTask($tenant->id, ['title' => 'Foto 2'], $owner->id), $worker->id, -6.2, 106.8, null, $owner->id);
        try {
            $svc->checkOut($visit2, -6.2, 106.8, null, UploadedFile::fake()->create('evil.php', 100, 'application/x-php'), $owner->id);
            $this->fail('Executable upload must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_task_guards(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(FieldForceService::class);
        $task = $svc->createTask($tenant->id, ['title' => 'Guard'], $owner->id);

        try {
            $svc->completeTask($task, $owner->id);
            $this->fail('Completing without a visit must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('cancelled', $svc->cancelTask($task, $owner->id)->status);
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko FF A');
        [$tenantB, $ownerB] = $this->context('Toko FF B');
        $taskA = app(FieldForceService::class)->createTask($tenantA->id, ['title' => 'Rahasia A'], null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, FfTask::query()->count());
        $this->actingAs($ownerB)->get('/fieldforce')->assertOk()->assertDontSee('Rahasia A');
        $this->actingAs($ownerB)->post("/fieldforce/tasks/{$taskA->id}/transition", ['action' => 'cancel'])->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/fieldforce')->assertForbidden();
        $member->givePermissionTo('fieldforce.view');
        $this->actingAs($member)->get('/fieldforce')->assertOk();
        $this->actingAs($member)->post('/fieldforce/tasks', ['title' => 'X'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];

        $created = $this->actingAs($owner)->postJson('/api/v1/fieldforce/tasks', ['title' => 'API Visit'], $headers)->assertCreated();
        $id = $created->json('data.id');

        $checkin = $this->actingAs($owner)->postJson("/api/v1/fieldforce/tasks/{$id}/checkin", [
            'lat' => -6.2, 'lng' => 106.8, 'idempotency_key' => 'api-key-1',
        ], $headers)->assertCreated();
        $visitId = $checkin->json('data.id');
        // Retry with the same key: same visit, no duplicate.
        $this->actingAs($owner)->postJson("/api/v1/fieldforce/tasks/{$id}/checkin", [
            'lat' => -6.2, 'lng' => 106.8, 'idempotency_key' => 'api-key-1',
        ], $headers)->assertCreated()->assertJsonPath('data.id', $visitId);

        $this->actingAs($owner)->postJson("/api/v1/fieldforce/visits/{$visitId}/checkout", ['lat' => -6.2, 'lng' => 106.81], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/fieldforce/tasks/{$id}/transition", ['action' => 'complete'], $headers)->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/fieldforce/tasks', ['title' => 'Web Visit'])->assertRedirect();
        $task = FfTask::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post("/fieldforce/tasks/{$task->id}/transition", ['action' => 'en_route'])->assertRedirect();
        $this->actingAs($owner)->post("/fieldforce/tasks/{$task->id}/checkin", ['lat' => -6.2, 'lng' => 106.8])->assertRedirect();
        $visit = FfVisit::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($owner)->post("/fieldforce/visits/{$visit->id}/checkout", ['lat' => -6.2, 'lng' => 106.8])->assertRedirect();
        $this->actingAs($owner)->post("/fieldforce/tasks/{$task->id}/transition", ['action' => 'complete'])->assertRedirect();
        $this->assertSame('completed', $task->refresh()->status);
        $this->actingAs($owner)->get('/fieldforce')->assertOk()->assertSeeText('Web Visit');
    }
}
