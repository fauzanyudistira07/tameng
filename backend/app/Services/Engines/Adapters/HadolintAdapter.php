<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\File;

class HadolintAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'hadolint';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.hadolint_image';
    }

    protected function reportFilename(): string
    {
        return 'hadolint-report.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1, 2];
    }

    public function execute(EngineRunPlan $plan): EngineExecutionResult
    {
        $plan->scanJob->loadMissing(['repository', 'target']);

        if ($plan->scanJob->target?->type === 'container') {
            $commandSpec = [
                ...$plan->toCommandSpec(),
                'mode' => 'real_adapter_guarded',
                'runtime' => config('secsys.engine_runtime'),
                'scanner_execution' => false,
            ];

            return EngineExecutionResult::skipped($commandSpec, 'HADOLINT_REQUIRES_DOCKERFILE');
        }

        $workspacePath = $plan->scanJob->repository?->metadata['local_path'] ?? null;

        // Skip gracefully if repository has no Dockerfile
        if ($workspacePath && File::isDirectory($workspacePath)) {
            $dockerfiles = File::glob("{$workspacePath}/*Dockerfile*") ?: [];
            if (empty($dockerfiles)) {
                $commandSpec = [
                    ...$plan->toCommandSpec(),
                    'mode' => 'real_adapter_guarded',
                    'runtime' => config('secsys.engine_runtime'),
                    'scanner_execution' => false,
                ];

                return EngineExecutionResult::skipped($commandSpec, 'NO_DOCKERFILE_FOUND');
            }
        }

        return parent::execute($plan);
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath): array
    {
        return [
            '/bin/hadolint',
            '-f',
            'json',
            '--no-fail',
            "{$containerWorkspacePath}/Dockerfile",
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)->map(fn (array $item): array => [
            'rule_id' => $item['code'] ?? 'hadolint.rule',
            'title' => $item['message'] ?? 'Hadolint Dockerfile issue',
            'severity' => $this->severity($item['level'] ?? null),
            'severity_raw' => $item['level'] ?? null,
            'confidence' => 0.85,
            'asset_type' => 'repository',
            'file_path' => isset($item['file']) ? str_replace('/repo/', '', $item['file']) : 'Dockerfile',
            'line_start' => $item['line'] ?? 1,
            'line_end' => $item['line'] ?? 1,
            'cwe' => 'CWE-1004',
            'owasp' => 'A05:2021-Security Misconfiguration',
            'evidence_summary' => [
                'rule_code' => $item['code'] ?? null,
                'column' => $item['column'] ?? null,
            ],
            'evidence' => ($item['message'] ?? '').' (Rule: '.($item['code'] ?? '-').')',
        ])->values()->all();
    }

    private function severity(?string $level): string
    {
        return match (strtolower((string) $level)) {
            'error' => 'high',
            'warning' => 'medium',
            'info' => 'low',
            default => 'informational',
        };
    }
}
