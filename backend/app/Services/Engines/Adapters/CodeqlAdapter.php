<?php

namespace App\Services\Engines\Adapters;

class CodeqlAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'codeql';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.codeql_image';
    }

    protected function reportFilename(): string
    {
        return 'codeql-report.sarif';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1, 2, 3];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
    {
        return [
            'codeql',
            'database',
            'analyze',
            '--format=sarif-latest',
            '--output=' . "{$containerOutputPath}/{$this->reportFilename()}",
            '--threads=2',
            $containerWorkspacePath,
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $runs = $decoded['runs'] ?? [];
        $findings = [];

        foreach ($runs as $run) {
            $results = $run['results'] ?? [];
            if (! is_array($results)) {
                continue;
            }

            $rules = collect($run['tool']['driver']['rules'] ?? [])->keyBy('id');

            foreach ($results as $result) {
                $ruleId = $result['ruleId'] ?? 'codeql.finding';
                $ruleMeta = $rules->get($ruleId);
                $message = $result['message']['text'] ?? 'CodeQL detected vulnerability';
                $location = $result['locations'][0]['physicalLocation'] ?? [];
                $filePath = $location['artifactLocation']['uri'] ?? null;
                $lineStart = $location['region']['startLine'] ?? null;
                $lineEnd = $location['region']['endLine'] ?? null;
                $level = $result['level'] ?? $ruleMeta['defaultConfiguration']['level'] ?? 'warning';

                $cwe = null;
                $tags = $ruleMeta['properties']['tags'] ?? [];
                foreach ($tags as $tag) {
                    if (str_starts_with(strtolower((string) $tag), 'external/cwe/cwe-')) {
                        $cwe = strtoupper(str_replace('external/cwe/', '', (string) $tag));
                        break;
                    }
                }

                $findings[] = [
                    'rule_id' => $ruleId,
                    'title' => $ruleMeta['shortDescription']['text'] ?? $ruleId,
                    'severity' => $this->severity($level),
                    'severity_raw' => $level,
                    'confidence' => 0.85,
                    'asset_type' => 'repository',
                    'file_path' => $filePath,
                    'line_start' => $lineStart,
                    'line_end' => $lineEnd,
                    'cwe' => $cwe ?? 'CWE-79',
                    'owasp' => 'A03:2021-Injection',
                    'evidence_summary' => [
                        'rule_id' => $ruleId,
                        'message' => $message,
                        'location' => $location,
                    ],
                    'evidence' => $message . ($filePath ? " in {$filePath}:{$lineStart}" : ''),
                ];
            }
        }

        return $findings;
    }

    private function severity(?string $level): string
    {
        return match (strtolower((string) $level)) {
            'error' => 'high',
            'warning' => 'medium',
            'note' => 'low',
            default => 'informational',
        };
    }
}
