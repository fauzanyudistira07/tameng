<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;

class SyftAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'syft';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.syft_image';
    }

    protected function reportFilename(): string
    {
        return 'syft-report.json';
    }

    protected function successfulExitCodes(): array
    {
        return [0, 1];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath): array
    {
        return [
            "dir:{$containerWorkspacePath}",
            '-o',
            'json',
            '--file',
            "{$containerOutputPath}/{$this->reportFilename()}",
        ];
    }

    protected function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $artifacts = $decoded['artifacts'] ?? [];
        if (! is_array($artifacts)) {
            return [];
        }

        // Syft generates an SBOM inventory. We surface license compliance or package inventory findings
        $findings = [];
        foreach ($artifacts as $pkg) {
            $name = $pkg['name'] ?? 'unknown-package';
            $version = $pkg['version'] ?? 'unknown';
            $type = $pkg['type'] ?? 'package';
            $licenses = $pkg['licenses'] ?? [];
            $locations = $pkg['locations'] ?? [];
            $firstPath = isset($locations[0]['path']) ? ltrim(str_replace('/repo', '', $locations[0]['path']), '/\\') : 'Package manifest';

            $licenseNames = collect($licenses)->map(fn ($l) => is_array($l) ? ($l['value'] ?? $l['spdxExpression'] ?? '') : (string) $l)->filter()->implode(', ');

            // Check for copyleft or unlicensed packages for compliance alert
            $isGpl = str_contains(strtoupper($licenseNames), 'GPL') || str_contains(strtoupper($licenseNames), 'AGPL');
            $severity = $isGpl ? 'low' : 'informational';

            $findings[] = [
                'rule_id' => 'syft.sbom.component',
                'title' => "SBOM: {$name}@{$version} ({$type})",
                'severity' => $severity,
                'severity_raw' => $severity,
                'confidence' => 0.95,
                'asset_type' => 'repository',
                'file_path' => $firstPath ?: null,
                'line_start' => 1,
                'line_end' => 1,
                'cwe' => 'CWE-1357',
                'owasp' => 'A06:2021-Vulnerable and Outdated Components',
                'evidence_summary' => [
                    'package_name' => $name,
                    'version' => $version,
                    'package_type' => $type,
                    'licenses' => $licenseNames ?: 'Unspecified',
                    'cpes' => $pkg['cpes'] ?? [],
                ],
                'evidence' => "Component {$name} v{$version} cataloged via {$type}. License: ".($licenseNames ?: 'None Specified'),
            ];
        }

        return $findings;
    }
}
