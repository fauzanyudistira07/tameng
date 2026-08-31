<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Authorization;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ScanProfile;
use App\Models\Scope;
use App\Models\Target;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthorizationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'authorizations' => Authorization::query()
                ->with([
                    'project:id,name,code',
                    'repository:id,name,verification_status',
                    'target:id,name,type,verification_status',
                    'scanProfile:id,key,name',
                ])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'repository_id' => ['nullable', 'exists:repositories,id'],
            'target_id' => ['nullable', 'exists:targets,id'],
            'scan_profile_id' => ['required', 'exists:scan_profiles,id'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'max_concurrency' => ['required', 'integer', 'min:1', 'max:10'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:600'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
        ]);

        $project = Project::query()->findOrFail($data['project_id']);
        $profile = ScanProfile::query()->findOrFail($data['scan_profile_id']);
        $repository = ! empty($data['repository_id']) ? Repository::query()->findOrFail($data['repository_id']) : null;
        $target = ! empty($data['target_id']) ? Target::query()->findOrFail($data['target_id']) : null;

        $this->assertAuthorizable($project, $profile, $repository, $target);

        $scopeQuery = Scope::query()
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->where(function ($query) use ($target): void {
                $query->whereNull('target_id');

                if ($target) {
                    $query->orWhere('target_id', $target->id);
                }
            });

        $allowedScopes = (clone $scopeQuery)->where('effect', 'allow')->get();
        $deniedScopes = (clone $scopeQuery)->where('effect', 'deny')->get();

        if ($allowedScopes->isEmpty()) {
            throw ValidationException::withMessages([
                'project_id' => ['At least one active allow scope is required before authorization.'],
            ]);
        }

        $authorization = Authorization::query()->create([
            ...$data,
            'code' => 'AUTH-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'requested_by' => $request->user()->id,
            'approved_by' => $request->user()->id,
            'allowed_engines' => $profile->engine_keys,
            'allowed_scope_snapshot' => $allowedScopes->map->only(['id', 'type', 'pattern', 'effect', 'target_id'])->values(),
            'denied_scope_snapshot' => $deniedScopes->map->only(['id', 'type', 'pattern', 'effect', 'target_id'])->values(),
            'policy_snapshot' => [
                'scan_profile' => $profile->only(['id', 'key', 'name', 'active_testing']),
                'profile_policy' => $profile->policy,
                'project' => $project->only(['id', 'code', 'criticality', 'status']),
                'repository_verified' => $repository?->verification_status === 'verified',
                'target_verified' => $target?->verification_status === 'verified',
            ],
        ]);

        $auditLogger->record($request, 'authorization.create', 'success', [
            'project_id' => $authorization->project_id,
            'authorization_id' => $authorization->id,
            'target_type' => 'authorization',
            'target_id' => $authorization->id,
            'metadata' => [
                'code' => $authorization->code,
                'repository_id' => $authorization->repository_id,
                'target_id' => $authorization->target_id,
                'scan_profile_id' => $authorization->scan_profile_id,
                'status' => $authorization->status,
            ],
        ]);

        return response()->json([
            'authorization' => $authorization->load(['project:id,name,code', 'repository:id,name,verification_status', 'target:id,name,type,verification_status', 'scanProfile:id,key,name']),
        ], 201);
    }

    private function assertAuthorizable(Project $project, ScanProfile $profile, ?Repository $repository, ?Target $target): void
    {
        if ($project->status !== 'active') {
            throw ValidationException::withMessages(['project_id' => ['Project must be active.']]);
        }

        if (! $profile->is_active) {
            throw ValidationException::withMessages(['scan_profile_id' => ['Scan profile must be active.']]);
        }

        if (! $repository && ! $target) {
            throw ValidationException::withMessages(['project_id' => ['Repository or target is required.']]);
        }

        if ($repository && ($repository->project_id !== $project->id || $repository->verification_status !== 'verified')) {
            throw ValidationException::withMessages(['repository_id' => ['Repository must belong to project and be verified.']]);
        }

        if ($target && ($target->project_id !== $project->id || $target->verification_status !== 'verified')) {
            throw ValidationException::withMessages(['target_id' => ['Target must belong to project and be verified.']]);
        }

        if ($target && ! in_array($target->type, $profile->allowed_target_types, true)) {
            throw ValidationException::withMessages(['target_id' => ['Target type is not allowed by selected scan profile.']]);
        }

        if ($repository && ! in_array('repository', $profile->allowed_target_types, true)) {
            throw ValidationException::withMessages(['repository_id' => ['Repository is not allowed by selected scan profile.']]);
        }
    }
}
