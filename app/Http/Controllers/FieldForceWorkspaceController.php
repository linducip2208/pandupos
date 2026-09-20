<?php

namespace App\Http\Controllers;

use App\Models\FfTask;
use App\Models\FfVisit;
use App\Models\User;
use App\Services\FieldForceService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class FieldForceWorkspaceController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', FfTask::class);

        return view('fieldforce.index', [
            'tasks' => FfTask::query()->with(['assignee', 'visits'])->orderByDesc('id')->limit(100)->get(),
            'users' => User::query()->orderBy('name')->limit(200)->get(),
        ]);
    }

    public function store(Request $request, FieldForceService $field)
    {
        $this->authorize('create', FfTask::class);
        $data = $request->validate([
            'title' => 'required|string|max:200', 'description' => 'nullable|string',
            'assignee_id' => 'nullable|integer', 'address' => 'nullable|string|max:255',
            'due_on' => 'nullable|date',
        ]);
        $field->createTask(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Tugas lapangan dibuat.');
    }

    public function transition(Request $request, FieldForceService $field, int $task)
    {
        $model = FfTask::query()->findOrFail($task);
        $this->authorize('manage', $model);
        $data = $request->validate(['action' => 'required|in:en_route,complete,cancel']);
        match ($data['action']) {
            'en_route' => $field->markEnRoute($model, $request->user()->id),
            'complete' => $field->completeTask($model, $request->user()->id),
            'cancel' => $field->cancelTask($model, $request->user()->id),
        };

        return back()->with('status', 'Tugas diperbarui.');
    }

    public function checkIn(Request $request, FieldForceService $field, int $task)
    {
        $model = FfTask::query()->findOrFail($task);
        $this->authorize('manage', $model);
        $data = $request->validate(['lat' => 'required|numeric', 'lng' => 'required|numeric']);
        $field->checkIn($model, $request->user()->id, (float) $data['lat'], (float) $data['lng'], null, $request->user()->id);

        return back()->with('status', 'Check-in tercatat.');
    }

    public function checkOut(Request $request, FieldForceService $field, int $visit)
    {
        $model = FfVisit::query()->findOrFail($visit);
        $this->authorize('manage', $model->task);
        $data = $request->validate([
            'lat' => 'required|numeric', 'lng' => 'required|numeric',
            'notes' => 'nullable|string', 'photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        $field->checkOut($model, (float) $data['lat'], (float) $data['lng'], $data['notes'] ?? null, $request->file('photo'), $request->user()->id);

        return back()->with('status', 'Check-out tercatat.');
    }
}
