<?php

namespace App\Services\Engines\Adapters;

class GrypeAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'grype';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.grype_image';
    }

    protected function reportFilename(): string
    {
        return 'grype-report.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
    {
        $targetSource = $imageTag ?: "dir:{$containerWorkspacePath}";

        $cmd = [
            $targetSource,
            '-o',
            'json',
            '--file',
            "{$containerOutputPath}/{$this->reportFilename()}",
            '--check-for-app-update=false',
        ];

        if (! $imageTag) {
            $cmd = array_merge($cmd, [
                '--exclude',
                '**/vendor/**',
                '--exclude',
                '**/node_modules/**',
                '--exclude',
                '**/.git/**',
                '--exclude',
                '**/storage/**',
            ]);
        }

        return $cmd;
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        $matches = is_array($decoded) ? ($decoded['matches'] ?? []) : [];

        if (! is_array($matches)) {
            return [];
        }

        return collect($matches)->map(function (array $match): array {
            $vuln = $match['vulnerability'] ?? [];
            $artifact = $match['artifact'] ?? [];
            $vulnId = $vuln['id'] ?? 'grype.vulnerability';
            $severity = $this->severity($vuln['severity'] ?? null);

            $locations = $artifact['locations'] ?? [];
            $firstPath = isset($locations[0]['path'])
                ? ltrim(str_replace('/repo', '', $locations[0]['path']), '/\\')
                : 'Container / Package Manifest';

            $cvss = null;
            if (! empty($vuln['cvss'])) {
                $firstCvss = $vuln['cvss'][0] ?? [];
                $cvss = $firstCvss['metrics']['baseScore'] ?? null;
            }

            $fixVersions = $vuln['fix']['versions'] ?? [];
            $fixVersionStr = ! empty($fixVersions) ? implode(', ', $fixVersions) : ($vuln['fix']['state'] ?? 'not-fixed');

            return [
                'rule_id' => $vulnId,
                'title' => "Grype: {$vulnId} in {$artifact['name']}@{$artifact['version']}",
                'severity' => $severity,
                'severity_raw' => $vuln['severity'] ?? null,
                'confidence' => 0.85,
                'asset_type' => 'container',
                'file_path' => $firstPath,
                'line_start' => 1,
                'line_end' => 1,
                'cve' => str_starts_with(strtoupper($vulnId), 'CVE') ? $vulnId : null,
                'cvss' => $cvss,
                'owasp' => 'A06:2021-Vulnerable and Outdated Components',
                'cwe' => 'CWE-1395',
                'evidence_summary' => [
                    'package_name' => $artifact['name'] ?? null,
                    'installed_version' => $artifact['version'] ?? null,
                    'package_type' => $artifact['type'] ?? null,
                    'fix_version' => $fixVersionStr,
                ],
                'evidence' => $vuln['description'] ?? "Grype identified vulnerability {$vulnId} in package {$artifact['name']}.",
            ];
        })->values()->all();
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
