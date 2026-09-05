<?php

namespace App\Services\Engines\Adapters;

class MobSFAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'mobsf';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.mobsf_image';
    }

    protected function reportFilename(): string
    {
        return 'mobsf-report.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
    {
        return [
            '--json',
            '-o',
            "{$containerOutputPath}/{$this->reportFilename()}",
            '--no-fail',
            $containerWorkspacePath,
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded) || empty($decoded['results'])) {
            return [];
        }

        $results = $decoded['results'];
        $findings = [];

        foreach ($results as $ruleId => $ruleData) {
            $metadata = $ruleData['metadata'] ?? [];
            $description = $metadata['description'] ?? "MobSF security finding: {$ruleId}";
            $severity = $this->severity($metadata['severity'] ?? null);
            $owasp = $metadata['owasp-mobile'] ?? 'OWASP Mobile Top 10';
            $cwe = $metadata['cwe'] ?? 'CWE-200';
            $masvs = $metadata['masvs'] ?? null;
            $reference = $metadata['reference'] ?? null;
            $files = $ruleData['files'] ?? [];

            if (empty($files)) {
                $findings[] = [
                    'rule_id' => $ruleId,
                    'title' => "MobSF: {$description}",
                    'severity' => $severity,
                    'severity_raw' => $metadata['severity'] ?? null,
                    'confidence' => 0.85,
                    'asset_type' => 'mobile',
                    'file_path' => 'Mobile Manifest / Config',
                    'line_start' => 1,
                    'line_end' => 1,
                    'owasp' => $owasp,
                    'cwe' => $cwe,
                    'evidence_summary' => [
                        'rule_id' => $ruleId,
                        'masvs' => $masvs,
                        'reference' => $reference,
                    ],
                    'evidence' => $description,
                ];
                continue;
            }

            foreach ($files as $file) {
                $rawPath = $file['file_path'] ?? '';
                $cleanedPath = ltrim(str_replace('/repo', '', $rawPath), '/\\');
                $matchLines = $file['match_lines'] ?? [1, 1];
                $lineStart = isset($matchLines[0]) ? (int) $matchLines[0] : 1;
                $lineEnd = isset($matchLines[1]) ? (int) $matchLines[1] : $lineStart;
                $matchString = $file['match_string'] ?? null;

                $findings[] = [
                    'rule_id' => $ruleId,
                    'title' => "MobSF: {$description}",
                    'severity' => $severity,
                    'severity_raw' => $metadata['severity'] ?? null,
                    'confidence' => 0.85,
                    'asset_type' => 'mobile',
                    'file_path' => $cleanedPath ?: 'Mobile Source Code',
                    'line_start' => $lineStart,
                    'line_end' => $lineEnd,
                    'owasp' => $owasp,
                    'cwe' => $cwe,
                    'evidence_summary' => [
                        'rule_id' => $ruleId,
                        'masvs' => $masvs,
                        'reference' => $reference,
                        'match_snippet' => $matchString,
                    ],
                    'evidence' => $matchString ? "{$description} | Snippet: {$matchString}" : $description,
                ];
            }
        }

        return $findings;
    }

    private function severity(?string $level): string
    {
        return match (strtoupper((string) $level)) {
            'ERROR' => 'high',
            'WARNING' => 'medium',
            'INFO' => 'low',
            default => 'informational',
        };
    }
}
