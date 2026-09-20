<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Services\ProjectService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ProjectWorkspaceController extends Controller
{
    public function index(ProjectService $projects)
    {
        $this->authorize('viewAny', Project::class);
        $list = Project::query()->with(['manager', 'tasks'])->orderByDesc('id')->limit(100)->get();
        $profit = [];
        foreach ($list as $project) {
            $profit[$project->id] = $projects->profitability($project);
        }

        return view('project.index', ['projects' => $list, 'profit' => $profit]);
    }

    public function store(Request $request, ProjectService $projects)
    {
        $this->authorize('create', Project::class);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'code' => 'nullable|string|max:32',
            'budget' => 'required|numeric|min:0', 'hourly_rate' => 'nullable|numeric|min:0',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date',
        ]);
        $projects->createProject(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Proyek '.$data['name'].' dibuat.');
    }

    public function transition(Request $request, ProjectService $projects, int $project)
    {
        $model = Project::query()->findOrFail($project);
        $this->authorize('manage', $model);
        $data = $request->validate(['to' => 'required|string']);
        $projects->transitionProject($model, $data['to'], $request->user()->id);

        return back()->with('status', 'Proyek pindah ke '.$data['to'].'.');
    }

    public function storeTask(Request $request, ProjectService $projects, int $project)
    {
        $model = Project::query()->findOrFail($project);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'title' => 'required|string|max:200', 'assignee_id' => 'nullable|integer',
            'estimate_hours' => 'nullable|numeric|min:0', 'due_on' => 'nullable|date',
        ]);
        $projects->createTask(TenantContext::idOrFail(), $model->id, $data, $request->user()->id);

        return back()->with('status', 'Tugas ditambahkan.');
    }

    public function advanceTask(Request $request, ProjectService $projects, int $task)
    {
        $model = ProjectTask::query()->findOrFail($task);
        $this->authorize('manage', $model->project);
        $data = $request->validate(['to' => 'required|string']);
        $projects->advanceTask($model, $data['to'], $request->user()->id);

        return back()->with('status', 'Tugas pindah ke '.$data['to'].'.');
    }

    public function storeMilestone(Request $request, ProjectService $projects, int $project)
    {
        $model = Project::query()->findOrFail($project);
        $this->authorize('manage', $model);
        $data = $request->validate(['title' => 'required|string|max:200', 'due_on' => 'nullable|date']);
        $projects->createMilestone(TenantContext::idOrFail(), $model->id, $data, $request->user()->id);

        return back()->with('status', 'Milestone ditambahkan.');
    }

    public function completeMilestone(Request $request, ProjectService $projects, int $milestone)
    {
        $model = ProjectMilestone::query()->findOrFail($milestone);
        $this->authorize('manage', $model->project);
        $projects->completeMilestone($model, $request->user()->id);

        return back()->with('status', 'Milestone selesai.');
    }

    public function logTime(Request $request, ProjectService $projects, int $project)
    {
        $model = Project::query()->findOrFail($project);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'hours' => 'required|numeric|gt:0|lte:24', 'worked_on' => 'nullable|date',
            'task_id' => 'nullable|integer', 'notes' => 'nullable|string',
        ]);
        $projects->logTime(TenantContext::idOrFail(), $model->id, $request->user()->id, $data, $request->user()->id);

        return back()->with('status', 'Jam kerja dicatat.');
    }

    public function addExpense(Request $request, ProjectService $projects, int $project)
    {
        $model = Project::query()->findOrFail($project);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'description' => 'required|string|max:200', 'category' => 'nullable|string|max:64',
            'amount' => 'required|numeric|gt:0', 'spent_on' => 'nullable|date',
        ]);
        $projects->addExpense(TenantContext::idOrFail(), $model->id, $data, $request->user()->id);

        return back()->with('status', 'Biaya dicatat.');
    }
}
