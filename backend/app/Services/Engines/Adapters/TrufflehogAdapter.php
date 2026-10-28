<?php

namespace App\Services\Engines\Adapters;

class TrufflehogAdapter extends RepositoryDockerAdapter
{
    public function key(): string
    {
        return 'trufflehog';
    }

    protected function imageConfigKey(): string
    {
        return 'secsys.trufflehog_image';
    }

    protected function reportFilename(): string
    {
        return 'trufflehog-report.json';
    }

    protected function successfulExitCodes(): array
    {
        // TruffleHog returns 0 (clean) or 183 (verified/unverified secrets found)
        return [0, 1, 183];
    }

    protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath, ?string $imageTag = null): array
    {
        return [
            'filesystem',
            $containerWorkspacePath,
            '--json',
            '--no-verification',
            '--exclude-paths=/repo/vendor,/repo/node_modules,/repo/.git,/repo/dist,/repo/build',
        ];
    }

    protected function parseFindings(string $output): array
    {
        if (trim($output) === '') {
            return [];
        }

        $lines = explode("\n", trim($output));
        $findings = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            $item = json_decode($line, true);
            if (! is_array($item)) {
                continue;
            }

            $detector = $item['DetectorName'] ?? $item['detector_name'] ?? 'Secret';
            $filePath = $item['SourceMetadata']['Data']['Filesystem']['file']
                ?? $item['SourceMetadata']['Data']['Git']['file']
                ?? null;

            if ($filePath) {
                $filePath = ltrim(str_replace('/repo', '', $filePath), '/\\');
            }

            $lineStart = $item['SourceMetadata']['Data']['Filesystem']['line']
                ?? $item['SourceMetadata']['Data']['Git']['line']
                ?? null;

            $verified = $item['Verified'] ?? false;
            $rawSecret = (string) ($item['Raw'] ?? '');
            $maskedSecret = strlen($rawSecret) > 6
                ? substr($rawSecret, 0, 3) . '***' . substr($rawSecret, -3)
                : '******';

            $findings[] = [
                'rule_id' => 'trufflehog.' . strtolower((string) $detector),
                'title' => "TruffleHog detected {$detector} credential",
                'severity' => $verified ? 'critical' : 'high',
                'severity_raw' => $verified ? 'VERIFIED' : 'UNVERIFIED',
                'confidence' => $verified ? 0.95 : 0.85,
                'asset_type' => 'repository',
                'file_path' => $filePath ?: null,
                'line_start' => $lineStart ? (int) $lineStart : null,
                'line_end' => $lineStart ? (int) $lineStart : null,
                'cwe' => 'CWE-798',
                'owasp' => 'A07:2021-Identification and Authentication Failures',
                'evidence_summary' => [
                    'detector' => $detector,
                    'verified' => $verified,
                    'decoder' => $item['DecoderName'] ?? null,
                ],
                'evidence' => "Exposed {$detector} token detected: {$maskedSecret}" . ($filePath ? " in {$filePath}" : ''),
            ];
        }

        return $findings;
    }
}
