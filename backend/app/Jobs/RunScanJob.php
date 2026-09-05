<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\ScanJob;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Engines\EngineRunner;
use App\Services\Engines\Support\DeterministicEngineSelector;
use App\Services\ReportGenerator;
use App\Services\RepositoryWorkspaceSyncer;
use App\Services\Support\FormLoginSessionResolver;
use App\Services\WebhookNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $scanJobId,
        public readonly int $userId,
    ) {}

    public function handle(
        EngineRunner $engineRunner,
        ReportGenerator $reportGenerator,
        AuditLogger $auditLogger,
        RepositoryWorkspaceSyncer $workspaceSyncer,
        WebhookNotificationService $webhookNotifier,
        DeterministicEngineSelector $engineSelector,
    ): void {
        set_time_limit(0);

        $scanJob = ScanJob::query()->find($this->scanJobId);
        $user = User::query()->find($this->userId);

        if (! $scanJob || ! $user || ! in_array($scanJob->status, ['queued', 'running'], true)) {
            return;
        }

        if ($scanJob->status === 'queued') {
            $scanJob->forceFill([
                'status' => 'running',
                'started_at' => $scanJob->started_at ?? now(),
            ])->save();
        }

        $scanJob->loadMissing('repository');

        if ($scanJob->repository && empty($scanJob->repository->metadata['local_path'])) {
            try {
                $syncResult = $workspaceSyncer->sync($scanJob->repository, $user);
            } catch (Throwable $exception) {
                $errorMessage = $exception instanceof ValidationException
                    ? (collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                    : $exception->getMessage();

                $scanJob->forceFill([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'failure_reason' => 'WORKSPACE_SYNC_FAILED: '.$errorMessage,
                ])->save();

                $auditLogger->recordSystem('repository.workspace.sync_auto', 'failed', [
                    'user_id' => $user->id,
                    'project_id' => $scanJob->project_id,
                    'authorization_id' => $scanJob->authorization_id,
                    'scan_job_id' => $scanJob->id,
                    'target_type' => 'repository',
                    'target_id' => $scanJob->repository->id,
                    'metadata' => [
                        'scan_job_code' => $scanJob->code,
                        'repository_id' => $scanJob->repository->id,
                        'failure_reason' => $errorMessage,
                        'source' => 'queue_worker',
                    ],
                ]);

                return;
            }

            $auditLogger->recordSystem('repository.workspace.sync_auto', 'success', [
                'user_id' => $user->id,
                'project_id' => $scanJob->project_id,
                'authorization_id' => $scanJob->authorization_id,
                'scan_job_id' => $scanJob->id,
                'target_type' => 'repository',
                'target_id' => $scanJob->repository->id,
                'metadata' => [
                    'scan_job_code' => $scanJob->code,
                    'repository_id' => $scanJob->repository->id,
                    'workspace' => $syncResult['workspace'],
                    'commit' => $syncResult['commit'],
                    'source' => 'queue_worker',
                ],
            ]);

            $scanJob->refresh();
        }

        $scanJob->loadMissing(['repository', 'target']);
        $workspacePath = $scanJob->repository?->metadata['local_path'] ?? null;

        // Deterministically select and enrich relevant engines for this target workspace or web target
        if ($workspacePath && $scanJob->repository_id) {
            $selectedEngineCodes = $engineSelector->selectEngines('repository', $workspacePath);
            $currentPlanned = collect($scanJob->engine_plan)->pluck('engine_key')->filter()->all();
            $engineKeys = array_values(array_unique(array_merge($currentPlanned, $selectedEngineCodes)));
        } elseif ($scanJob->target_id && $scanJob->target) {
            // If target uses form_login, auto-resolve session cookies before engine dispatch
            $auth = $scanJob->target->metadata['auth'] ?? null;
            if ($auth && ($auth['type'] ?? null) === 'form_login' && ! empty($auth['username']) && ! empty($auth['password'])) {
                try {
                    $resolver = app(FormLoginSessionResolver::class);
                    $rawPassword = Crypt::decryptString($auth['password']);
                    $loginPath = $auth['login_path'] ?? '/login';
                    $resolvedCookies = $resolver->resolve(
                        $scanJob->target->base_url,
                        $auth['username'],
                        $rawPassword,
                        $loginPath
                    );

                    if ($resolvedCookies) {
                        $meta = $scanJob->target->metadata;
                        $meta['auth']['resolved_cookie_value'] = Crypt::encryptString($resolvedCookies);
                        $meta['auth']['resolved_at'] = now()->toISOString();
                        $scanJob->target->metadata = $meta;
                        $scanJob->target->save();
                    }
                } catch (\Throwable $e) {
                    Log::warning("RunScanJob: Auto-login resolution failed for job #{$scanJob->id}: " . $e->getMessage());
                }
            }

            $targetType = $scanJob->target->type ?? 'web';
            $selectedEngineCodes = $engineSelector->selectEngines($targetType);
            $currentPlanned = collect($scanJob->engine_plan)->pluck('engine_key')->filter()->all();
            $engineKeys = array_values(array_unique(array_merge($currentPlanned, $selectedEngineCodes)));
        } else {
            $engineKeys = collect($scanJob->engine_plan)->pluck('engine_key')->filter()->values()->all();
        }

        $updatedEnginePlan = collect($engineKeys)->map(fn ($k) => ['engine_key' => $k])->all();
        $scanJob->forceFill([
            'engine_plan' => $updatedEnginePlan,
            'required_engine_count' => count($engineKeys),
        ])->save();

        $totalEngines = max(1, count($engineKeys));
        foreach ($engineKeys as $engineIndex => $engineKey) {
            $scanRun = $engineRunner->execute($scanJob, $engineKey);

            $incrementalProgress = (int) round((($engineIndex + 1) / $totalEngines) * 95);
            $scanJob->forceFill(['progress' => $incrementalProgress])->save();

            $auditLogger->recordSystem('engine.run_auto', $scanRun->status === 'failed' ? 'failed' : 'success', [
                'user_id' => $user->id,
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
                    'source' => 'queue_worker',
                ],
            ]);

            if ($scanRun->status === 'denied') {
                break;
            }
        }

        $scanJob->refresh();

        $runs = $scanJob->scanRuns()->get();
        $totalEngines = max(1, count($scanJob->engine_plan ?? []));
        $terminalRuns = $runs->filter(fn (ScanRun $run): bool => in_array($run->status, ['completed', 'failed', 'denied', 'skipped'], true));
        $completedCount = $runs->where('status', 'completed')->count();
        $failedCount = $runs->where('status', 'failed')->count();
        $hasDenied = $runs->contains(fn (ScanRun $run): bool => $run->status === 'denied');
        $finalStatus = $hasDenied ? 'denied' : ($completedCount > 0 ? 'completed' : ($failedCount > 0 ? 'failed' : 'completed'));
        $progress = $terminalRuns->count() >= $totalEngines ? 100 : (int) round(($terminalRuns->count() / $totalEngines) * 100);

        // Update engine_plan array with actual individual engine statuses
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

        if (
            in_array($scanJob->status, ['completed', 'failed'], true)
            && $scanJob->scanRuns()->exists()
            && ! Report::query()->where('scan_job_id', $scanJob->id)->exists()
        ) {
            $report = $reportGenerator->generateStandardJson($scanJob, $user);

            $auditLogger->recordSystem('report.generate_auto', 'success', [
                'user_id' => $user->id,
                'project_id' => $scanJob->project_id,
                'authorization_id' => $scanJob->authorization_id,
                'scan_job_id' => $scanJob->id,
                'target_type' => 'report',
                'target_id' => $report->id,
                'metadata' => [
                    'report_id' => $report->id,
                    'format' => $report->format,
                    'finding_count' => $report->metadata['finding_count'] ?? 0,
                    'source' => 'queue_worker',
                ],
            ]);

            $webhookNotifier->notifyScanResult($scanJob);
        }
    }
}
