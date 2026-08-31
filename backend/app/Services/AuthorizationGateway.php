<?php

namespace App\Services;

use App\Models\Authorization;
use App\Models\PolicyDecision;
use App\Models\Repository;
use App\Models\ScanProfile;
use App\Models\Target;
use Illuminate\Support\Carbon;

class AuthorizationGateway
{
    public function decide(array $requestSnapshot): array
    {
        $authorization = Authorization::query()
            ->with(['project', 'repository', 'target', 'scanProfile'])
            ->find($requestSnapshot['authorization_id'] ?? null);

        if (! $authorization) {
            return $this->deny('AUTHORIZATION_NOT_FOUND', $requestSnapshot);
        }

        $policySnapshot = [
            'authorization' => $authorization->only(['id', 'code', 'status', 'valid_from', 'valid_until']),
            'allowed_engines' => $authorization->allowed_engines,
            'allowed_scope_snapshot' => $authorization->allowed_scope_snapshot,
            'denied_scope_snapshot' => $authorization->denied_scope_snapshot,
            'policy_snapshot' => $authorization->policy_snapshot,
        ];

        if ($authorization->status !== 'active') {
            return $this->deny('AUTHORIZATION_NOT_ACTIVE', $requestSnapshot, $authorization, $policySnapshot);
        }

        if (Carbon::parse($authorization->valid_from)->isFuture() || Carbon::parse($authorization->valid_until)->isPast()) {
            return $this->deny('AUTHORIZATION_NOT_IN_VALID_WINDOW', $requestSnapshot, $authorization, $policySnapshot);
        }

        if ((int) $requestSnapshot['project_id'] !== (int) $authorization->project_id) {
            return $this->deny('PROJECT_MISMATCH', $requestSnapshot, $authorization, $policySnapshot);
        }

        if ((int) $requestSnapshot['scan_profile_id'] !== (int) $authorization->scan_profile_id) {
            return $this->deny('SCAN_PROFILE_MISMATCH', $requestSnapshot, $authorization, $policySnapshot);
        }

        if (($requestSnapshot['repository_id'] ?? null) && (int) $requestSnapshot['repository_id'] !== (int) $authorization->repository_id) {
            return $this->deny('REPOSITORY_MISMATCH', $requestSnapshot, $authorization, $policySnapshot);
        }

        if (($requestSnapshot['target_id'] ?? null) && (int) $requestSnapshot['target_id'] !== (int) $authorization->target_id) {
            return $this->deny('TARGET_MISMATCH', $requestSnapshot, $authorization, $policySnapshot);
        }

        if (! ($requestSnapshot['repository_id'] ?? null) && ! ($requestSnapshot['target_id'] ?? null)) {
            return $this->deny('NO_ASSET_SELECTED', $requestSnapshot, $authorization, $policySnapshot);
        }

        if (! $authorization->allowed_scope_snapshot || count($authorization->allowed_scope_snapshot) === 0) {
            return $this->deny('NO_ALLOWED_SCOPE_SNAPSHOT', $requestSnapshot, $authorization, $policySnapshot);
        }

        $profile = ScanProfile::query()->find($requestSnapshot['scan_profile_id']);

        if (! $profile || ! $profile->is_active) {
            return $this->deny('SCAN_PROFILE_NOT_ACTIVE', $requestSnapshot, $authorization, $policySnapshot);
        }

        if (($requestSnapshot['repository_id'] ?? null)) {
            $repository = Repository::query()->find($requestSnapshot['repository_id']);

            if (! $repository || $repository->verification_status !== 'verified') {
                return $this->deny('REPOSITORY_NOT_VERIFIED', $requestSnapshot, $authorization, $policySnapshot);
            }
        }

        if (($requestSnapshot['target_id'] ?? null)) {
            $target = Target::query()->find($requestSnapshot['target_id']);

            if (! $target || $target->verification_status !== 'verified') {
                return $this->deny('TARGET_NOT_VERIFIED', $requestSnapshot, $authorization, $policySnapshot);
            }
        }

        return [
            'decision' => 'allow',
            'reason_code' => 'AUTHORIZED',
            'authorization' => $authorization,
            'engine_plan' => collect($authorization->allowed_engines)->map(fn (string $engine): array => [
                'engine_key' => $engine,
                'status' => 'planned',
            ])->values()->all(),
            'policy_snapshot' => $policySnapshot,
            'policy_decision' => PolicyDecision::query()->create([
                'authorization_id' => $authorization->id,
                'gateway' => 'authorization',
                'decision' => 'allow',
                'reason_code' => 'AUTHORIZED',
                'request_snapshot' => $requestSnapshot,
                'policy_snapshot' => $policySnapshot,
            ]),
        ];
    }

    private function deny(
        string $reasonCode,
        array $requestSnapshot,
        ?Authorization $authorization = null,
        ?array $policySnapshot = null
    ): array {
        $decision = PolicyDecision::query()->create([
            'authorization_id' => $authorization?->id,
            'gateway' => 'authorization',
            'decision' => 'deny',
            'reason_code' => $reasonCode,
            'request_snapshot' => $requestSnapshot,
            'policy_snapshot' => $policySnapshot,
        ]);

        return [
            'decision' => 'deny',
            'reason_code' => $reasonCode,
            'authorization' => $authorization,
            'engine_plan' => [],
            'policy_snapshot' => $policySnapshot,
            'policy_decision' => $decision,
        ];
    }
}
