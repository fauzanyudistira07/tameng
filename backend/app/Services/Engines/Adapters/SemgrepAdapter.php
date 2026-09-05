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

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath): array
    {
        return [
            'semgrep',
            'scan',
            '--config',
            'p/default',
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
        ];
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
            'severity' => $this->severity($finding['extra']['severity'] ?? null),
            'severity_raw' => $finding['extra']['severity'] ?? null,
            'confidence' => 0.75,
            'asset_type' => 'repository',
            'file_path' => $finding['path'] ?? null,
            'line_start' => $finding['start']['line'] ?? null,
            'line_end' => $finding['end']['line'] ?? null,
            'cwe' => $finding['extra']['metadata']['cwe'][0] ?? null,
            'owasp' => $finding['extra']['metadata']['owasp'][0] ?? null,
            'evidence_summary' => [
                'check_id' => $finding['check_id'] ?? null,
                'metadata' => $finding['extra']['metadata'] ?? null,
            ],
            'evidence' => $finding['extra']['lines'] ?? 'Semgrep detected a code finding.',
        ])->values()->all();
    }

    private function severity(?string $severity): string
    {
        return match (strtolower((string) $severity)) {
            'error' => 'high',
            'warning' => 'medium',
            'info' => 'low',
            default => 'informational',
        };
    }
}
