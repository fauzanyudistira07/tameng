<?php

namespace App\Services;

use App\Models\ScanJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookNotificationService
{
    /**
     * Send webhook notification on scan completion / failure.
     */
    public function notifyScanResult(ScanJob $scanJob): void
    {
        $webhookUrl = config('services.tameng.webhook_url') ?: env('TAMENG_WEBHOOK_URL');
        if (empty($webhookUrl)) {
            return;
        }

        $scanJob->loadMissing(['project', 'repository', 'target', 'scanRuns', 'reports']);

        $findingsCount = 0;
        $criticalCount = 0;
        $highCount = 0;

        if ($scanJob->reports->isNotEmpty()) {
            $report = $scanJob->reports->first();
            $risk = $report->metadata['risk_summary'] ?? $report->metadata['content']['risk_summary'] ?? [];
            $criticalCount = $risk['critical'] ?? 0;
            $highCount = $risk['high'] ?? 0;
            $findingsCount = ($risk['critical'] ?? 0) + ($risk['high'] ?? 0) + ($risk['medium'] ?? 0) + ($risk['low'] ?? 0);
        }

        $isSuccess = $scanJob->status === 'completed';
        $statusEmoji = $isSuccess ? '✅' : '❌';
        $severityBadge = $criticalCount > 0 ? '🚨 KRITIS DITEMUKAN' : ($highCount > 0 ? '⚠️ TINGGI DITEMUKAN' : '🛡️ AMAN');

        $targetName = $scanJob->repository?->name ?? $scanJob->target?->name ?? $scanJob->target?->base_url ?? 'Aset Target';

        $payload = [
            'content' => "{$statusEmoji} **[TAMENG Security Alert]** Pemindaian {$scanJob->code} {$scanJob->status}!",
            'embeds' => [
                [
                    'title' => "🛡️ Laporan Pemindaian Keamanan TAMENG",
                    'description' => "Pemindaian keamanan pada proyek **{$scanJob->project?->name}** telah selesai diproses.",
                    'color' => $isSuccess ? ($criticalCount > 0 ? 15158332 : 3066993) : 10038562,
                    'fields' => [
                        [
                            'name' => 'Kode Scan',
                            'value' => "`{$scanJob->code}`",
                            'inline' => true,
                        ],
                        [
                            'name' => 'Target Aset',
                            'value' => $targetName,
                            'inline' => true,
                        ],
                        [
                            'name' => 'Status Eksekusi',
                            'value' => strtoupper($scanJob->status),
                            'inline' => true,
                        ],
                        [
                            'name' => 'Ringkasan Temuan',
                            'value' => "Total: **{$findingsCount}** (Kritis: `{$criticalCount}`, Tinggi: `{$highCount}`)",
                            'inline' => false,
                        ],
                        [
                            'name' => 'Status Risiko',
                            'value' => $severityBadge,
                            'inline' => true,
                        ],
                    ],
                    'footer' => [
                        'text' => 'TAMENG Cyber Defense & Security Scan System',
                    ],
                    'timestamp' => now()->toISOString(),
                ],
            ],
        ];

        try {
            Http::timeout(5)->post($webhookUrl, $payload);
        } catch (Throwable $e) {
            Log::warning('Gagal mengirim notifikasi webhook TAMENG: ' . $e->getMessage());
        }
    }
}
