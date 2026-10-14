<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        $query = Project::query()
            ->with([
                'owner:id,name,email,role_id',
                'owner.role:id,name,display_name',
                'authorizations' => function ($q) {
                    $q->select('id', 'project_id', 'code', 'status', 'valid_from', 'valid_until', 'requested_by')
                      ->with('requester.role:id,name')
                      ->orderByDesc('id');
                }
            ])
            ->orderByDesc('id');

        // Scoping keamanan ketat: Administrator (super_admin, security_admin) memiliki akses global ke seluruh proyek.
        // Role non-admin (security_analyst, developer, viewer) HANYA boleh melihat proyek miliknya atau yang ditugaskan.
        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $assignedIds = $user->projects()->pluck('projects.id');
            $query->where(function ($q) use ($assignedIds, $user) {
                $q->whereIn('id', $assignedIds)
                  ->orWhere('owner_id', $user->id);
            });
        }

        $projects = $query->get()->map(function ($project) {
            $project->active_authorization = $project->authorizations->first();
            return $project;
        });

        return response()->json([
            'projects' => $projects,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;
        $data = $this->validateProject($request);
        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $data['owner_id'] = $user->id;
        }

        $project = Project::query()->create($data);

        return response()->json([
            'project' => $project->load('owner:id,name,email'),
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $isAssigned = $user->projects()->where('projects.id', $project->id)->exists();
            $isOwner = $project->owner_id === $user->id;
            if (! $isAssigned && ! $isOwner) {
                return response()->json([
                    'message' => 'Akses ditolak: Anda tidak memiliki izin untuk melihat proyek ini.',
                ], 403);
            }
        }

        return response()->json([
            'project' => $project->load('owner:id,name,email'),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $isAssigned = $user->projects()->where('projects.id', $project->id)->exists();
            $isOwner = $project->owner_id === $user->id;
            if (! $isAssigned && ! $isOwner) {
                return response()->json([
                    'message' => 'Akses ditolak: Anda tidak memiliki izin untuk mengedit proyek ini.',
                ], 403);
            }
        }

        $project->update($this->validateProject($request, $project));

        return response()->json([
            'project' => $project->refresh()->load('owner:id,name,email'),
        ]);
    }

    private function validateProject(Request $request, ?Project $project = null): array
    {
        // Jika code tidak diisi atau kosong, generate kode otomatis dari nama proyek dan ID user assign
        // Format: PRJ-SINGKATAN-USR-ID
        if (! $request->filled('code') && $request->filled('name')) {
            $userId = $request->input('owner_id') ?: ($request->user()?->id ?: 1);
            $abbr = $this->getProjectAbbreviation((string) $request->input('name'));
            $prefix = 'PRJ-'.$abbr.'-USR-'.$userId;

            $candidate = $prefix;
            while (Project::where('code', $candidate)->when($project, fn ($q) => $q->where('id', '!=', $project->id))->exists()) {
                $candidate = $prefix.'-'.Str::upper(Str::random(4));
            }
            $request->merge(['code' => $candidate]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', Rule::unique('projects', 'code')->ignore($project?->id)],
            'description' => ['nullable', 'string'],
            'criticality' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['required', Rule::in(['active', 'inactive', 'archived'])],
            'owner_id' => ['nullable', 'exists:users,id'],
        ]);
    }

    private function getProjectAbbreviation(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return 'APP';
        }

        if (preg_match('/\(([\w\d]+)\)/', $trimmed, $matches)) {
            return Str::upper($matches[1]);
        }

        $words = preg_split('/[\s_\-]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        if (! empty($words) && count($words) >= 2) {
            $initials = '';
            foreach ($words as $w) {
                $initials .= Str::upper(mb_substr($w, 0, 1));
            }
            return Str::limit($initials, 10, '');
        }

        if (! empty($words) && count($words) === 1) {
            $clean = preg_replace('/[^\w\d]/', '', $words[0]);
            return Str::upper(Str::limit($clean, 12, ''));
        }

        return 'APP';
    }
}
