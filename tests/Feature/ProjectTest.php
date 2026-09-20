<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ModuleManager;
use App\Services\ProjectService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Project module: guarded lifecycles, tasks, milestones, timesheets with
 * daily cap, expenses, profitability, isolation, RBAC and API.
 */
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Project', bool $enableProject = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableProject) {
            app(ModuleManager::class)->enable($tenant->id, 'project');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko Project Gate', false);

        $this->actingAs($owner)->get('/projects')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/projects', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'project');
        $this->actingAs($owner)->get('/projects')->assertOk()->assertSeeText('Proyek');
    }

    public function test_project_lifecycle_requires_done_tasks_for_completion(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(ProjectService::class);

        try {
            $svc->transitionProject($svc->createProject($tenant->id, ['name' => 'X'], $owner->id), 'completed', $owner->id);
            $this->fail('Skipping active must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $project = $svc->createProject($tenant->id, ['name' => 'Ruko', 'budget' => 10000000, 'hourly_rate' => 50000], $owner->id);
        $task = $svc->createTask($tenant->id, $project->id, ['title' => 'Pondasi'], $owner->id);
        $svc->transitionProject($project, 'active', $owner->id);
        try {
            $svc->transitionProject($project->refresh(), 'completed', $owner->id);
            $this->fail('Completion with open tasks must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->advanceTask($task, 'doing', $owner->id);
        $svc->advanceTask($task->refresh(), 'review', $owner->id);
        $svc->advanceTask($task->refresh(), 'done', $owner->id);
        $this->assertSame('completed', $svc->transitionProject($project->refresh(), 'completed', $owner->id)->status);
    }

    public function test_task_machine_rejects_skips(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(ProjectService::class);
        $project = $svc->createProject($tenant->id, ['name' => 'T'], $owner->id);
        $task = $svc->createTask($tenant->id, $project->id, ['title' => 'T1'], $owner->id);

        try {
            $svc->advanceTask($task, 'done', $owner->id);
            $this->fail('Skipping review must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_timesheet_caps_daily_hours_and_profitability(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(ProjectService::class);
        $project = $svc->createProject($tenant->id, ['name' => 'Profit', 'budget' => 1000000, 'hourly_rate' => 100000], $owner->id);

        $svc->logTime($tenant->id, $project->id, $owner->id, ['hours' => 8, 'worked_on' => '2026-09-01'], $owner->id);
        try {
            $svc->logTime($tenant->id, $project->id, $owner->id, ['hours' => 17, 'worked_on' => '2026-09-01'], $owner->id);
            $this->fail('Over 24h/day must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $svc->addExpense($tenant->id, $project->id, ['description' => 'Material', 'amount' => 100000], $owner->id);

        $profit = $svc->profitability($project);
        $this->assertSame(8.0, $profit['labor_hours']);
        $this->assertSame(800000.0, $profit['labor_cost']);
        $this->assertSame(100000.0, $profit['expenses']);
        $this->assertSame(900000.0, $profit['total_cost']);
        $this->assertSame(100000.0, $profit['remaining']);
    }

    public function test_milestones_complete_once(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(ProjectService::class);
        $project = $svc->createProject($tenant->id, ['name' => 'Mile'], $owner->id);
        $ms = $svc->createMilestone($tenant->id, $project->id, ['title' => 'Serah terima'], $owner->id);
        $svc->completeMilestone($ms, $owner->id);
        try {
            $svc->completeMilestone($ms->refresh(), $owner->id);
            $this->fail('Double completion must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko Project A');
        [$tenantB, $ownerB] = $this->context('Toko Project B');
        $projectA = app(ProjectService::class)->createProject($tenantA->id, ['name' => 'Rahasia A'], null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, Project::query()->count());
        $this->actingAs($ownerB)->get('/projects')->assertOk()->assertDontSee('Rahasia A');
        $this->actingAs($ownerB)->post("/projects/{$projectA->id}/transition", ['to' => 'active'])->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/projects')->assertForbidden();
        $member->givePermissionTo('project.view');
        $this->actingAs($member)->get('/projects')->assertOk();
        $this->actingAs($member)->post('/projects', ['name' => 'X'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];

        $created = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'API Proj', 'budget' => 500000], $headers)->assertCreated();
        $id = $created->json('data.id');

        $task = $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/tasks", ['title' => 'API Task'], $headers)->assertCreated();
        $taskId = $task->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/projects/tasks/{$taskId}/advance", ['to' => 'doing'], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/time", ['hours' => 4], $headers)->assertCreated();
        $this->actingAs($owner)->postJson("/api/v1/projects/{$id}/transition", ['to' => 'active'], $headers)->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($owner)->getJson('/api/v1/projects', $headers)->assertOk()->assertJsonPath('data.0.profitability.labor_hours', 4);
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/projects', ['name' => 'Web Proj', 'budget' => 100000])->assertRedirect();
        $project = Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post("/projects/{$project->id}/tasks", ['title' => 'Web Task'])->assertRedirect();
        $task = ProjectTask::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($owner)->post("/projects/tasks/{$task->id}/advance", ['to' => 'doing'])->assertRedirect();
        $this->actingAs($owner)->post("/projects/tasks/{$task->id}/advance", ['to' => 'review'])->assertRedirect();
        $this->actingAs($owner)->post("/projects/tasks/{$task->id}/advance", ['to' => 'done'])->assertRedirect();
        $this->actingAs($owner)->post("/projects/{$project->id}/time", ['hours' => 2])->assertRedirect();
        $this->actingAs($owner)->post("/projects/{$project->id}/expenses", ['description' => 'Web cost', 'amount' => 5000])->assertRedirect();
        $this->actingAs($owner)->post("/projects/{$project->id}/transition", ['to' => 'active'])->assertRedirect();
        $this->actingAs($owner)->post("/projects/{$project->id}/transition", ['to' => 'completed'])->assertRedirect();
        $this->assertSame('completed', $project->refresh()->status);
        $this->actingAs($owner)->get('/projects')->assertOk()->assertSeeText('Web Proj');
    }
}
