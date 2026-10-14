<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Support\UniversalFindingNormalizer;

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

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
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
                ->flatMap(function (array $package) use ($result): array {
                    $seenCves = [];
                    $vulns = collect($package['vulnerabilities'] ?? [])->filter(function (array $vulnerability) use (&$seenCves) {
                        $cve = null;
                        foreach (($vulnerability['aliases'] ?? []) as $alias) {
                            if (str_starts_with(strtoupper($alias), 'CVE-')) {
                                $cve = strtoupper($alias);
                                break;
                            }
                        }
                        if (! $cve && str_starts_with(strtoupper($vulnerability['id'] ?? ''), 'CVE-')) {
                            $cve = strtoupper($vulnerability['id']);
                        }
                        if ($cve) {
                            if (isset($seenCves[$cve])) {
                                return false;
                            }
                            $seenCves[$cve] = true;
                        }

                        return true;
                    });

                    return $vulns->map(function (array $vulnerability) use ($result, $package): array {
                        $rawSev = $vulnerability['database_specific']['severity']
                            ?? $vulnerability['ecosystem_specific']['severity']
                            ?? null;

                        $cve = null;
                        foreach (($vulnerability['aliases'] ?? []) as $alias) {
                            if (str_starts_with(strtoupper($alias), 'CVE-')) {
                                $cve = $alias;
                                break;
                            }
                        }
                        if (! $cve && str_starts_with(strtoupper($vulnerability['id'] ?? ''), 'CVE-')) {
                            $cve = $vulnerability['id'];
                        }

                        $cvss = null;
                        if (! empty($vulnerability['severity'])) {
                            foreach ($vulnerability['severity'] as $s) {
                                if (isset($s['score']) && is_numeric($s['score'])) {
                                    $cvss = (float) $s['score'];
                                    break;
                                }
                            }
                        }

                        $severity = UniversalFindingNormalizer::normalizeSeverity($rawSev);
                        if ($severity === 'medium' && $cvss !== null) {
                            if ($cvss >= 9.0) {
                                $severity = 'critical';
                            } elseif ($cvss >= 7.0) {
                                $severity = 'high';
                            } elseif ($cvss >= 4.0) {
                                $severity = 'medium';
                            } else {
                                $severity = 'low';
                            }
                        }

                        $pkgName = $package['package']['name'] ?? 'unknown';
                        $pkgVersion = $package['package']['version'] ?? 'unknown';
                        $vulnId = $vulnerability['id'] ?? 'osv.vulnerability';
                        $rawSource = $result['source']['path'] ?? 'Package Manifest';
                        $cleanSource = ltrim(str_replace('/repo', '', $rawSource), '/\\');

                        return [
                            'rule_id' => $vulnId,
                            'title' => "OSV: {$vulnId} in {$pkgName}@{$pkgVersion}",
                            'severity' => $severity,
                            'severity_raw' => $rawSev ?: $severity,
                            'confidence' => 0.85,
                            'asset_type' => 'repository',
                            'file_path' => $cleanSource ?: 'Package Manifest',
                            'line_start' => 1,
                            'line_end' => 1,
                            'cve' => $cve,
                            'cvss' => $cvss,
                            'cwe' => 'CWE-1395',
                            'owasp' => 'A06:2021-Vulnerable and Outdated Components',
                            'evidence_summary' => [
                                'package' => $pkgName,
                                'version' => $pkgVersion,
                                'ecosystem' => $package['package']['ecosystem'] ?? null,
                                'aliases' => $vulnerability['aliases'] ?? [],
                            ],
                            'evidence' => $vulnerability['details'] ?? "OSV detected vulnerability {$vulnId} in package {$pkgName}.",
                        ];
                    })->all();
                })->all())
            ->values()
            ->all();
    }
}

