<?php

namespace App\Services;

use App\Models\Finding;
use App\Models\FindingEvidence;
use App\Models\ScanRun;
use Illuminate\Support\Str;

class FindingNormalizer
{
    public function normalizeSimulatedFinding(ScanRun $scanRun, array $rawFinding): Finding
    {
        return $this->normalizeFinding($scanRun, $rawFinding, 'simulated_worker', 'simulated');
    }

    public function normalizeGitleaksFinding(ScanRun $scanRun, array $rawFinding): Finding
    {
        return $this->normalizeFinding($scanRun, $rawFinding, 'gitleaks_adapter', 'gitleaks');
    }

    public function normalizeEngineFinding(ScanRun $scanRun, array $rawFinding, string $engineKey): Finding
    {
        return match ($engineKey) {
            'gitleaks' => $this->normalizeGitleaksFinding($scanRun, $rawFinding),
            default => $this->normalizeFinding($scanRun, $rawFinding, "{$engineKey}_adapter", $engineKey),
        };
    }

    private function normalizeFinding(ScanRun $scanRun, array $rawFinding, string $source, string $evidenceType): Finding
    {
        $scanRun->loadMissing('scanJob.repository', 'scanJob.target');
        $scanJob = $scanRun->scanJob;

        $assetType = $rawFinding['asset_type']
            ?? ($scanJob->repository_id ? 'repository' : 'target');

        $assetIdentifier = $rawFinding['asset_identifier']
            ?? $scanJob->repository?->name
            ?? $scanJob->target?->hostname
            ?? $scanJob->target?->name
            ?? 'unknown';

        $dedupKey = hash('sha256', implode('|', [
            $scanJob->id,
            $scanRun->engine_key,
            $rawFinding['rule_id'] ?? 'simulated',
            $assetType,
            $assetIdentifier,
            $rawFinding['file_path'] ?? '',
            $rawFinding['endpoint'] ?? '',
            $rawFinding['line_start'] ?? '',
        ]));

        $finding = Finding::query()->updateOrCreate([
            'scan_job_id' => $scanJob->id,
            'dedup_key' => $dedupKey,
        ], [
            'code' => 'FIND-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'project_id' => $scanJob->project_id,
            'scan_run_id' => $scanRun->id,
            'engine_key' => $scanRun->engine_key,
            'rule_id' => $rawFinding['rule_id'] ?? null,
            'title' => $rawFinding['title'],
            'severity_raw' => $rawFinding['severity_raw'] ?? $rawFinding['severity'],
            'severity' => $rawFinding['severity'],
            'confidence' => $rawFinding['confidence'] ?? null,
            'asset_type' => $assetType,
            'asset_identifier' => $assetIdentifier,
            'file_path' => $rawFinding['file_path'] ?? null,
            'line_start' => $rawFinding['line_start'] ?? null,
            'line_end' => $rawFinding['line_end'] ?? null,
            'http_method' => $rawFinding['http_method'] ?? null,
            'endpoint' => $rawFinding['endpoint'] ?? null,
            'cwe' => $rawFinding['cwe'] ?? null,
            'owasp' => $rawFinding['owasp'] ?? null,
            'cve' => $rawFinding['cve'] ?? null,
            'cvss' => $rawFinding['cvss'] ?? null,
            'status' => 'open',
            'evidence_summary' => $rawFinding['evidence_summary'] ?? null,
            'normalization_metadata' => [
                'source' => $source,
                'scanner_execution' => $source !== 'simulated_worker',
                'normalized_at' => now()->toISOString(),
            ],
        ]);

        FindingEvidence::query()->updateOrCreate([
            'finding_id' => $finding->id,
            'type' => $evidenceType,
        ], [
            'content' => $rawFinding['evidence'] ?? 'Simulated finding used to validate the finding pipeline.',
            'metadata' => [
                'engine_key' => $scanRun->engine_key,
                'scan_run_id' => $scanRun->id,
            ],
        ]);

        return $finding;
    }
}
