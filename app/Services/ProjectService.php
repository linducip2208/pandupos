<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\ProjectTimesheet;
use App\Models\User;

/**
 * Projects: guarded project/task lifecycles, milestones, timesheets with a
 * daily cap, expenses, and budget-vs-actual profitability.
 */
final class ProjectService
{
    public function __construct(private AuditService $audit) {}

    public function createProject(int $tenantId, array $data, ?int $actorId = null): Project
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Project name is required.');
        $budget = round((float) ($data['budget'] ?? 0), 2);
        abort_if($budget < 0, 422, 'Budget cannot be negative.');
        $rate = round((float) ($data['hourly_rate'] ?? 0), 2);
        abort_if($rate < 0, 422, 'Hourly rate cannot be negative.');
        $contactId = isset($data['contact_id']) ? (int) $data['contact_id'] : null;
        if ($contactId) {
            abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($contactId)->exists(), 422, 'Contact does not belong to tenant.');
        }
        if (! empty($data['starts_on']) && ! empty($data['ends_on'])) {
            abort_if($data['ends_on'] < $data['starts_on'], 422, 'Project end must not precede start.');
        }

        $project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'name' => $name, 'code' => $data['code'] ?? null,
            'contact_id' => $contactId, 'status' => Project::PLANNED,
            'budget' => $budget, 'hourly_rate' => $rate,
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null,
            'manager_id' => $data['manager_id'] ?? $actorId, 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'project.created', Project::class, $project->id, null, ['name' => $name]);

        return $project;
    }

    public function transitionProject(Project $project, string $to, ?int $actorId = null): Project
    {
        $allowed = [
            Project::PLANNED => [Project::ACTIVE, Project::CANCELLED],
            Project::ACTIVE => [Project::ON_HOLD, Project::COMPLETED, Project::CANCELLED],
            Project::ON_HOLD => [Project::ACTIVE, Project::CANCELLED],
            Project::COMPLETED => [],
            Project::CANCELLED => [],
        ];
        abort_unless(in_array($to, [Project::PLANNED, Project::ACTIVE, Project::ON_HOLD, Project::COMPLETED, Project::CANCELLED], true), 422, 'Invalid project status.');
        abort_unless(in_array($to, $allowed[$project->status] ?? [], true), 422, "Project cannot move from [{$project->status}] to [{$to}].");
        if ($to === Project::COMPLETED) {
            $open = ProjectTask::withoutGlobalScopes()->where('tenant_id', $project->tenant_id)->where('project_id', $project->id)->where('status', '!=', ProjectTask::DONE)->exists();
            abort_if($open, 422, 'All tasks must be done before completing the project.');
        }
        $before = $project->toArray();
        $project->update(['status' => $to]);
        $this->audit->log($project->tenant_id, $actorId, 'project.transitioned', Project::class, $project->id, $before, $project->fresh()->toArray());

        return $project->fresh();
    }

    public function createTask(int $tenantId, int $projectId, array $data, ?int $actorId = null): ProjectTask
    {
        $project = Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($projectId);
        abort_unless(in_array($project->status, [Project::PLANNED, Project::ACTIVE, Project::ON_HOLD], true), 422, 'Tasks cannot be added to a finished project.');
        $title = trim((string) ($data['title'] ?? ''));
        abort_if($title === '', 422, 'Task title is required.');
        $assigneeId = isset($data['assignee_id']) ? (int) $data['assignee_id'] : null;
        if ($assigneeId) {
            abort_unless(User::withoutGlobalScopes()->whereKey($assigneeId)->exists(), 422, 'Assignee not found.');
        }

        $task = ProjectTask::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'project_id' => $project->id, 'title' => $title,
            'description' => $data['description'] ?? null, 'assignee_id' => $assigneeId,
            'status' => ProjectTask::TODO, 'estimate_hours' => round((float) ($data['estimate_hours'] ?? 0), 2),
            'due_on' => $data['due_on'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'project.task.created', ProjectTask::class, $task->id, null, ['title' => $title]);

        return $task;
    }

    public function advanceTask(ProjectTask $task, string $to, ?int $actorId = null): ProjectTask
    {
        $allowed = [
            ProjectTask::TODO => [ProjectTask::DOING],
            ProjectTask::DOING => [ProjectTask::REVIEW, ProjectTask::TODO],
            ProjectTask::REVIEW => [ProjectTask::DONE, ProjectTask::DOING],
            ProjectTask::DONE => [ProjectTask::DOING],
        ];
        abort_unless(in_array($to, [ProjectTask::TODO, ProjectTask::DOING, ProjectTask::REVIEW, ProjectTask::DONE], true), 422, 'Invalid task status.');
        abort_unless(in_array($to, $allowed[$task->status] ?? [], true), 422, "Task cannot move from [{$task->status}] to [{$to}].");
        $before = $task->toArray();
        $task->update(['status' => $to]);
        $this->audit->log($task->tenant_id, $actorId, 'project.task.advanced', ProjectTask::class, $task->id, $before, $task->fresh()->toArray());

        return $task->fresh();
    }

    public function createMilestone(int $tenantId, int $projectId, array $data, ?int $actorId = null): ProjectMilestone
    {
        Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($projectId);
        $title = trim((string) ($data['title'] ?? ''));
        abort_if($title === '', 422, 'Milestone title is required.');

        return ProjectMilestone::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'project_id' => $projectId, 'title' => $title,
            'due_on' => $data['due_on'] ?? null,
        ]);
    }

    public function completeMilestone(ProjectMilestone $milestone, ?int $actorId = null): ProjectMilestone
    {
        abort_if($milestone->done_at !== null, 422, 'Milestone is already completed.');
        $before = $milestone->toArray();
        $milestone->update(['done_at' => now()]);
        $this->audit->log($milestone->tenant_id, $actorId, 'project.milestone.completed', ProjectMilestone::class, $milestone->id, $before, $milestone->fresh()->toArray());

        return $milestone->fresh();
    }

    public function logTime(int $tenantId, int $projectId, int $userId, array $data, ?int $actorId = null): ProjectTimesheet
    {
        Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($projectId);
        abort_unless(User::withoutGlobalScopes()->whereKey($userId)->exists(), 422, 'User not found.');
        $hours = round((float) ($data['hours'] ?? 0), 2);
        abort_if($hours <= 0 || $hours > 24, 422, 'Logged hours must be between 0 and 24.');
        $workedOn = $data['worked_on'] ?? now()->toDateString();
        $dayTotal = (float) ProjectTimesheet::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereDate('worked_on', $workedOn)->sum('hours');
        abort_if($dayTotal + $hours > 24, 422, 'A user cannot log more than 24 hours per day.');
        $taskId = isset($data['task_id']) ? (int) $data['task_id'] : null;
        if ($taskId) {
            abort_unless(ProjectTask::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->whereKey($taskId)->exists(), 422, 'Task does not belong to this project.');
        }

        $sheet = ProjectTimesheet::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'project_id' => $projectId, 'task_id' => $taskId,
            'user_id' => $userId, 'worked_on' => $workedOn, 'hours' => $hours, 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'project.time.logged', ProjectTimesheet::class, $sheet->id, null, ['hours' => $hours]);

        return $sheet;
    }

    public function addExpense(int $tenantId, int $projectId, array $data, ?int $actorId = null): ProjectExpense
    {
        Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($projectId);
        $description = trim((string) ($data['description'] ?? ''));
        abort_if($description === '', 422, 'Expense description is required.');
        $amount = round((float) ($data['amount'] ?? 0), 2);
        abort_if($amount <= 0, 422, 'Expense amount must be positive.');

        $expense = ProjectExpense::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'project_id' => $projectId, 'description' => $description,
            'category' => $data['category'] ?? null, 'amount' => $amount,
            'spent_on' => $data['spent_on'] ?? now()->toDateString(), 'created_by' => $actorId,
        ]);
        $this->audit->log($tenantId, $actorId, 'project.expense.added', ProjectExpense::class, $expense->id, null, ['amount' => $amount]);

        return $expense;
    }

    /** @return array{budget:float,labor_hours:float,labor_cost:float,expenses:float,total_cost:float,remaining:float} */
    public function profitability(Project $project): array
    {
        $project = $project->fresh();
        $hours = round((float) ProjectTimesheet::withoutGlobalScopes()->where('tenant_id', $project->tenant_id)->where('project_id', $project->id)->sum('hours'), 2);
        $labor = round($hours * (float) $project->hourly_rate, 2);
        $expenses = round((float) ProjectExpense::withoutGlobalScopes()->where('tenant_id', $project->tenant_id)->where('project_id', $project->id)->sum('amount'), 2);
        $total = round($labor + $expenses, 2);

        return [
            'budget' => (float) $project->budget, 'labor_hours' => $hours, 'labor_cost' => $labor,
            'expenses' => $expenses, 'total_cost' => $total,
            'remaining' => round((float) $project->budget - $total, 2),
        ];
    }
}
