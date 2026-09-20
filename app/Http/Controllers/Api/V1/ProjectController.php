<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(ProjectService $projects)
    {
        $this->authorize('viewAny', Project::class);
        $list = Project::query()->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $list->map(fn ($p) => array_merge($p->toArray(), ['profitability' => $projects->profitability($p)]))]);
    }

    public function store(Request $request, ProjectService $projects)
    {
        $this->authorize('create', Project::class);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'code' => 'nullable|string|max:32',
            'budget' => 'required|numeric|min:0', 'hourly_rate' => 'nullable|numeric|min:0',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date',
        ]);

        return response()->json(['data' => $projects->createProject(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function transition(Request $request, ProjectService $projects, int $project)
    {
        $model = Project::query()->findOrFail($project);
        $this->authorize('manage', $model);
        $data = $request->validate(['to' => 'required|string']);

        return response()->json(['data' => $projects->transitionProject($model, $data['to'], $request->user()->id)]);
    }

    public function storeTask(Request $request, ProjectService $projects, int $project)
    {
        $model = Project::query()->findOrFail($project);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'title' => 'required|string|max:200', 'assignee_id' => 'nullable|integer',
            'estimate_hours' => 'nullable|numeric|min:0', 'due_on' => 'nullable|date',
        ]);

        return response()->json(['data' => $projects->createTask(TenantContext::idOrFail(), $model->id, $data, $request->user()->id)], 201);
    }

    public function advanceTask(Request $request, ProjectService $projects, int $task)
    {
        $model = ProjectTask::query()->findOrFail($task);
        $this->authorize('manage', $model->project);
        $data = $request->validate(['to' => 'required|string']);

        return response()->json(['data' => $projects->advanceTask($model, $data['to'], $request->user()->id)]);
    }

    public function logTime(Request $request, ProjectService $projects, int $project)
    {
        $model = Project::query()->findOrFail($project);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'hours' => 'required|numeric|gt:0|lte:24', 'worked_on' => 'nullable|date',
            'task_id' => 'nullable|integer', 'notes' => 'nullable|string',
        ]);

        return response()->json(['data' => $projects->logTime(TenantContext::idOrFail(), $model->id, $request->user()->id, $data, $request->user()->id)], 201);
    }
}
