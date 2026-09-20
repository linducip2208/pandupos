<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FfTask;
use App\Models\FfVisit;
use App\Services\FieldForceService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class FieldForceController extends Controller
{
    public function tasks()
    {
        $this->authorize('viewAny', FfTask::class);

        return response()->json(['data' => FfTask::query()->with('visits')->orderByDesc('id')->limit(100)->get()]);
    }

    public function storeTask(Request $request, FieldForceService $field)
    {
        $this->authorize('create', FfTask::class);
        $data = $request->validate([
            'title' => 'required|string|max:200', 'description' => 'nullable|string',
            'assignee_id' => 'nullable|integer', 'address' => 'nullable|string|max:255',
            'due_on' => 'nullable|date',
        ]);

        return response()->json(['data' => $field->createTask(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function checkIn(Request $request, FieldForceService $field, int $task)
    {
        $model = FfTask::query()->findOrFail($task);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'lat' => 'required|numeric', 'lng' => 'required|numeric',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        return response()->json(['data' => $field->checkIn($model, $request->user()->id, (float) $data['lat'], (float) $data['lng'], $data['idempotency_key'] ?? null, $request->user()->id)], 201);
    }

    public function checkOut(Request $request, FieldForceService $field, int $visit)
    {
        $model = FfVisit::query()->findOrFail($visit);
        $this->authorize('manage', $model->task);
        $data = $request->validate([
            'lat' => 'required|numeric', 'lng' => 'required|numeric',
            'notes' => 'nullable|string', 'photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        return response()->json(['data' => $field->checkOut($model, (float) $data['lat'], (float) $data['lng'], $data['notes'] ?? null, $request->file('photo'), $request->user()->id)]);
    }

    public function transition(Request $request, FieldForceService $field, int $task)
    {
        $model = FfTask::query()->findOrFail($task);
        $this->authorize('manage', $model);
        $data = $request->validate(['action' => 'required|in:en_route,complete,cancel']);
        $result = match ($data['action']) {
            'en_route' => $field->markEnRoute($model, $request->user()->id),
            'complete' => $field->completeTask($model, $request->user()->id),
            'cancel' => $field->cancelTask($model, $request->user()->id),
        };

        return response()->json(['data' => $result]);
    }
}
