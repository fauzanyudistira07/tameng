<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'projects' => Project::query()
                ->with('owner:id,name,email')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = Project::query()->create($this->validateProject($request));

        return response()->json([
            'project' => $project->load('owner:id,name,email'),
        ], 201);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json([
            'project' => $project->load('owner:id,name,email'),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $project->update($this->validateProject($request, $project));

        return response()->json([
            'project' => $project->refresh()->load('owner:id,name,email'),
        ]);
    }

    private function validateProject(Request $request, ?Project $project = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', Rule::unique('projects', 'code')->ignore($project?->id)],
            'description' => ['nullable', 'string'],
            'criticality' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['required', Rule::in(['active', 'inactive', 'archived'])],
            'owner_id' => ['nullable', 'exists:users,id'],
        ]);
    }
}
