<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\File;

class CheckovAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'checkov';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.checkov_image';
    }

    protected function reportFilename(): string
    {
        return 'results_json.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1, 2];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath): array
    {
        return [
            '-d',
            $containerWorkspacePath,
            '--output',
            'json',
            '--output-file-path',
            $containerOutputPath,
            '--soft-fail',
            '--compact',
            '--skip-path',
            "{$containerWorkspacePath}/vendor",
            '--skip-path',
            "{$containerWorkspacePath}/node_modules",
            '--skip-path',
            "{$containerWorkspacePath}/.git",
            '--skip-path',
            "{$containerWorkspacePath}/storage",
            '--skip-path',
            "{$containerWorkspacePath}/dist",
            '--skip-path',
            "{$containerWorkspacePath}/build",
            '--skip-framework',
            'sca_package',
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        // Checkov can return a single object or an array of framework scan results
        $resultsLists = isset($decoded['results']) ? [$decoded] : $decoded;
        $findings = [];

        foreach ($resultsLists as $frameworkResult) {
            $failedChecks = $frameworkResult['results']['failed_checks'] ?? [];
            if (! is_array($failedChecks)) {
                continue;
            }

            foreach ($failedChecks as $check) {
                $filePath = $check['file_path'] ?? $check['file_abs_path'] ?? 'IaC Config';
                $filePath = ltrim(str_replace('/repo', '', $filePath), '/\\');

                $findings[] = [
                    'rule_id' => $check['check_id'] ?? 'checkov.iac_check',
                    'title' => $check['check_name'] ?? $check['check_id'] ?? 'Checkov IaC finding',
                    'severity' => $this->severity($check['severity'] ?? null),
                    'severity_raw' => $check['severity'] ?? null,
                    'confidence' => 0.85,
                    'asset_type' => 'repository',
                    'file_path' => $filePath ?: null,
                    'line_start' => $check['file_line_range'][0] ?? null,
                    'line_end' => $check['file_line_range'][1] ?? null,
                    'cwe' => 'CWE-1008',
                    'owasp' => 'A05:2021-Security Misconfiguration',
                    'evidence_summary' => [
                        'check_id' => $check['check_id'] ?? null,
                        'guideline' => $check['guideline'] ?? null,
                        'resource' => $check['resource'] ?? null,
                    ],
                    'evidence' => ($check['check_name'] ?? '').' in '.$filePath.' (Resource: '.($check['resource'] ?? '-').')',
                ];
            }
        }

        return $findings;
    }

    private function severity(?string $severity): string
    {
        return match (strtoupper((string) $severity)) {
            'CRITICAL' => 'critical',
            'HIGH' => 'high',
            'MEDIUM' => 'medium',
            'LOW' => 'low',
            default => 'medium',
        };
    }
}
