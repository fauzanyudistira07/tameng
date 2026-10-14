<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScopeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        $query = Scope::query()
            ->with(['project:id,name,code', 'target:id,name,hostname,type', 'creator:id,name'])
            ->orderByDesc('id');

        // Scoping keamanan ketat: Tim SOC (super_admin, security_admin, security_analyst) memiliki akses sistem global.
        // Role klien/tenant (developer, viewer) hanya boleh melihat scope proyek miliknya.
        if (in_array($userRole, ['developer', 'viewer'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            $userId = $user->id;
            $query->where(function ($q) use ($allowedProjectIds, $userId) {
                $q->whereIn('project_id', $allowedProjectIds)
                  ->orWhere('created_by', $userId);
            });
        }

        return response()->json([
            'scopes' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;
        $validated = $this->validateScope($request);

        if (in_array($userRole, ['developer', 'viewer'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            if (! $allowedProjectIds->contains($validated['project_id'])) {
                abort(403, 'Akses ditolak: Anda tidak memiliki akses ke proyek ini.');
            }
        }

        $scope = Scope::query()->create([
            ...$validated,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'scope' => $scope->load(['project:id,name,code', 'target:id,name,hostname,type', 'creator:id,name']),
        ], 201);
    }

    public function update(Request $request, Scope $scope): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        if (in_array($userRole, ['developer', 'viewer'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            if (! $allowedProjectIds->contains($scope->project_id)) {
                abort(403, 'Akses ditolak: Anda tidak memiliki akses ke proyek ini.');
            }
        }

        $scope->update($this->validateScope($request));

        return response()->json([
            'scope' => $scope->refresh()->load(['project:id,name,code', 'target:id,name,hostname,type', 'creator:id,name']),
        ]);
    }

    private function validateScope(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'target_id' => ['nullable', 'exists:targets,id'],
            'type' => ['required', Rule::in(['url', 'hostname', 'path', 'api_route'])],
            'pattern' => ['required', 'string', 'max:2048'],
            'effect' => ['required', Rule::in(['allow', 'deny'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reason' => ['nullable', 'string'],
        ]);
    }
}
