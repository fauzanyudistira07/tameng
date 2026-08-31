<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScopeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'scopes' => Scope::query()
                ->with(['project:id,name,code', 'target:id,name,hostname,type', 'creator:id,name'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $scope = Scope::query()->create([
            ...$this->validateScope($request),
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'scope' => $scope->load(['project:id,name,code', 'target:id,name,hostname,type', 'creator:id,name']),
        ], 201);
    }

    public function update(Request $request, Scope $scope): JsonResponse
    {
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
