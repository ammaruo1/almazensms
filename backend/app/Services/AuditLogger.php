<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Str;

class AuditLogger
{
    public function record(?int $userId, ?int $projectId, string $action, string $subjectType, ?string $subjectId = null, array $metadata = []): void
    {
        AuditLog::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'project_id' => $projectId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
