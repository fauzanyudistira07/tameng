<?php

namespace App\Services\Engines\Adapters;

class DependencyCheckAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'dependency_check';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.dependency_check_image';
    }

    protected function reportFilename(): string
    {
        return 'dependency-check-report.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1, 14];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
    {
        return [
            '--scan',
            $containerWorkspacePath,
            '--format',
            'JSON',
            '--out',
            "{$containerOutputPath}/{$this->reportFilename()}",
            '--project',
            'secsys-scan',
            '--noupdate',
            '--disableAssembly',
            '--disableCentral',
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $dependencies = $decoded['dependencies'] ?? [];
        if (! is_array($dependencies)) {
            return [];
        }

        $findings = [];

        foreach ($dependencies as $dependency) {
            $vulnerabilities = $dependency['vulnerabilities'] ?? [];
            if (! is_array($vulnerabilities)) {
                continue;
            }

            $depName = $dependency['fileName'] ?? $dependency['filePath'] ?? 'dependency';
            $depPath = ltrim(str_replace('/repo', '', (string) ($dependency['filePath'] ?? '')), '/\\');

            foreach ($vulnerabilities as $vuln) {
                $cveName = $vuln['name'] ?? 'CVE-UNKNOWN';
                $severity = $vuln['severity'] ?? 'MEDIUM';
                $score = $vuln['cvssv3']['baseScore'] ?? $vuln['cvssv2']['score'] ?? null;
                $cwe = $vuln['cwes'][0] ?? 'CWE-1395';

                $findings[] = [
                    'rule_id' => $cveName,
                    'title' => "OWASP DC: {$cveName} in {$depName}",
                    'severity' => $this->severity($severity),
                    'severity_raw' => $severity,
                    'confidence' => 0.85,
                    'asset_type' => 'repository',
                    'file_path' => $depPath ?: $depName,
                    'cve' => $cveName,
                    'cvss' => $score ? (float) $score : null,
                    'cwe' => $cwe,
                    'owasp' => 'A06:2021-Vulnerable and Outdated Components',
                    'evidence_summary' => [
                        'cve' => $cveName,
                        'dependency' => $depName,
                        'cvss' => $score,
                        'source' => $vuln['source'] ?? 'NVD',
                    ],
                    'evidence' => ($vuln['description'] ?? 'Known dependency vulnerability.') . " ({$depName})",
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
