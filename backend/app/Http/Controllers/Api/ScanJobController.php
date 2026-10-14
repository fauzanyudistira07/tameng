<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunScanJob;
use App\Models\Report;
use App\Models\ScanJob;
use App\Models\ScanRun;
use App\Services\AuditLogger;
use App\Services\AuthorizationGateway;
use App\Services\Engines\EngineRunner;
use App\Services\ReportGenerator;
use App\Services\SimulatedWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScanJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        $query = ScanJob::query()
            ->with([
                'project:id,name,code',
                'repository:id,name',
                'target:id,name,type',
                'scanProfile:id,key,name',
                'authorization:id,code,status,valid_from,valid_until',
                'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            ])
            ->select([
                'id',
                'code',
                'project_id',
                'repository_id',
                'target_id',
                'scan_profile_id',
                'authorization_id',
                'status',
                'progress',
                'engine_plan',
                'queued_at',
                'started_at',
                'finished_at',
                'failure_reason',
                'created_by',
            ])
            ->orderByDesc('id');

        // Scoping keamanan ketat: Administrator (super_admin, security_admin) memiliki akses sistem global ke seluruh scan job.
        // Role non-admin (security_analyst, developer, viewer) hanya melihat scan job proyek miliknya atau scan mandiri yang dibuatnya.
        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
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
            'scan_jobs' => $query->get(),
        ]);
    }

    public function store(
        Request $request,
        AuthorizationGateway $gateway,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'repository_id' => ['nullable', 'exists:repositories,id'],
            'target_id' => ['nullable', 'exists:targets,id'],
            'scan_profile_id' => ['required', 'exists:scan_profiles,id'],
            'authorization_id' => ['required', 'exists:authorizations,id'],
        ]);

        $user = $request->user();
        $userRole = $user?->role?->name;

        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            if (! $allowedProjectIds->contains($data['project_id'])) {
                abort(403, 'Akses ditolak: Anda tidak memiliki akses ke proyek ini.');
            }
        }

        $decision = $gateway->decide([
            ...$data,
            'requested_by' => $user->id,
            'requested_at' => now()->toISOString(),
        ]);

        if ($decision['decision'] !== 'allow') {
            throw ValidationException::withMessages([
                'authorization_id' => ["Authorization denied: {$decision['reason_code']}"],
            ]);
        }

        $scanJob = ScanJob::query()->create([
            ...$data,
            'code' => 'SCAN-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'created_by' => $request->user()->id,
            'status' => 'queued',
            'progress' => 0,
            'attempt' => 0,
            'queued_at' => now(),
            'engine_plan' => $decision['engine_plan'],
            'execution_policy_snapshot' => [
                'authorization_decision_id' => $decision['policy_decision']->id,
                'authorization_reason_code' => $decision['reason_code'],
                'authorization_policy_snapshot' => $decision['policy_snapshot'],
            ],
        ]);

        $decision['policy_decision']->forceFill(['scan_job_id' => $scanJob->id])->save();

        $auditLogger->record($request, 'scan_job.create', 'success', [
            'project_id' => $scanJob->project_id,
            'authorization_id' => $scanJob->authorization_id,
            'scan_job_id' => $scanJob->id,
            'target_type' => 'scan_job',
            'target_id' => $scanJob->id,
            'metadata' => [
                'code' => $scanJob->code,
                'status' => $scanJob->status,
                'engine_plan' => $scanJob->engine_plan,
            ],
        ]);

        RunScanJob::dispatch($scanJob->id, $request->user()->id)->afterCommit();

        return response()->json([
            'scan_job' => $scanJob->refresh()->load([
                'project:id,name,code',
                'repository:id,name',
                'target:id,name,type',
                'scanProfile:id,key,name',
                'authorization:id,code,status,valid_from,valid_until',
                'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            ]),
        ], 201);
    }

    public function process(Request $request, ScanJob $scanJob, SimulatedWorker $worker, AuditLogger $auditLogger): JsonResponse
    {
        $processedScanJob = $worker->process($scanJob);

        $auditLogger->record($request, 'scan_job.process_simulated', 'success', [
            'project_id' => $processedScanJob->project_id,
            'authorization_id' => $processedScanJob->authorization_id,
            'scan_job_id' => $processedScanJob->id,
            'target_type' => 'scan_job',
            'target_id' => $processedScanJob->id,
            'metadata' => [
                'code' => $processedScanJob->code,
                'status' => $processedScanJob->status,
                'progress' => $processedScanJob->progress,
            ],
        ]);

        return response()->json([
            'scan_job' => $processedScanJob,
        ]);
    }

    public function runGuardedEngine(
        Request $request,
        ScanJob $scanJob,
        string $engineKey,
        EngineRunner $engineRunner,
        AuditLogger $auditLogger,
        ReportGenerator $reportGenerator,
    ): JsonResponse {
        $scanRun = $engineRunner->execute($scanJob, $engineKey);

        $scanJob->refresh();
        $runs = $scanJob->scanRuns()->get();
        $totalEngines = max(1, count($scanJob->engine_plan ?? []));
        $terminalRuns = $runs->filter(fn (ScanRun $run): bool => in_array($run->status, ['completed', 'failed', 'denied', 'skipped'], true));
        $completedCount = $runs->where('status', 'completed')->count();
        $failedCount = $runs->where('status', 'failed')->count();
        $hasDenied = $runs->contains(fn (ScanRun $run): bool => $run->status === 'denied');
        $finalStatus = $hasDenied ? 'denied' : ($completedCount > 0 ? 'completed' : ($failedCount > 0 ? 'failed' : 'completed'));
        $progress = $terminalRuns->count() >= $totalEngines ? 100 : (int) round(($terminalRuns->count() / $totalEngines) * 100);

        $updatedPlan = collect($scanJob->engine_plan ?? [])->map(function ($planItem) use ($runs) {
            $key = $planItem['engine_key'] ?? null;
            $matchedRun = $runs->firstWhere('engine_key', $key);
            return [
                ...$planItem,
                'status' => $matchedRun?->status ?? 'queued',
                'finding_count' => $matchedRun?->runtime_metrics['finding_count'] ?? 0,
            ];
        })->all();

        $scanJob->forceFill([
            'status' => $finalStatus,
            'progress' => $progress,
            'engine_plan' => $updatedPlan,
            'completed_engine_count' => $completedCount,
            'failed_engine_count' => $failedCount,
            'coverage_pass' => ($failedCount === 0 && $completedCount > 0),
            'finished_at' => in_array($finalStatus, ['completed', 'failed', 'denied'], true) ? ($scanJob->finished_at ?? now()) : null,
        ])->save();

        // If a report already exists for this scan job, automatically sync it
        $existingReport = Report::where('scan_job_id', $scanJob->id)->first();
        if ($existingReport) {
            try {
                $reportGenerator->syncReport($existingReport, $request->user());
            } catch (\Throwable $e) {
                // Ignore sync errors gracefully
            }
        }

        $auditLogger->record($request, 'engine.run_guarded', $scanRun->status === 'failed' ? 'failed' : 'success', [
            'project_id' => $scanJob->project_id,
            'authorization_id' => $scanJob->authorization_id,
            'scan_job_id' => $scanJob->id,
            'target_type' => 'scan_run',
            'target_id' => $scanRun->id,
            'metadata' => [
                'scan_job_code' => $scanJob->code,
                'engine_key' => $engineKey,
                'scan_run_status' => $scanRun->status,
                'failure_reason' => $scanRun->failure_reason,
                'scanner_execution' => $scanRun->command_spec['scanner_execution'] ?? false,
            ],
        ]);

        return response()->json([
            'scan_run' => $scanRun,
            'scan_job' => $scanJob->refresh()->load([
                'project:id,name,code',
                'repository:id,name',
                'target:id,name,type',
                'scanProfile:id,key,name',
                'authorization:id,code,status,valid_from,valid_until',
                'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            ]),
        ]);
    }

    public function rerun(Request $request, ScanJob $scanJob, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            if (! $allowedProjectIds->contains($scanJob->project_id) && $scanJob->created_by !== $user->id) {
                abort(403, 'Akses ditolak: Anda tidak memiliki akses untuk menjalankan ulang scan ini.');
            }
        }

        $isAdminOrAnalyst = $user?->isAdmin() ?? false;

        // Kebijakan Masa Berlaku:
        // Jika Pemohon adalah Admin: otorisasi permanen (valid_until = null / unlimited)
        // Jika Pemohon adalah Pengguna Biasa: terapkan masa berlaku 7 hari jika kedaluwarsa
        if ($scanJob->authorization) {
            $auth = $scanJob->authorization;
            $auth->loadMissing('requester.role');
            $isRequesterAdmin = $auth->requester?->isAdmin() ?? false;

            if ($isRequesterAdmin) {
                $auth->update([
                    'status' => 'active',
                    'valid_from' => now()->subMinute(),
                    'valid_until' => null, // Admin = Tanpa Masa Berlaku
                    'notes' => trim(($auth->notes ?? '') . "\n[Diperbarui untuk Administrator: Tanpa Masa Berlaku pada " . now()->format('Y-m-d H:i') . "]"),
                ]);
            } else {
                if ($auth->status !== 'active' || ($auth->valid_until && \Carbon\Carbon::parse($auth->valid_until)->isPast())) {
                    $auth->update([
                        'status' => 'active',
                        'valid_from' => now()->subMinute(),
                        'valid_until' => now()->addDays(7),
                        'notes' => trim(($auth->notes ?? '') . "\n[Diperpanjang untuk Pengguna Biasa (7 hari) pada " . now()->format('Y-m-d H:i') . "]"),
                    ]);
                }
            }
        }

        $newScanJob = ScanJob::query()->create([
            'code' => 'SCAN-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'project_id' => $scanJob->project_id,
            'repository_id' => $scanJob->repository_id,
            'target_id' => $scanJob->target_id,
            'scan_profile_id' => $scanJob->scan_profile_id,
            'authorization_id' => $scanJob->authorization_id,
            'created_by' => $request->user()->id,
            'status' => 'queued',
            'progress' => 0,
            'attempt' => 0,
            'queued_at' => now(),
            'engine_plan' => $scanJob->engine_plan,
            'execution_policy_snapshot' => $scanJob->execution_policy_snapshot,
        ]);

        $auditLogger->record($request, 'scan_job.rerun', 'success', [
            'project_id' => $newScanJob->project_id,
            'authorization_id' => $newScanJob->authorization_id,
            'scan_job_id' => $newScanJob->id,
            'target_type' => 'scan_job',
            'target_id' => $newScanJob->id,
            'metadata' => [
                'code' => $newScanJob->code,
                'original_scan_job_id' => $scanJob->id,
            ],
        ]);

        RunScanJob::dispatch($newScanJob->id, $request->user()->id)->afterCommit();

        return response()->json([
            'scan_job' => $newScanJob->refresh()->load([
                'project:id,name,code',
                'repository:id,name',
                'target:id,name,type',
                'scanProfile:id,key,name',
                'authorization:id,code,status,valid_from,valid_until',
                'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            ]),
        ], 201);
    }
}
