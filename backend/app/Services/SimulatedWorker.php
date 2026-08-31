<?php

namespace App\Services;

use App\Models\ScanJob;
use App\Models\ScanRun;
use App\Models\Worker;
use App\Services\Engines\EngineRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SimulatedWorker
{
    public function __construct(
        private readonly EngineRunner $engineRunner,
        private readonly FindingNormalizer $findingNormalizer,
    ) {}

    public function process(ScanJob $scanJob): ScanJob
    {
        return DB::transaction(function () use ($scanJob): ScanJob {
            $scanJob->refresh();

            if ($scanJob->status !== 'queued') {
                throw ValidationException::withMessages([
                    'scan_job_id' => ['Only queued scan jobs can be processed by the simulated worker.'],
                ]);
            }

            $worker = Worker::query()->updateOrCreate([
                'name' => 'local-sim-worker',
            ], [
                'hostname' => gethostname() ?: 'local',
                'status' => 'busy',
                'capabilities' => ['mode' => 'simulated', 'engines' => collect($scanJob->engine_plan)->pluck('engine_key')->values()],
                'resource_limits' => ['network' => 'disabled', 'scanner_execution' => 'disabled'],
                'last_seen_at' => now(),
            ]);

            $scanJob->forceFill([
                'status' => 'running',
                'started_at' => now(),
                'progress' => 0,
            ])->save();

            $enginePlan = collect($scanJob->engine_plan);
            $total = max($enginePlan->count(), 1);

            foreach ($enginePlan as $index => $engine) {
                $engineKey = $engine['engine_key'];
                $engineRunPlan = $this->engineRunner->plan($scanJob, $engineKey);
                $decision = $engineRunPlan->policyDecision;

                if ($decision['decision'] !== 'allow') {
                    ScanRun::query()->create([
                        'scan_job_id' => $scanJob->id,
                        'worker_id' => $worker->id,
                        'engine_key' => $engineKey,
                        'status' => 'denied',
                        'exit_code' => null,
                        'started_at' => now(),
                        'finished_at' => now(),
                        'failure_reason' => $decision['reason_code'],
                        'command_spec' => [
                            ...$engineRunPlan->toCommandSpec(),
                            'execution' => 'denied',
                        ],
                        'runtime_metrics' => ['policy_decision_id' => $decision['policy_decision']->id],
                    ]);

                    $scanJob->forceFill([
                        'status' => 'denied',
                        'finished_at' => now(),
                        'failure_reason' => $decision['reason_code'],
                    ])->save();

                    $worker->forceFill(['status' => 'online', 'last_seen_at' => now()])->save();

                    return $scanJob->refresh();
                }

                $scanRun = ScanRun::query()->create([
                    'scan_job_id' => $scanJob->id,
                    'worker_id' => $worker->id,
                    'engine_key' => $engineKey,
                    'status' => 'completed',
                    'exit_code' => 0,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'failure_reason' => null,
                    'command_spec' => [
                        ...$engineRunPlan->toCommandSpec(),
                        'mode' => 'simulated',
                        'scanner_execution' => false,
                    ],
                    'runtime_metrics' => [
                        'policy_decision_id' => $decision['policy_decision']->id,
                        'duration_ms' => 0,
                    ],
                ]);

                foreach ($this->simulatedFindingsForEngine($engineKey) as $rawFinding) {
                    $this->findingNormalizer->normalizeSimulatedFinding($scanRun, $rawFinding);
                }

                $scanJob->forceFill([
                    'progress' => (int) floor((($index + 1) / $total) * 100),
                ])->save();
            }

            $scanJob->forceFill([
                'status' => 'completed',
                'progress' => 100,
                'finished_at' => now(),
            ])->save();

            $worker->forceFill(['status' => 'online', 'last_seen_at' => now()])->save();

            return $scanJob->refresh()->load(['scanRuns', 'project:id,name,code', 'repository:id,name', 'target:id,name,type', 'scanProfile:id,key,name', 'authorization:id,code']);
        });
    }

    private function simulatedFindingsForEngine(string $engineKey): array
    {
        return match ($engineKey) {
            'semgrep' => [[
                'rule_id' => 'simulated.semgrep.sql-injection-candidate',
                'title' => 'Simulated SQL Injection Candidate',
                'severity' => 'high',
                'severity_raw' => 'WARNING',
                'confidence' => 0.72,
                'asset_type' => 'repository',
                'file_path' => 'app/Http/Controllers/UserController.php',
                'line_start' => 87,
                'line_end' => 91,
                'cwe' => 'CWE-89',
                'owasp' => 'A03',
                'evidence_summary' => ['pattern' => 'user input appears near raw query construction'],
                'evidence' => 'Simulated SAST evidence. No repository content was scanned.',
            ]],
            'gitleaks' => [[
                'rule_id' => 'simulated.gitleaks.generic-api-key',
                'title' => 'Simulated Exposed API Key',
                'severity' => 'critical',
                'severity_raw' => 'critical',
                'confidence' => 0.80,
                'asset_type' => 'repository',
                'file_path' => '.env.example',
                'line_start' => 12,
                'line_end' => 12,
                'cwe' => 'CWE-798',
                'evidence_summary' => ['secret_type' => 'generic api key'],
                'evidence' => 'Simulated secret finding. No secret value was generated or stored.',
            ]],
            'trivy' => [[
                'rule_id' => 'simulated.trivy.vulnerable-package',
                'title' => 'Simulated Vulnerable Dependency',
                'severity' => 'medium',
                'severity_raw' => 'MEDIUM',
                'confidence' => 0.76,
                'asset_type' => 'repository',
                'file_path' => 'package-lock.json',
                'cve' => 'CVE-0000-0000',
                'cvss' => 5.3,
                'evidence_summary' => ['package' => 'example-package', 'fixed_version' => '1.2.3'],
                'evidence' => 'Simulated dependency finding. CVE identifier is placeholder.',
            ]],
            'zap' => [[
                'rule_id' => 'simulated.zap.missing-security-header',
                'title' => 'Simulated Missing Security Header',
                'severity' => 'low',
                'severity_raw' => 'Low',
                'confidence' => 0.68,
                'asset_type' => 'target',
                'http_method' => 'GET',
                'endpoint' => '/',
                'cwe' => 'CWE-693',
                'evidence_summary' => ['header' => 'Content-Security-Policy'],
                'evidence' => 'Simulated passive DAST finding. No HTTP request was sent.',
            ]],
            'nuclei' => [[
                'rule_id' => 'simulated.nuclei.exposed-panel',
                'title' => 'Simulated Exposed Admin Panel',
                'severity' => 'medium',
                'severity_raw' => 'medium',
                'confidence' => 0.70,
                'asset_type' => 'target',
                'http_method' => 'GET',
                'endpoint' => '/admin',
                'evidence_summary' => ['template_mode' => 'safe allowlist simulation'],
                'evidence' => 'Simulated nuclei finding. No template was executed.',
            ]],
            default => [],
        };
    }
}
