<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ScanJob;
use App\Services\AuditLogger;
use App\Services\ReportGenerator;
use App\Services\ReportPdfGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        $query = Report::query()
            ->with(['scanJob:id,code,status,project_id,repository_id,target_id,created_by', 'scanJob.project:id,name,code', 'generator:id,name'])
            ->orderByDesc('id');

        // Scoping untuk developer dan viewer: hanya laporan dari proyek ditugaskan atau scan yang dibuat sendiri
        if (in_array($userRole, ['developer', 'viewer'], true)) {
            $assignedProjectIds = $user->projects()->pluck('projects.id');
            $userId = $user->id;
            $query->whereHas('scanJob', function ($q) use ($assignedProjectIds, $userId) {
                $q->whereIn('project_id', $assignedProjectIds)
                  ->orWhere('created_by', $userId);
            });
        }

        return response()->json([
            'reports' => $query->get(),
        ]);
    }

    public function store(Request $request, ReportGenerator $generator, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate([
            'scan_job_id' => ['required', 'exists:scan_jobs,id'],
        ]);

        $scanJob = ScanJob::query()->findOrFail($data['scan_job_id']);

        if (! in_array($scanJob->status, ['completed', 'failed'], true) || ! $scanJob->scanRuns()->exists()) {
            throw ValidationException::withMessages([
                'scan_job_id' => ['Laporan hanya bisa dibuat untuk scan yang sudah selesai atau gagal setelah engine berjalan.'],
            ]);
        }

        $report = $generator
            ->generateStandardJson($scanJob, $request->user())
            ->load(['scanJob:id,code,status', 'generator:id,name']);

        $auditLogger->record($request, 'report.generate', 'success', [
            'project_id' => $scanJob->project_id,
            'authorization_id' => $scanJob->authorization_id,
            'scan_job_id' => $scanJob->id,
            'target_type' => 'report',
            'target_id' => $report->id,
            'metadata' => [
                'report_id' => $report->id,
                'format' => $report->format,
                'finding_count' => $report->metadata['finding_count'] ?? 0,
            ],
        ]);

        return response()->json([
            'report' => $report,
        ], 201);
    }

    public function show(Report $report): JsonResponse
    {
        $this->authorizeReportAccess($report);

        return response()->json([
            'report' => $report->load(['scanJob:id,code,status', 'generator:id,name']),
        ]);
    }

    public function downloadPdf(Report $report, ReportPdfGenerator $pdfGenerator)
    {
        $this->authorizeReportAccess($report);

        $pdf = $pdfGenerator->render($report);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdfGenerator->filename($report).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function authorizeReportAccess(Report $report): void
    {
        $user = request()->user();
        $role = $user?->role?->name;

        if (in_array($role, ['developer', 'viewer'], true)) {
            $report->loadMissing('scanJob:id,created_by,project_id');
            $isCreator = $report->scanJob?->created_by === $user->id;
            $isAssignedProject = $user->projects()->where('projects.id', $report->scanJob?->project_id)->exists();
            abort_unless($isCreator || $isAssignedProject, 403, 'Akses laporan ditolak: Anda tidak memiliki izin untuk laporan proyek ini.');
        }
    }
}
