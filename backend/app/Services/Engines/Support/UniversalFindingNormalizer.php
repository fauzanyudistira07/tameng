<?php

namespace App\Services\Engines\Support;

class UniversalFindingNormalizer
{
    /**
     * Normalize severity string across all engines to SecSys standard: critical, high, medium, low, informational.
     */
    public static function normalizeSeverity(?string $severity): string
    {
        $s = strtolower(trim((string) $severity));

        return match ($s) {
            'critical', 'blocker', 'fatal' => 'critical',
            'high', 'error', 'severe' => 'high',
            'medium', 'warning', 'moderate', 'warn' => 'medium',
            'low', 'notice', 'caution', 'minor' => 'low',
            'info', 'informational', 'note', 'suggestion', 'none' => 'informational',
            default => 'medium',
        };
    }

    /**
     * Compute a deterministic SHA-256 fingerprint for finding deduplication & clustering.
     */
    public static function computeFingerprint(
        int|string $projectId,
        string $assetType,
        ?string $filePathOrEndpoint,
        ?string $ruleOrCwe,
        ?int $lineStart = null
    ): string {
        $normalizedLocation = strtolower(trim((string) $filePathOrEndpoint));
        $normalizedRule = strtolower(trim((string) $ruleOrCwe));
        // Cluster lines within ±3 lines tolerance window if applicable
        $lineBucket = $lineStart ? (int) floor($lineStart / 5) * 5 : 0;

        $raw = sprintf(
            'proj:%s|type:%s|loc:%s|rule:%s|bucket:%d',
            $projectId,
            $assetType,
            $normalizedLocation,
            $normalizedRule,
            $lineBucket
        );

        return hash('sha256', $raw);
    }

    /**
     * Standardize a normalized finding array from any security scanner.
     */
    public static function formatItem(array $data, int|string $projectId): array
    {
        $severity = self::normalizeSeverity($data['severity'] ?? $data['severity_raw'] ?? null);
        $assetType = $data['asset_type'] ?? 'repository';
        $location = $data['file_path'] ?? $data['endpoint'] ?? $data['location'] ?? null;
        $rule = $data['cwe'] ?? $data['cve'] ?? $data['rule_id'] ?? 'secsys.finding';
        $lineStart = isset($data['line_start']) ? (int) $data['line_start'] : null;

        $fingerprint = self::computeFingerprint(
            $projectId,
            $assetType,
            $location,
            $rule,
            $lineStart
        );

        return [
            'fingerprint' => $fingerprint,
            'rule_id' => $data['rule_id'] ?? 'secsys.rule',
            'title' => $data['title'] ?? 'Security Finding Detected',
            'severity' => $severity,
            'severity_raw' => $data['severity_raw'] ?? $severity,
            'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : 0.80,
            'asset_type' => $assetType,
            'asset_identifier' => $data['asset_identifier'] ?? $location,
            'file_path' => $data['file_path'] ?? null,
            'line_start' => $lineStart,
            'line_end' => isset($data['line_end']) ? (int) $data['line_end'] : null,
            'http_method' => $data['http_method'] ?? null,
            'endpoint' => $data['endpoint'] ?? null,
            'cwe' => $data['cwe'] ?? null,
            'owasp' => $data['owasp'] ?? null,
            'cve' => $data['cve'] ?? null,
            'cvss' => isset($data['cvss']) ? (float) $data['cvss'] : null,
            'status' => 'open',
            'evidence_summary' => is_array($data['evidence_summary'] ?? null) ? $data['evidence_summary'] : ['raw' => $data['evidence'] ?? null],
            'normalization_metadata' => [
                'engine' => $data['engine'] ?? 'unknown',
                'engine_version' => $data['engine_version'] ?? '1.0.0',
                'normalized_at' => now()->toIso8601String(),
            ],
        ];
    }
}
