<?php

namespace App\Services\Engines\Adapters;

class OsvAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'osv';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.osv_image';
    }

    protected function reportFilename(): string
    {
        return 'osv-report.json';
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath): array
    {
        return [
            '--format',
            'json',
            '--output',
            "{$containerOutputPath}/{$this->reportFilename()}",
            '--recursive',
            $containerWorkspacePath,
        ];
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        $results = is_array($decoded) ? ($decoded['results'] ?? []) : [];

        return collect($results)
            ->flatMap(fn (array $result): array => collect($result['packages'] ?? [])
                ->flatMap(fn (array $package): array => collect($package['vulnerabilities'] ?? [])
                    ->map(fn (array $vulnerability): array => [
                        'rule_id' => $vulnerability['id'] ?? 'osv.vulnerability',
                        'title' => $vulnerability['summary'] ?? $vulnerability['id'] ?? 'OSV vulnerability',
                        'severity' => 'medium',
                        'severity_raw' => $vulnerability['database_specific']['severity'] ?? null,
                        'confidence' => 0.8,
                        'asset_type' => 'repository',
                        'file_path' => $result['source']['path'] ?? null,
                        'cve' => $vulnerability['aliases'][0] ?? $vulnerability['id'] ?? null,
                        'evidence_summary' => [
                            'package' => $package['package']['name'] ?? null,
                            'version' => $package['package']['version'] ?? null,
                            'aliases' => $vulnerability['aliases'] ?? [],
                        ],
                        'evidence' => $vulnerability['details'] ?? 'OSV detected a dependency vulnerability.',
                    ])->all())->all())
            ->values()
            ->all();
    }
}
