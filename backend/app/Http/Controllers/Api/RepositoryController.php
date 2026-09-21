<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunScanJob;
use App\Models\Authorization;
use App\Models\Repository;
use App\Models\ScanJob;
use App\Models\ScanProfile;
use App\Models\Scope;
use App\Services\AuditLogger;
use App\Services\AuthorizationGateway;
use App\Services\RepositoryWorkspaceManager;
use App\Services\RepositoryWorkspaceSyncer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RepositoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        $query = Repository::query()
            ->with(['project:id,name,code', 'verifier:id,name'])
            ->orderByDesc('id');

        // Scoping untuk developer dan viewer: hanya tampilkan repositori dari proyek yang ditugaskan
        if (in_array($userRole, ['developer', 'viewer'], true)) {
            $query->whereIn('project_id', $user->projects()->pluck('projects.id'));
        }

        $repositories = $query->get()
            ->map(fn (Repository $repo) => $this->sanitizeRepository($repo));

        return response()->json([
            'repositories' => $repositories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateRepository($request);
        $encryptedToken = ! empty($validated['access_token']) ? Crypt::encryptString($validated['access_token']) : null;
        $metadata = [
            'is_private' => (bool) ($validated['is_private'] ?? ! empty($validated['access_token'])),
            'access_token' => $encryptedToken,
        ];

        $repository = Repository::query()->create([
            'project_id' => $validated['project_id'],
            'provider' => $validated['provider'],
            'name' => $validated['name'],
            'url' => $validated['url'],
            'default_branch' => $validated['default_branch'],
            'metadata' => $metadata,
        ]);

        return response()->json([
            'repository' => $this->sanitizeRepository($repository->load(['project:id,name,code', 'verifier:id,name'])),
        ], 201);
    }

    public function update(Request $request, Repository $repository): JsonResponse
    {
        $validated = $this->validateRepository($request);
        $metadata = $repository->metadata ?? [];
        if (isset($validated['is_private'])) {
            $metadata['is_private'] = (bool) $validated['is_private'];
        }
        if (array_key_exists('access_token', $validated) && $validated['access_token'] !== null) {
            $metadata['access_token'] = $validated['access_token'] !== '' ? Crypt::encryptString($validated['access_token']) : null;
            if (! empty($metadata['access_token'])) {
                $metadata['is_private'] = true;
            }
        }

        $repository->update([
            'project_id' => $validated['project_id'],
            'provider' => $validated['provider'],
            'name' => $validated['name'],
            'url' => $validated['url'],
            'default_branch' => $validated['default_branch'],
            'metadata' => $metadata,
        ]);

        return response()->json([
            'repository' => $this->sanitizeRepository($repository->refresh()->load(['project:id,name,code', 'verifier:id,name'])),
        ]);
    }

    private function sanitizeRepository(Repository $repo): Repository
    {
        if (isset($repo->metadata['access_token'])) {
            $meta = $repo->metadata;
            $meta['has_access_token'] = ! empty($meta['access_token']);
            unset($meta['access_token']);
            $repo->metadata = $meta;
        }

        return $repo;
    }

    public function verify(Request $request, Repository $repository): JsonResponse
    {
        $data = $request->validate([
            'verification_status' => ['required', Rule::in(['verified', 'rejected', 'pending'])],
        ]);

        $repository->forceFill([
            'verification_status' => $data['verification_status'],
            'verified_at' => $data['verification_status'] === 'verified' ? now() : null,
            'verified_by' => $data['verification_status'] === 'verified' ? $request->user()->id : null,
        ])->save();

        return response()->json([
            'repository' => $repository->refresh()->load(['project:id,name,code', 'verifier:id,name']),
        ]);
    }

    public function attachWorkspace(
        Request $request,
        Repository $repository,
        RepositoryWorkspaceManager $workspaceManager,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $data = $request->validate([
            'local_path' => ['required', 'string', 'max:2048'],
        ]);

        $repository = $workspaceManager->attach($repository, $data['local_path']);

        $auditLogger->record($request, 'repository.workspace.attach', 'success', [
            'project_id' => $repository->project_id,
            'target_type' => 'repository',
            'target_id' => $repository->id,
            'metadata' => [
                'repository_id' => $repository->id,
                'repository_name' => $repository->name,
                'workspace' => $workspaceManager->summarize($repository),
            ],
        ]);

        return response()->json([
            'repository' => $repository->load(['project:id,name,code', 'verifier:id,name']),
            'workspace' => $workspaceManager->summarize($repository),
        ]);
    }

    public function cloneWorkspace(
        Request $request,
        Repository $repository,
        RepositoryWorkspaceSyncer $workspaceSyncer,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $syncResult = $workspaceSyncer->sync($repository, $request->user());
        $repository = $syncResult['repository'];

        $auditLogger->record($request, 'repository.workspace.clone', 'success', [
            'project_id' => $repository->project_id,
            'target_type' => 'repository',
            'target_id' => $repository->id,
            'metadata' => [
                'repository_id' => $repository->id,
                'repository_name' => $repository->name,
                'workspace' => $syncResult['workspace'],
                'commit' => $syncResult['commit'],
            ],
        ]);

        return response()->json([
            'repository' => $repository->refresh()->load(['project:id,name,code', 'verifier:id,name']),
            'workspace' => $syncResult['workspace'],
        ]);
    }

    public function clearWorkspace(
        Request $request,
        Repository $repository,
        RepositoryWorkspaceManager $workspaceManager,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $repository = $workspaceManager->clear($repository);

        $auditLogger->record($request, 'repository.workspace.clear', 'success', [
            'project_id' => $repository->project_id,
            'target_type' => 'repository',
            'target_id' => $repository->id,
            'metadata' => [
                'repository_id' => $repository->id,
                'repository_name' => $repository->name,
                'workspace' => $workspaceManager->summarize($repository),
            ],
        ]);

        return response()->json([
            'repository' => $repository->load(['project:id,name,code', 'verifier:id,name']),
            'workspace' => $workspaceManager->summarize($repository),
        ]);
    }

    public function scan(
        Request $request,
        Repository $repository,
        AuthorizationGateway $gateway,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $user = $request->user();

        // 1. Pastikan status verifikasi repositori valid
        if ($repository->verification_status !== 'verified') {
            $repository->forceFill([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $user->id,
            ])->save();
        }

        // 2. Tentukan profile SAST (source_code_scan)
        $profile = ScanProfile::query()
            ->where('key', 'source_code_scan')
            ->where('is_active', true)
            ->first() ?? ScanProfile::query()->where('is_active', true)->firstOrFail();

        // 3. Buat authorization dan jadwalkan scan job dalam DB transaction
        $scanJob = DB::transaction(function () use ($repository, $profile, $user, $gateway, $auditLogger): ScanJob {
            $project = $repository->project;

            $scope = Scope::query()->firstOrCreate([
                'project_id' => $repository->project_id,
                'pattern' => $repository->url,
            ], [
                'type' => 'repository',
                'effect' => 'allow',
                'status' => 'active',
                'reason' => 'Scope dibuat otomatis untuk pemindaian instan repositori.',
                'created_by' => $user->id,
            ]);

            $authorization = Authorization::query()->create([
                'code' => 'AUTH-'.now()->format('YmdHis').'-REP-'.Str::upper(Str::random(4)),
                'project_id' => $repository->project_id,
                'repository_id' => $repository->id,
                'scan_profile_id' => $profile->id,
                'requested_by' => $user->id,
                'approved_by' => $user->id,
                'status' => 'active',
                'valid_from' => now()->subMinute(),
                'valid_until' => now()->addDays(7),
                'max_concurrency' => 1,
                'rate_limit_per_minute' => 60,
                'allowed_engines' => $profile->engine_keys,
                'allowed_scope_snapshot' => [$scope->only(['id', 'type', 'pattern', 'effect', 'target_id'])],
                'denied_scope_snapshot' => [],
                'policy_snapshot' => [
                    'scan_profile' => $profile->only(['id', 'key', 'name', 'active_testing']),
                    'profile_policy' => $profile->policy,
                    'project' => $project ? $project->only(['id', 'code', 'criticality', 'status']) : ['id' => $repository->project_id],
                    'repository_verified' => true,
                    'source' => 'instant_repository_scan',
                ],
                'notes' => 'Pemindaian instan dari Manajemen Repositori.',
            ]);

            $decision = $gateway->decide([
                'project_id' => $repository->project_id,
                'repository_id' => $repository->id,
                'scan_profile_id' => $profile->id,
                'authorization_id' => $authorization->id,
                'requested_by' => $user->id,
                'requested_at' => now()->toISOString(),
                'source' => 'instant_repository_scan',
            ]);

            if ($decision['decision'] !== 'allow') {
                throw ValidationException::withMessages([
                    'repository' => ["Authorization Gateway menolak pemindaian: {$decision['reason_code']}"],
                ]);
            }

            $scanJob = ScanJob::query()->create([
                'code' => 'SCAN-'.now()->format('YmdHis').'-REP-'.Str::upper(Str::random(4)),
                'project_id' => $repository->project_id,
                'repository_id' => $repository->id,
                'scan_profile_id' => $profile->id,
                'authorization_id' => $authorization->id,
                'created_by' => $user->id,
                'status' => 'queued',
                'progress' => 0,
                'attempt' => 0,
                'queued_at' => now(),
                'engine_plan' => $decision['engine_plan'],
                'execution_policy_snapshot' => [
                    'authorization_decision_id' => $decision['policy_decision']->id,
                    'authorization_reason_code' => $decision['reason_code'],
                    'authorization_policy_snapshot' => $decision['policy_snapshot'],
                ],
            ]);

            $decision['policy_decision']->forceFill(['scan_job_id' => $scanJob->id])->save();

            $auditLogger->recordSystem('scan_job.instant_repository', 'success', [
                'user_id' => $user->id,
                'project_id' => $repository->project_id,
                'repository_id' => $repository->id,
                'scan_job_id' => $scanJob->id,
                'target_type' => 'scan_job',
                'metadata' => [
                    'code' => $scanJob->code,
                    'repository_name' => $repository->name,
                    'url' => $repository->url,
                ],
            ]);

            RunScanJob::dispatch($scanJob->id, $user->id)->afterCommit();

            return $scanJob;
        });

        return response()->json([
            'message' => "Pemindaian SAST untuk repositori {$repository->name} berhasil dimulai di latar belakang!",
            'scan_job' => $scanJob->load([
                'project:id,name,code',
                'repository:id,name,url',
                'scanProfile:id,key,name',
            ]),
        ], 201);
    }

    private function validateRepository(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'provider' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'default_branch' => ['required', 'string', 'max:120'],
            'is_private' => ['nullable', 'boolean'],
            'access_token' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
