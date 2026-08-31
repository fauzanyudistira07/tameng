<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunScanJob;
use App\Models\ScanJob;
use App\Services\AuditLogger;
use App\Services\AuthorizationGateway;
use App\Services\Engines\EngineRunner;
use App\Services\SimulatedWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScanJobController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'scan_jobs' => ScanJob::query()
                ->with([
                    'project:id,name,code',
                    'repository:id,name',
                    'target:id,name,type',
                    'scanProfile:id,key,name',
                    'authorization:id,code',
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
                ])
                ->orderByDesc('id')
                ->get(),
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

        $decision = $gateway->decide([
            ...$data,
            'requested_by' => $request->user()->id,
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
                'authorization:id,code',
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
    ): JsonResponse {
        $scanRun = $engineRunner->execute($scanJob, $engineKey);

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
                'authorization:id,code',
                'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            ]),
        ]);
    }

    public function rerun(Request $request, ScanJob $scanJob, AuditLogger $auditLogger): JsonResponse
    {
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
                'authorization:id,code',
                'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            ]),
        ], 201);
    }
}
