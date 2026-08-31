<?php

namespace App\Services;

use App\Models\PolicyDecision;
use App\Models\ScanJob;
use Illuminate\Support\Carbon;

class ExecutionGateway
{
    public function decide(ScanJob $scanJob, string $engineKey): array
    {
        $scanJob->loadMissing(['authorization', 'repository', 'target', 'scanProfile']);
        $authorization = $scanJob->authorization;

        $requestSnapshot = [
            'scan_job_id' => $scanJob->id,
            'authorization_id' => $scanJob->authorization_id,
            'engine_key' => $engineKey,
            'project_id' => $scanJob->project_id,
            'repository_id' => $scanJob->repository_id,
            'target_id' => $scanJob->target_id,
            'scan_profile_id' => $scanJob->scan_profile_id,
            'requested_at' => now()->toISOString(),
        ];

        $policySnapshot = [
            'scan_job' => $scanJob->only(['id', 'code', 'status']),
            'authorization' => $authorization?->only(['id', 'code', 'status', 'valid_from', 'valid_until']),
            'allowed_engines' => $authorization?->allowed_engines,
            'execution_policy_snapshot' => $scanJob->execution_policy_snapshot,
        ];

        if (! $authorization) {
            return $this->deny('AUTHORIZATION_NOT_FOUND', $requestSnapshot, $policySnapshot, $scanJob);
        }

        if (! in_array($scanJob->status, ['queued', 'running'], true)) {
            return $this->deny('SCAN_JOB_NOT_EXECUTABLE', $requestSnapshot, $policySnapshot, $scanJob);
        }

        if ($authorization->status !== 'active') {
            return $this->deny('AUTHORIZATION_NOT_ACTIVE', $requestSnapshot, $policySnapshot, $scanJob);
        }

        if (Carbon::parse($authorization->valid_from)->isFuture() || Carbon::parse($authorization->valid_until)->isPast()) {
            return $this->deny('AUTHORIZATION_NOT_IN_VALID_WINDOW', $requestSnapshot, $policySnapshot, $scanJob);
        }

        if (! in_array($engineKey, $authorization->allowed_engines ?? [], true)) {
            return $this->deny('ENGINE_NOT_ALLOWED', $requestSnapshot, $policySnapshot, $scanJob);
        }

        if ($scanJob->repository_id && $scanJob->repository?->verification_status !== 'verified') {
            return $this->deny('REPOSITORY_NOT_VERIFIED', $requestSnapshot, $policySnapshot, $scanJob);
        }

        if ($scanJob->target_id && $scanJob->target?->verification_status !== 'verified') {
            return $this->deny('TARGET_NOT_VERIFIED', $requestSnapshot, $policySnapshot, $scanJob);
        }

        if (! $authorization->allowed_scope_snapshot || count($authorization->allowed_scope_snapshot) === 0) {
            return $this->deny('NO_ALLOWED_SCOPE_SNAPSHOT', $requestSnapshot, $policySnapshot, $scanJob);
        }

        return [
            'decision' => 'allow',
            'reason_code' => 'EXECUTION_AUTHORIZED',
            'policy_decision' => PolicyDecision::query()->create([
                'authorization_id' => $authorization->id,
                'scan_job_id' => $scanJob->id,
                'gateway' => 'execution',
                'decision' => 'allow',
                'reason_code' => 'EXECUTION_AUTHORIZED',
                'request_snapshot' => $requestSnapshot,
                'policy_snapshot' => $policySnapshot,
            ]),
        ];
    }

    private function deny(string $reasonCode, array $requestSnapshot, array $policySnapshot, ScanJob $scanJob): array
    {
        return [
            'decision' => 'deny',
            'reason_code' => $reasonCode,
            'policy_decision' => PolicyDecision::query()->create([
                'authorization_id' => $scanJob->authorization_id,
                'scan_job_id' => $scanJob->id,
                'gateway' => 'execution',
                'decision' => 'deny',
                'reason_code' => $reasonCode,
                'request_snapshot' => $requestSnapshot,
                'policy_snapshot' => $policySnapshot,
            ]),
        ];
    }
}
