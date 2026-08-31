<?php

namespace App\Services\Engines\Adapters;

class TrivyAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'trivy';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.trivy_image';
    }

    protected function reportFilename(): string
    {
        return 'trivy-report.json';
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath): array
    {
        return [
            'fs',
            '--scanners',
            'vuln',
            '--format',
            'json',
            '--output',
            "{$containerOutputPath}/{$this->reportFilename()}",
            $containerWorkspacePath,
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        $results = is_array($decoded) ? ($decoded['Results'] ?? []) : [];

        return collect($results)
            ->flatMap(fn (array $result): array => collect($result['Vulnerabilities'] ?? [])
                ->map(fn (array $vulnerability): array => [
                    'rule_id' => $vulnerability['VulnerabilityID'] ?? 'trivy.vulnerability',
                    'title' => $vulnerability['Title'] ?? $vulnerability['VulnerabilityID'] ?? 'Trivy vulnerability',
                    'severity' => $this->severity($vulnerability['Severity'] ?? null),
                    'severity_raw' => $vulnerability['Severity'] ?? null,
                    'confidence' => 0.8,
                    'asset_type' => 'repository',
                    'file_path' => $result['Target'] ?? null,
                    'cve' => $vulnerability['VulnerabilityID'] ?? null,
                    'cvss' => $vulnerability['CVSS']['nvd']['V3Score'] ?? $vulnerability['CVSS']['redhat']['V3Score'] ?? null,
                    'evidence_summary' => [
                        'package' => $vulnerability['PkgName'] ?? null,
                        'installed_version' => $vulnerability['InstalledVersion'] ?? null,
                        'fixed_version' => $vulnerability['FixedVersion'] ?? null,
                    ],
                    'evidence' => $vulnerability['Description'] ?? 'Trivy detected a dependency vulnerability.',
                ])->all())
            ->values()
            ->all();
    }

    private function severity(?string $severity): string
    {
        return match (strtolower((string) $severity)) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'informational',
        };
    }
}
