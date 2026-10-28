<?php

namespace App\Services\Engines\Adapters;

class SemgrepAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'semgrep';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.semgrep_image';
    }

    protected function reportFilename(): string
    {
        return 'semgrep-report.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1, 2, 3];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
    {
        $cmd = [
            'semgrep',
            'scan',
            '--config',
            'p/default',
        ];

        $sourceRulesDir = app_path('Services/Engines/Rules/semgrep');
        $customRulesDir = storage_path('app/private/rules/semgrep');
        if (\Illuminate\Support\Facades\File::isDirectory($sourceRulesDir)) {
            \Illuminate\Support\Facades\File::copyDirectory($sourceRulesDir, $customRulesDir);
        }

        if (\Illuminate\Support\Facades\File::isDirectory($customRulesDir)) {
            $cmd[] = '--config';
            $cmd[] = '/rules';
        }

        return array_merge($cmd, [
            '--disable-version-check',
            '--exclude',
            'vendor',
            '--exclude',
            'node_modules',
            '--exclude',
            '.git',
            '--exclude',
            'dist',
            '--exclude',
            'build',
            '--exclude',
            'storage',
            '--json',
            '--output',
            "{$containerOutputPath}/{$this->reportFilename()}",
            $containerWorkspacePath,
        ]);
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        $results = is_array($decoded) ? ($decoded['results'] ?? []) : [];

        if (! is_array($results)) {
            return [];
        }

        return collect($results)->map(fn (array $finding): array => [
            'rule_id' => $finding['check_id'] ?? 'semgrep.rule',
            'title' => $finding['extra']['message'] ?? $finding['check_id'] ?? 'Semgrep finding',
            'severity' => $this->severity($finding['extra']['severity'] ?? null, isset($finding['extra']['metadata']['cvss']) ? (float) $finding['extra']['metadata']['cvss'] : null),
            'severity_raw' => $finding['extra']['severity'] ?? null,
            'confidence' => 0.85,
            'asset_type' => 'repository',
            'file_path' => $finding['path'] ?? null,
            'line_start' => $finding['start']['line'] ?? null,
            'line_end' => $finding['end']['line'] ?? null,
            'cwe' => $finding['extra']['metadata']['cwe'][0] ?? null,
            'owasp' => $finding['extra']['metadata']['owasp'][0] ?? null,
            'cve' => $finding['extra']['metadata']['cve'] ?? null,
            'cvss' => isset($finding['extra']['metadata']['cvss']) ? (float) $finding['extra']['metadata']['cvss'] : null,
            'evidence_summary' => [
                'check_id' => $finding['check_id'] ?? null,
                'metadata' => $finding['extra']['metadata'] ?? null,
            ],
            'evidence' => $finding['extra']['lines'] ?? 'Semgrep detected a code finding.',
        ])->values()->all();
    }

    private function severity(?string $severity, ?float $cvss = null): string
    {
        if ($cvss !== null && $cvss >= 9.0) {
            return 'critical';
        }

        return match (strtolower((string) $severity)) {
            'critical' => 'critical',
            'error' => 'high',
            'warning' => 'medium',
            'info' => 'low',
            default => 'informational',
        };
    }
}
