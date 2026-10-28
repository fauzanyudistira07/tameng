<?php

namespace App\Services\Engines\Adapters;

class SonarQubeAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'sonarqube';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.sonarqube_image';
    }

    protected function reportFilename(): string
    {
        return 'sonar-report.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1, 2];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
    {
        return [
            'sonar-scanner',
            "-Dsonar.projectKey=secsys-scan",
            "-Dsonar.sources={$containerWorkspacePath}",
            "-Dsonar.working.directory={$containerOutputPath}/.scannerwork",
            "-Dsonar.scanner.dumpToFile={$containerOutputPath}/{$this->reportFilename()}",
            '-Dsonar.exclusions=**/vendor/**,**/node_modules/**,**/.git/**',
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $issues = $decoded['issues'] ?? $decoded['hotspots'] ?? [];
        if (! is_array($issues)) {
            return [];
        }

        return collect($issues)->map(function (array $issue): array {
            $component = $issue['component'] ?? $issue['file'] ?? '';
            $filePath = ltrim(str_replace(['/repo', 'secsys-scan:'], '', $component), '/\\');
            $severity = $issue['severity'] ?? $issue['vulnerabilityProbability'] ?? 'MEDIUM';

            return [
                'rule_id' => $issue['rule'] ?? $issue['securityCategory'] ?? 'sonarqube.issue',
                'title' => $issue['message'] ?? $issue['rule'] ?? 'SonarQube code finding',
                'severity' => $this->severity($severity),
                'severity_raw' => $severity,
                'confidence' => 0.80,
                'asset_type' => 'repository',
                'file_path' => $filePath ?: null,
                'line_start' => $issue['line'] ?? $issue['textRange']['startLine'] ?? null,
                'line_end' => $issue['textRange']['endLine'] ?? null,
                'cwe' => $issue['cwe'] ?? 'CWE-710',
                'owasp' => 'A05:2021-Security Misconfiguration',
                'evidence_summary' => [
                    'rule' => $issue['rule'] ?? null,
                    'type' => $issue['type'] ?? null,
                    'effort' => $issue['effort'] ?? null,
                ],
                'evidence' => ($issue['message'] ?? 'SonarQube finding') . ($filePath ? " in {$filePath}" : ''),
            ];
        })->values()->all();
    }

    private function severity(?string $severity): string
    {
        return match (strtoupper((string) $severity)) {
            'BLOCKER', 'CRITICAL' => 'critical',
            'MAJOR', 'HIGH' => 'high',
            'MINOR', 'MEDIUM' => 'medium',
            'INFO', 'LOW' => 'low',
            default => 'informational',
        };
    }
}
