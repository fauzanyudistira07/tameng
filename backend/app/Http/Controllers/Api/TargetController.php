<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Target;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TargetController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'targets' => Target::query()
                ->with(['project:id,name,code', 'verifier:id,name'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $target = Target::query()->create($this->validateTarget($request));

        return response()->json([
            'target' => $target->load(['project:id,name,code', 'verifier:id,name']),
        ], 201);
    }

    public function update(Request $request, Target $target): JsonResponse
    {
        $target->update($this->validateTarget($request));

        return response()->json([
            'target' => $target->refresh()->load(['project:id,name,code', 'verifier:id,name']),
        ]);
    }

    public function verify(Request $request, Target $target): JsonResponse
    {
        $data = $request->validate([
            'verification_status' => ['required', Rule::in(['verified', 'rejected', 'pending'])],
        ]);

        $target->forceFill([
            'verification_status' => $data['verification_status'],
            'verified_at' => $data['verification_status'] === 'verified' ? now() : null,
            'verified_by' => $data['verification_status'] === 'verified' ? $request->user()->id : null,
        ])->save();

        return response()->json([
            'target' => $target->refresh()->load(['project:id,name,code', 'verifier:id,name']),
        ]);
    }

    private function validateTarget(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'type' => ['required', Rule::in(['web', 'api'])],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'hostname' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
