<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\FfTask;
use App\Models\FfVisit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Field force: tasks with a guarded field lifecycle, GPS check-in/out with
 * Haversine distance, photo evidence on the private disk, and idempotency
 * keys so offline retries never duplicate visits.
 */
final class FieldForceService
{
    public function __construct(private AuditService $audit) {}

    public function createTask(int $tenantId, array $data, ?int $actorId = null): FfTask
    {
        $title = trim((string) ($data['title'] ?? ''));
        abort_if($title === '', 422, 'Task title is required.');
        $assigneeId = isset($data['assignee_id']) ? (int) $data['assignee_id'] : null;
        if ($assigneeId) {
            abort_unless(User::withoutGlobalScopes()->whereKey($assigneeId)->exists(), 422, 'Assignee not found.');
        }
        $contactId = isset($data['contact_id']) ? (int) $data['contact_id'] : null;
        if ($contactId) {
            abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($contactId)->exists(), 422, 'Contact does not belong to tenant.');
        }

        $task = FfTask::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'title' => $title, 'description' => $data['description'] ?? null,
            'assignee_id' => $assigneeId, 'contact_id' => $contactId, 'address' => $data['address'] ?? null,
            'planned_lat' => $this->coordinate($data['planned_lat'] ?? null, 'planned latitude'),
            'planned_lng' => $this->coordinate($data['planned_lng'] ?? null, 'planned longitude', false),
            'status' => FfTask::ASSIGNED, 'due_on' => $data['due_on'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'fieldforce.task.created', FfTask::class, $task->id, null, ['title' => $title]);

        return $task;
    }

    public function markEnRoute(FfTask $task, ?int $actorId = null): FfTask
    {
        abort_if($task->status !== FfTask::ASSIGNED, 422, 'Only assigned tasks can go en route.');
        $before = $task->toArray();
        $task->update(['status' => FfTask::EN_ROUTE]);
        $this->audit->log($task->tenant_id, $actorId, 'fieldforce.task.en_route', FfTask::class, $task->id, $before, $task->fresh()->toArray());

        return $task->fresh();
    }

    /**
     * GPS check-in. The idempotency key makes offline retries safe: a replay
     * returns the original visit without re-running the status machine, and a
     * unique index wins any insert race (loser re-reads the winner).
     */
    public function checkIn(FfTask $task, int $userId, float $lat, float $lng, ?string $idempotencyKey = null, ?int $actorId = null): FfVisit
    {
        $key = $idempotencyKey ? substr(trim($idempotencyKey), 0, 64) : null;
        if ($key) {
            $existing = FfVisit::withoutGlobalScopes()->where('tenant_id', $task->tenant_id)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
        }
        try {
            return DB::transaction(function () use ($task, $userId, $lat, $lng, $key, $actorId) {
                $locked = FfTask::withoutGlobalScopes()->lockForUpdate()->findOrFail($task->id);
                abort_unless(in_array($locked->status, [FfTask::ASSIGNED, FfTask::EN_ROUTE], true), 422, 'Check-in is only possible on open tasks.');
                if ($locked->assignee_id) {
                    abort_unless($locked->assignee_id === $userId, 403, 'Only the assignee may check in.');
                }
                abort_unless(User::withoutGlobalScopes()->whereKey($userId)->exists(), 422, 'User not found.');
                if ($key) {
                    $existing = FfVisit::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->where('idempotency_key', $key)->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                $visit = FfVisit::withoutGlobalScopes()->create([
                    'tenant_id' => $locked->tenant_id, 'task_id' => $locked->id, 'user_id' => $userId,
                    'check_in_at' => now(),
                    'check_in_lat' => $this->coordinate($lat, 'latitude'),
                    'check_in_lng' => $this->coordinate($lng, 'longitude', false),
                    'idempotency_key' => $key,
                ]);
                $locked->update(['status' => FfTask::CHECKED_IN]);
                $this->audit->log($locked->tenant_id, $actorId, 'fieldforce.visit.checkin', FfVisit::class, $visit->id, null, ['task_id' => $locked->id]);

                return $visit;
            });
        } catch (QueryException $e) {
            if ($key && in_array($e->getCode(), ['23000', '23000 ', 23000], true)) {
                $existing = FfVisit::withoutGlobalScopes()->where('tenant_id', $task->tenant_id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    public function checkOut(FfVisit $visit, float $lat, float $lng, ?string $notes = null, ?UploadedFile $photo = null, ?int $actorId = null): FfVisit
    {
        return DB::transaction(function () use ($visit, $lat, $lng, $notes, $photo, $actorId) {
            $locked = FfVisit::withoutGlobalScopes()->lockForUpdate()->findOrFail($visit->id);
            abort_if($locked->check_out_at !== null, 422, 'Visit is already checked out.');
            $lat = $this->coordinate($lat, 'latitude');
            $lng = $this->coordinate($lng, 'longitude', false);
            $distance = (int) round($this->haversine((float) $locked->check_in_lat, (float) $locked->check_in_lng, $lat, $lng));
            $photoPath = $locked->evidence_photo;
            if ($photo) {
                $this->validatePhoto($photo);
                // Default local disk roots at app/private: never public.
                $photoPath = $photo->store('field-evidence/'.$locked->tenant_id);
            }
            $before = $locked->toArray();
            $locked->update([
                'check_out_at' => now(), 'check_out_lat' => $lat, 'check_out_lng' => $lng,
                'distance_m' => $distance, 'notes' => $notes ?? $locked->notes, 'evidence_photo' => $photoPath,
            ]);
            $this->audit->log($locked->tenant_id, $actorId, 'fieldforce.visit.checkout', FfVisit::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function completeTask(FfTask $task, ?int $actorId = null): FfTask
    {
        $locked = FfTask::withoutGlobalScopes()->lockForUpdate()->findOrFail($task->id);
        abort_if($locked->status !== FfTask::CHECKED_IN, 422, 'Only checked-in tasks can be completed.');
        abort_unless(FfVisit::withoutGlobalScopes()->where('task_id', $locked->id)->whereNotNull('check_out_at')->exists(), 422, 'A checked-out visit is required before completion.');
        $before = $locked->toArray();
        $locked->update(['status' => FfTask::COMPLETED]);
        $this->audit->log($locked->tenant_id, $actorId, 'fieldforce.task.completed', FfTask::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function cancelTask(FfTask $task, ?int $actorId = null): FfTask
    {
        abort_unless(in_array($task->status, [FfTask::ASSIGNED, FfTask::EN_ROUTE], true), 422, 'Only open tasks can be cancelled.');
        $before = $task->toArray();
        $task->update(['status' => FfTask::CANCELLED]);
        $this->audit->log($task->tenant_id, $actorId, 'fieldforce.task.cancelled', FfTask::class, $task->id, $before, $task->fresh()->toArray());

        return $task->fresh();
    }

    private function coordinate(mixed $value, string $label, bool $latitude = true): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        abort_unless(is_numeric($value), 422, "Invalid {$label}.");
        $value = round((float) $value, 7);
        $max = $latitude ? 90 : 180;
        abort_if(abs($value) > $max, 422, "Invalid {$label}.");

        return $value;
    }

    private function validatePhoto(UploadedFile $photo): void
    {
        abort_unless(in_array($photo->getClientOriginalExtension(), ['jpg', 'jpeg', 'png', 'webp'], true), 422, 'Evidence must be an image.');
        abort_unless(str_starts_with((string) $photo->getMimeType(), 'image/'), 422, 'Evidence MIME must be an image.');
        abort_if($photo->getSize() > 5 * 1024 * 1024, 422, 'Evidence may not exceed 5 MB.');
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }
}
