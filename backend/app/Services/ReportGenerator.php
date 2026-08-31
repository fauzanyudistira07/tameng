<?php

namespace App\Services;

use App\Models\Finding;
use App\Models\Report;
use App\Models\ScanJob;
use App\Models\User;

class ReportGenerator
{
    public function generateStandardJson(ScanJob $scanJob, User $user): Report
    {
        $scanJob->load([
            'project:id,name,code',
            'repository:id,name,provider,url,default_branch',
            'target:id,name,type,base_url,hostname',
            'scanProfile:id,key,name',
            'authorization:id,code,status,allowed_scope_snapshot,denied_scope_snapshot,policy_snapshot',
            'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,started_at,finished_at,failure_reason',
        ]);

        $findings = Finding::query()
            ->with('evidence')
            ->where('scan_job_id', $scanJob->id)
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low', 'informational')")
            ->orderByDesc('id')
            ->get();

        $summary = [
            'total' => $findings->count(),
            'critical' => $findings->where('severity', 'critical')->count(),
            'high' => $findings->where('severity', 'high')->count(),
            'medium' => $findings->where('severity', 'medium')->count(),
            'low' => $findings->where('severity', 'low')->count(),
            'informational' => $findings->where('severity', 'informational')->count(),
        ];

        $hasScannerExecution = $scanJob->scanRuns
            ->contains(fn ($scanRun): bool => (bool) data_get($scanRun->command_spec, 'scanner_execution', false));
        $failedRuns = $scanJob->scanRuns->where('status', 'failed')->values();
        $completedRuns = $scanJob->scanRuns->where('status', 'completed')->values();
        $isPartialReport = $scanJob->status === 'failed' && $completedRuns->isNotEmpty();

        $content = [
            'title' => 'Laporan Standar Pemindaian Keamanan SecSys',
            'description' => $isPartialReport
                ? 'Laporan ini bersifat parsial: sebagian engine berhasil berjalan dan sebagian engine gagal. Temuan dari engine yang berhasil tetap disimpan agar bisa ditinjau.'
                : 'Laporan ini dibuat dari hasil scan yang sudah selesai diproses oleh pipeline SecSys.',
            'generated_at' => now()->toISOString(),
            'generated_by' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'project' => $scanJob->project,
            'repository' => $scanJob->repository,
            'target' => $scanJob->target,
            'authorization' => $scanJob->authorization,
            'scan' => [
                'id' => $scanJob->id,
                'code' => $scanJob->code,
                'status' => $scanJob->status,
                'status_label' => match ($scanJob->status) {
                    'completed' => 'Selesai',
                    'failed' => $isPartialReport ? 'Selesai parsial' : 'Gagal',
                    'denied' => 'Ditolak',
                    'running' => 'Sedang berjalan',
                    'queued' => 'Dalam antrean',
                    default => $scanJob->status,
                },
                'failure_reason' => $scanJob->failure_reason,
                'profile' => $scanJob->scanProfile,
                'queued_at' => $scanJob->queued_at?->toISOString(),
                'started_at' => $scanJob->started_at?->toISOString(),
                'finished_at' => $scanJob->finished_at?->toISOString(),
                'engine_plan' => $scanJob->engine_plan,
                'runs' => $scanJob->scanRuns,
            ],
            'execution_summary' => [
                'mode' => $hasScannerExecution ? 'Scanner real' : 'Simulasi',
                'outcome' => $isPartialReport ? 'Selesai parsial' : ($scanJob->status === 'completed' ? 'Selesai' : 'Tidak selesai'),
                'completed_engines' => $completedRuns->pluck('engine_key')->values(),
                'failed_engines' => $failedRuns->map(fn ($scanRun): array => [
                    'engine_key' => $scanRun->engine_key,
                    'failure_reason' => $scanRun->failure_reason,
                    'exit_code' => $scanRun->exit_code,
                ])->values(),
            ],
            'risk_summary' => $summary,
            'findings' => $findings->map(fn (Finding $finding): array => [
                'code' => $finding->code,
                'severity' => $finding->severity,
                'status' => $finding->status,
                'title' => $finding->title,
                'engine_key' => $finding->engine_key,
                'rule_id' => $finding->rule_id,
                'asset_type' => $finding->asset_type,
                'asset_identifier' => $finding->asset_identifier,
                'location' => [
                    'file_path' => $finding->file_path,
                    'line_start' => $finding->line_start,
                    'line_end' => $finding->line_end,
                    'http_method' => $finding->http_method,
                    'endpoint' => $finding->endpoint,
                ],
                'classification' => [
                    'cwe' => $finding->cwe,
                    'owasp' => $finding->owasp,
                    'cve' => $finding->cve,
                    'cvss' => $finding->cvss,
                    'confidence' => $finding->confidence,
                ],
                'evidence_summary' => $finding->evidence_summary,
                'evidence' => $finding->evidence,
            ])->values(),
            'notes' => [
                'Laporan MVP ini dibuat dari record yang tersimpan di SecSys.',
                $hasScannerExecution
                    ? 'Minimal satu engine menggunakan adapter scanner real. Temuan tetap perlu divalidasi manual sebelum keputusan perbaikan.'
                    : 'Output engine saat ini adalah simulasi dan belum boleh dipakai sebagai bukti scanner produksi.',
                $isPartialReport
                    ? 'Karena ada engine yang gagal, baca execution_summary untuk melihat engine mana yang berhasil dan gagal.'
                    : 'Semua ringkasan risiko dihitung dari temuan yang berhasil dinormalisasi.',
            ],
        ];

        return Report::query()->create([
            'scan_job_id' => $scanJob->id,
            'type' => 'standard',
            'status' => 'generated',
            'format' => 'json',
            'generated_by' => $user->id,
            'generated_at' => now(),
            'metadata' => [
                'content' => $content,
                'finding_count' => $summary['total'],
                'risk_summary' => $summary,
                'simulated' => ! $hasScannerExecution,
                'scanner_execution' => $hasScannerExecution,
                'partial' => $isPartialReport,
                'description' => $content['description'],
            ],
        ]);
    }
}
