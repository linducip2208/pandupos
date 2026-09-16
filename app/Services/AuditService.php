<?php

namespace App\Services;

use App\Models\AuditLog;

/** Never store passwords/secrets. */
final class AuditService
{
    public function log(?int $tenantId, ?int $actorId, string $action, ?string $subjectType = null, ?int $subjectId = null, ?array $before = null, ?array $after = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 500),
            'request_id' => $request?->header('X-Request-ID'),
        ]);
    }
}
