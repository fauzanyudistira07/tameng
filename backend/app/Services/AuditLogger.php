<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public function record(Request $request, string $action, string $result, array $context = []): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => $context['user_id'] ?? $request->user()?->id,
            'project_id' => $context['project_id'] ?? null,
            'authorization_id' => $context['authorization_id'] ?? null,
            'scan_job_id' => $context['scan_job_id'] ?? null,
            'action' => $action,
            'result' => $result,
            'actor_ip' => $request->ip(),
            'target_type' => $context['target_type'] ?? null,
            'target_id' => isset($context['target_id']) ? (string) $context['target_id'] : null,
            'metadata' => $context['metadata'] ?? null,
        ]);
    }

    public function recordSystem(string $action, string $result, array $context = []): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => $context['user_id'] ?? null,
            'project_id' => $context['project_id'] ?? null,
            'authorization_id' => $context['authorization_id'] ?? null,
            'scan_job_id' => $context['scan_job_id'] ?? null,
            'action' => $action,
            'result' => $result,
            'actor_ip' => null,
            'target_type' => $context['target_type'] ?? null,
            'target_id' => isset($context['target_id']) ? (string) $context['target_id'] : null,
            'metadata' => $context['metadata'] ?? null,
        ]);
    }
}
