<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\AuditLogger;
use App\Services\RepositoryWorkspaceManager;
use App\Services\RepositoryWorkspaceSyncer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class RepositoryController extends Controller
{
    public function index(): JsonResponse
    {
        $repositories = Repository::query()
            ->with(['project:id,name,code', 'verifier:id,name'])
            ->orderByDesc('id')
            ->get()
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
