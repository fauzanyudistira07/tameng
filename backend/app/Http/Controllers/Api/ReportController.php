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
    public function index(): JsonResponse
    {
        return response()->json([
            'reports' => Report::query()
                ->with(['scanJob:id,code,status,project_id,repository_id,target_id,created_by', 'scanJob.project:id,name,code', 'generator:id,name'])
                ->orderByDesc('id')
                ->get(),
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

        if ($role === 'developer') {
            $report->loadMissing('scanJob:id,created_by');
            abort_unless($report->scanJob?->created_by === $user->id, 403);
        }
    }
}
