<?php

namespace App\Services\Engines\Adapters;

class KubescapeAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'kubescape';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.kubescape_image';
    }

    protected function reportFilename(): string
    {
        return 'kubescape-report.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
    {
        return [
            'scan',
            $containerWorkspacePath,
            '--format',
            'json',
            '--output',
            "{$containerOutputPath}/{$this->reportFilename()}",
            '--view',
            'control',
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $findings = [];
        $controls = $decoded['controls'] ?? $decoded['results'] ?? [];

        if (is_array($controls)) {
            foreach ($controls as $control) {
                $status = $control['status'] ?? $control['complianceStatus'] ?? '';
                if (in_array(strtolower((string) $status), ['passed', 'action_required_none'], true)) {
                    continue;
                }

                $controlId = $control['id'] ?? $control['controlID'] ?? 'kubescape.misconfig';
                $name = $control['name'] ?? $controlId;
                $score = $control['baseScore'] ?? $control['score'] ?? 5;
                $description = $control['description'] ?? 'Kubernetes security posture issue';

                $findings[] = [
                    'rule_id' => $controlId,
                    'title' => "Kubescape: {$name}",
                    'severity' => $this->severityByScore((float) $score),
                    'severity_raw' => (string) $score,
                    'confidence' => 0.85,
                    'asset_type' => 'repository',
                    'file_path' => 'k8s/manifest.yaml',
                    'cwe' => 'CWE-1008',
                    'owasp' => 'A05:2021-Security Misconfiguration',
                    'evidence_summary' => [
                        'control_id' => $controlId,
                        'name' => $name,
                        'score' => $score,
                    ],
                    'evidence' => "{$name} - {$description}",
                ];
            }
        }

        return $findings;
    }

    private function severityByScore(float $score): string
    {
        if ($score >= 8.0) {
            return 'high';
        }

        if ($score >= 4.0) {
            return 'medium';
        }

        return 'low';
    }
}
