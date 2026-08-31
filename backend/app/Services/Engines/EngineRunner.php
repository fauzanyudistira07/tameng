<?php

namespace App\Services\Engines;

use App\Models\Artifact;
use App\Models\ScanJob;
use App\Models\ScanRun;
use App\Services\Engines\Support\FindingDeduplicator;
use App\Services\ExecutionGateway;
use App\Services\FindingNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EngineRunner
{
    public function __construct(
        private readonly EngineRegistry $registry,
        private readonly ExecutionGateway $executionGateway,
        private readonly EngineAdapterResolver $adapterResolver,
        private readonly FindingNormalizer $findingNormalizer,
        private readonly FindingDeduplicator $findingDeduplicator,
    ) {}

    public function plan(ScanJob $scanJob, string $engineKey): EngineRunPlan
    {
        $engine = $this->registry->get($engineKey);
        $decision = $this->executionGateway->decide($scanJob, $engineKey);

        return new EngineRunPlan($scanJob, $engine, $decision);
    }

    public function plansForScanJob(ScanJob $scanJob): array
    {
        return collect($scanJob->engine_plan ?? [])
            ->map(fn (array $engine): array => $this->plan($scanJob, $engine['engine_key'])->toCommandSpec())
            ->values()
            ->all();
    }

    public function execute(ScanJob $scanJob, string $engineKey): ScanRun
    {
        return DB::transaction(function () use ($scanJob, $engineKey): ScanRun {
            $scanJob->refresh();

            $existingTerminalRun = $this->latestTerminalRun($scanJob, $engineKey);

            if ($existingTerminalRun) {
                $this->syncScanJobProgress($scanJob);

                return $existingTerminalRun;
            }

            if (! in_array($scanJob->status, ['queued', 'running'], true)) {
                throw ValidationException::withMessages([
                    'scan_job_id' => ['Only queued or running scan jobs can execute guarded engines.'],
                ]);
            }

            if ($scanJob->status === 'queued') {
                $scanJob->forceFill([
                    'status' => 'running',
                    'started_at' => $scanJob->started_at ?? now(),
                ])->save();
            }

            $plan = $this->plan($scanJob, $engineKey);

            if ($plan->policyDecision['decision'] !== 'allow') {
                $scanRun = ScanRun::query()->create([
                    'scan_job_id' => $scanJob->id,
                    'engine_key' => $engineKey,
                    'status' => 'denied',
                    'exit_code' => null,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'failure_reason' => $plan->policyDecision['reason_code'],
                    'command_spec' => $plan->toCommandSpec(),
                    'runtime_metrics' => [
                        'policy_decision_id' => $plan->policyDecision['policy_decision']->id,
                    ],
                ]);

                $this->syncScanJobProgress($scanJob);

                return $scanRun->refresh();
            }

            $result = $this->adapterResolver->resolve($engineKey)->execute($plan);

            $scanRun = ScanRun::query()->create([
                'scan_job_id' => $scanJob->id,
                'engine_key' => $engineKey,
                'status' => $result->status,
                'exit_code' => $result->exitCode,
                'started_at' => now(),
                'finished_at' => now(),
                'failure_reason' => $result->failureReason,
                'command_spec' => $result->commandSpec,
                'runtime_metrics' => [
                    ...$result->runtimeMetrics,
                    'policy_decision_id' => $plan->policyDecision['policy_decision']->id,
                ],
            ]);

            if ($result->rawOutput !== null && $result->rawOutput !== '') {
                $this->storeRawArtifact($scanJob, $scanRun, $engineKey, $result->rawOutput);
            }

            if (! empty($result->normalizedFindings)) {
                $this->findingDeduplicator->ingest($result->normalizedFindings, $scanJob, $scanRun, $engineKey);
            }

            $this->syncScanJobProgress($scanJob);

            return $scanRun->refresh();
        });
    }

    private function storeRawArtifact(ScanJob $scanJob, ScanRun $scanRun, string $engineKey, string $rawOutput): void
    {
        $path = "scan-runs/{$scanRun->id}/{$engineKey}-raw.json";
        Storage::disk('local')->put($path, $rawOutput);

        Artifact::query()->create([
            'scan_job_id' => $scanJob->id,
            'scan_run_id' => $scanRun->id,
            'type' => 'engine_raw_output',
            'storage_disk' => 'local',
            'path' => $path,
            'sha256' => hash('sha256', $rawOutput),
            'size_bytes' => strlen($rawOutput),
            'metadata' => [
                'engine_key' => $engineKey,
                'redacted' => $engineKey === 'gitleaks',
            ],
        ]);
    }

    private function latestTerminalRun(ScanJob $scanJob, string $engineKey): ?ScanRun
    {
        return $scanJob->scanRuns()
            ->where('engine_key', $engineKey)
            ->whereIn('status', ['completed', 'failed', 'denied', 'skipped'])
            ->latest('id')
            ->first();
    }

    private function syncScanJobProgress(ScanJob $scanJob): void
    {
        $runs = $scanJob->scanRuns()->get();
        $totalEngines = max(1, count($scanJob->engine_plan ?? []));
        $terminalRuns = $runs->filter(fn (ScanRun $run): bool => in_array($run->status, ['completed', 'failed', 'denied', 'skipped'], true));

        $progress = (int) round(($terminalRuns->count() / $totalEngines) * 100);
        $progress = min(100, max(0, $progress));

        $hasDenied = $runs->contains(fn (ScanRun $run): bool => $run->status === 'denied');
        $hasFailed = $runs->contains(fn (ScanRun $run): bool => $run->status === 'failed');
        $allTerminal = $terminalRuns->count() >= $totalEngines;

        $newStatus = $scanJob->status;
        $failureReason = $scanJob->failure_reason;

        if ($hasDenied) {
            $newStatus = 'denied';
            $failureReason = $runs->firstWhere('status', 'denied')?->failure_reason;
        } elseif ($allTerminal) {
            $newStatus = $hasFailed ? 'failed' : 'completed';
            $progress = 100;
            if ($hasFailed && empty($failureReason)) {
                $failureReason = $runs->firstWhere('status', 'failed')?->failure_reason;
            }
        }

        // Sync individual engine statuses into engine_plan JSON
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
            'status' => $newStatus,
            'progress' => $progress,
            'engine_plan' => $updatedPlan,
            'failure_reason' => $failureReason,
            'finished_at' => in_array($newStatus, ['completed', 'failed', 'denied'], true) ? now() : null,
        ])->save();
    }
}
