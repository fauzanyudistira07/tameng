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
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        $query = Finding::query()
            ->with(['project:id,name,code', 'scanJob:id,code,status', 'scanRun:id,engine_key,status'])
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low', 'informational')")
            ->orderByDesc('id');

        // Scoping keamanan: Administrator (super_admin, security_admin) memiliki akses penuh ke seluruh temuan sistem.
        // Role non-admin (security_analyst, developer, viewer) hanya boleh mengakses temuan proyek miliknya atau yang ditugaskan.
        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            $query->whereIn('project_id', $allowedProjectIds);
        }

        $findings = $query->get();

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

    public function show(Request $request, Finding $finding): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            if (! $allowedProjectIds->contains($finding->project_id)) {
                return response()->json([
                    'message' => 'Akses ditolak: Anda tidak memiliki akses ke proyek temuan ini.',
                ], 403);
            }
        }

        return response()->json([
            'finding' => $finding->load([
                'project:id,name,code',
                'scanJob:id,code,status',
                'scanRun:id,engine_key,status',
                'evidence',
            ]),
        ]);
    }

    public function aiRemediation(Request $request, Finding $finding, AiRemediationService $aiService): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            if (! $allowedProjectIds->contains($finding->project_id)) {
                return response()->json([
                    'message' => 'Akses ditolak: Anda tidak memiliki akses ke proyek temuan ini.',
                ], 403);
            }
        }

        $guidance = $aiService->generateGuidance($finding);

        return response()->json([
            'remediation' => $guidance,
        ]);
    }

    public function update(Request $request, Finding $finding, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        // Viewer hanya memiliki hak baca
        if ($userRole === 'viewer') {
            return response()->json([
                'message' => 'Peran Anda tidak memiliki izin untuk mengubah status temuan.',
            ], 403);
        }

        // Pastikan role non-admin hanya dapat mengupdate temuan pada proyek yang ditugaskan/dimilikinya
        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            if (! $allowedProjectIds->contains($finding->project_id)) {
                return response()->json([
                    'message' => 'Akses ditolak: Temuan ini bukan bagian dari proyek yang ditugaskan kepada Anda.',
                ], 403);
            }
        }

        if ($userRole === 'developer') {

            $data = $request->validate([
                'status' => ['required', Rule::in(['open', 'in_progress', 'fixed'])],
                'resolution_notes' => ['nullable', 'string', 'max:1500'],
            ]);
        } else {
            // Super Admin, Security Admin, dan Security Analyst memiliki hak triage lengkap
            $data = $request->validate([
                'status' => ['required', Rule::in(['open', 'reviewing', 'in_progress', 'resolved', 'false_positive', 'accepted', 'fixed'])],
                'resolution_notes' => ['nullable', 'string', 'max:1500'],
            ]);
        }

        $previousStatus = $finding->status;
        $meta = $finding->normalization_metadata ?? [];
        if (! empty($data['resolution_notes'])) {
            $meta['triage_notes'] = $data['resolution_notes'];
            $meta['triaged_by'] = $user->name;
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
