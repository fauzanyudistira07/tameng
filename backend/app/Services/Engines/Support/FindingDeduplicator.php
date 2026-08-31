<?php

namespace App\Services\Engines\Support;

use App\Models\Finding;
use App\Models\FindingEvidence;
use App\Models\ScanJob;
use App\Models\ScanRun;
use Illuminate\Support\Str;

class FindingDeduplicator
{
    /**
     * Ingest and deduplicate a list of normalized findings for a given ScanRun.
     * Clusters multi-engine evidence under a single Primary Finding.
     */
    public function ingest(array $normalizedFindings, ScanJob $scanJob, ScanRun $scanRun, string $engineKey): array
    {
        $savedFindings = [];

        foreach ($normalizedFindings as $item) {
            $fingerprint = $item['fingerprint'] ?? UniversalFindingNormalizer::computeFingerprint(
                $scanJob->project_id,
                $item['asset_type'] ?? 'repository',
                $item['file_path'] ?? $item['endpoint'] ?? null,
                $item['cwe'] ?? $item['cve'] ?? $item['rule_id'] ?? 'rule',
                $item['line_start'] ?? null
            );

            // 1. Look for existing finding in this scan job or project with the same dedup_key
            $finding = Finding::firstOrNew([
                'scan_job_id' => $scanJob->id,
                'dedup_key' => $fingerprint,
            ]);

            $isNew = ! $finding->exists;

            if ($isNew) {
                $code = 'FND-'.strtoupper(now()->format('YmdHis')).'-'.strtoupper(Str::random(4));
                $finding->code = $code;
                $finding->project_id = $scanJob->project_id;
                $finding->scan_run_id = $scanRun->id;
                $finding->engine_key = $engineKey;
                $finding->rule_id = $item['rule_id'] ?? 'secsys.rule';
                $finding->title = $item['title'] ?? 'Security Finding Detected';
                $finding->severity_raw = $item['severity_raw'] ?? 'medium';
                $finding->severity = $item['severity'] ?? 'medium';
                $finding->confidence = (float) ($item['confidence'] ?? 0.80);
                $finding->asset_type = $item['asset_type'] ?? 'repository';
                $finding->asset_identifier = $item['asset_identifier'] ?? $item['file_path'] ?? '-';
                $finding->file_path = $item['file_path'] ?? null;
                $finding->line_start = $item['line_start'] ?? null;
                $finding->line_end = $item['line_end'] ?? null;
                $finding->http_method = $item['http_method'] ?? null;
                $finding->endpoint = $item['endpoint'] ?? null;
                $finding->cwe = $item['cwe'] ?? null;
                $finding->owasp = $item['owasp'] ?? null;
                $finding->cve = $item['cve'] ?? null;
                $finding->cvss = $item['cvss'] ?? null;
                $finding->status = 'open';
                $finding->evidence_summary = $item['evidence_summary'] ?? [];
                $finding->normalization_metadata = $item['normalization_metadata'] ?? [];
                $finding->save();
            } else {
                // Multi-engine cross-validation boost: Increase confidence
                $finding->confidence = min(0.99, (float) $finding->confidence + 0.10);
                // If the new finding has higher severity, escalate primary severity
                if ($this->severityWeight($item['severity'] ?? '') > $this->severityWeight($finding->severity)) {
                    $finding->severity = $item['severity'];
                }
                $finding->save();
            }

            // 2. Attach Evidence Record
            FindingEvidence::updateOrCreate(
                [
                    'finding_id' => $finding->id,
                    'engine_key' => $engineKey,
                ],
                [
                    'engine_version' => $item['normalization_metadata']['engine_version'] ?? '1.0.0',
                    'confidence' => (float) ($item['confidence'] ?? 0.80),
                    'fingerprint_hash' => $fingerprint,
                    'evidence_summary' => $item['evidence_summary'] ?? [],
                    'raw_artifact_path' => $item['raw_artifact_path'] ?? null,
                ]
            );

            $savedFindings[] = $finding;
        }

        return $savedFindings;
    }

    private function severityWeight(string $severity): int
    {
        return match (strtolower($severity)) {
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'low' => 2,
            'informational' => 1,
            default => 0,
        };
    }
}
