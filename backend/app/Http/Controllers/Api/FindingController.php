<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Finding;
use App\Services\AiRemediationService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FindingController extends Controller
{
    public function index(): JsonResponse
    {
        $findings = Finding::query()
            ->with(['project:id,name,code', 'scanJob:id,code,status', 'scanRun:id,engine_key,status'])
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low', 'informational')")
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'summary' => [
                'total' => $findings->count(),
                'critical' => $findings->where('severity', 'critical')->count(),
                'high' => $findings->where('severity', 'high')->count(),
                'medium' => $findings->where('severity', 'medium')->count(),
                'low' => $findings->where('severity', 'low')->count(),
                'informational' => $findings->where('severity', 'informational')->count(),
            ],
            'findings' => $findings,
        ]);
    }

    public function show(Finding $finding): JsonResponse
    {
        return response()->json([
            'finding' => $finding->load([
                'project:id,name,code',
                'scanJob:id,code,status',
                'scanRun:id,engine_key,status',
                'evidence',
            ]),
        ]);
    }

    public function aiRemediation(Finding $finding, AiRemediationService $aiService): JsonResponse
    {
        $guidance = $aiService->generateGuidance($finding);

        return response()->json([
            'remediation' => $guidance,
        ]);
    }

    public function update(Request $request, Finding $finding, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'reviewing', 'in_progress', 'resolved', 'false_positive', 'accepted', 'fixed'])],
            'resolution_notes' => ['nullable', 'string', 'max:1500'],
        ]);

        $previousStatus = $finding->status;
        $meta = $finding->normalization_metadata ?? [];
        if (! empty($data['resolution_notes'])) {
            $meta['triage_notes'] = $data['resolution_notes'];
            $meta['triaged_by'] = $request->user()->name;
            $meta['triaged_at'] = now()->toISOString();
        }

        $finding->update([
            'status' => $data['status'],
            'normalization_metadata' => $meta,
        ]);

        $auditLogger->record($request, 'finding.triage', 'success', [
            'project_id' => $finding->project_id,
            'scan_job_id' => $finding->scan_job_id,
            'target_type' => 'finding',
            'target_id' => $finding->id,
            'metadata' => [
                'code' => $finding->code,
                'previous_status' => $previousStatus,
                'new_status' => $finding->status,
                'severity' => $finding->severity,
                'notes' => $data['resolution_notes'] ?? null,
            ],
        ]);

        return response()->json([
            'finding' => $finding->refresh()->load(['project:id,name,code', 'scanJob:id,code,status', 'scanRun:id,engine_key,status']),
        ]);
    }
}
