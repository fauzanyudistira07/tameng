<?php

namespace App\Services;

use App\Models\Finding;
use App\Models\Report;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;

class ReportPdfGenerator
{
    private static bool $autoloadRegistered = false;

    public function render(Report $report): string
    {
        $this->registerPdfAutoloader();

        $report->loadMissing([
            'scanJob.project:id,name,code',
            'scanJob.repository:id,name,url',
            'scanJob.target:id,name,type,base_url,hostname',
            'generator:id,name,email',
        ]);

        $findingsData = data_get($report->metadata, 'content.findings', []);
        $remediationService = app(AiRemediationService::class);

        $enrichedFindings = collect($findingsData)->map(function ($item) use ($remediationService) {
            $findingModel = null;
            if (! empty($item['code'])) {
                $findingModel = Finding::where('code', $item['code'])->first();
            }

            if ($findingModel) {
                $guidance = $remediationService->generateGuidance($findingModel);
                $item['remediation'] = [
                    'category' => $guidance['category'] ?? null,
                    'summary' => $guidance['summary'] ?? null,
                    'cause' => $guidance['cause'] ?? null,
                    'mitigation_checklist' => $guidance['mitigation_checklist'] ?? [],
                    'code_diff' => $guidance['code_diff'] ?? null,
                    'attack_vector' => $guidance['attack_vector'] ?? null,
                    'business_impact' => $guidance['business_impact'] ?? null,
                ];
            } else {
                $item['remediation'] = [
                    'category' => 'General Security Best Practice',
                    'summary' => 'Perbarui komponen ke versi terbaru dan pastikan input pengguna divalidasi dan dienkripsi.',
                    'cause' => 'Ditemukan implementasi atau konfigurasi yang belum memenuhi standar pengamanan minimum.',
                    'mitigation_checklist' => [
                        'Upgrade library/paket terkait ke versi patch keamanan terbaru.',
                        'Lakukan pengujian regresi pada fungsionalitas yang terpengaruh.',
                    ],
                    'code_diff' => null,
                    'attack_vector' => null,
                    'business_impact' => null,
                ];
            }

            return $item;
        })->all();

        $summary = $report->metadata['risk_summary'] ?? [];
        $posture = $this->assessPosture($summary);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.security-pdf', [
            'report' => $report,
            'content' => $report->metadata['content'] ?? [],
            'summary' => $summary,
            'posture' => $posture,
            'findings' => $enrichedFindings,
            'execution' => data_get($report->metadata, 'content.execution_summary', []),
        ])->render());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        // Render footer page numbers on all pages
        $canvas->page_text(260, 816, "Halaman {PAGE_NUM} dari {PAGE_COUNT}", $font, 8, [0.45, 0.5, 0.58]);

        return $dompdf->output();
    }

    private function assessPosture(array $summary): array
    {
        $critical = $summary['critical'] ?? 0;
        $high = $summary['high'] ?? 0;
        $medium = $summary['medium'] ?? 0;
        $low = $summary['low'] ?? 0;

        if ($critical > 0) {
            return [
                'grade' => 'F',
                'label' => 'KRITIS (CRITICAL RISK)',
                'description' => 'Aset memiliki kerentanan dengan tingkat keparahan kritis yang dapat dieksploitasi langsung. Diperlukan tindakan perbaikan segera sebelum rilis produksi.',
                'color' => '#dc2626',
                'bg_color' => '#fef2f2',
                'border_color' => '#f87171',
                'score' => max(15, 100 - ($critical * 20 + $high * 8 + $medium * 3)),
            ];
        }

        if ($high > 0) {
            return [
                'grade' => 'D',
                'label' => 'TINGGI (HIGH RISK)',
                'description' => 'Aset memiliki temuan berisiko tinggi (misal: dependensi rentan atau konfigurasi tidak aman). Disarankan melakukan perbaikan dalam siklus sprint terdekat.',
                'color' => '#ea580c',
                'bg_color' => '#fff7ed',
                'border_color' => '#fb923c',
                'score' => max(40, 100 - ($high * 10 + $medium * 4 + $low * 1)),
            ];
        }

        if ($medium > 0) {
            return [
                'grade' => 'C',
                'label' => 'SEDANG (MEDIUM RISK)',
                'description' => 'Ditemukan potensi celah tingkat sedang. Dianjurkan untuk ditinjau dan diperbaiki sesuai standar kepatuhan.',
                'color' => '#d97706',
                'bg_color' => '#fffbeb',
                'border_color' => '#facc15',
                'score' => max(65, 100 - ($medium * 5 + $low * 2)),
            ];
        }

        if ($low > 0) {
            return [
                'grade' => 'B',
                'label' => 'RENDAH (LOW RISK)',
                'description' => 'Sebagian besar pengujian aman, hanya terdapat beberapa rekomendasi minor (*best practice*).',
                'color' => '#2563eb',
                'bg_color' => '#eff6ff',
                'border_color' => '#93c5fd',
                'score' => max(85, 100 - ($low * 2)),
            ];
        }

        return [
            'grade' => 'A',
            'label' => 'SANGAT BAIK (SECURE / PASS)',
            'description' => 'Seluruh pengujian multi-engine berhasil dan tidak ditemukan kerentanan aktif. Postur keamanan aset dalam kondisi prima.',
            'color' => '#16a34a',
            'bg_color' => '#f0fdf4',
            'border_color' => '#86efac',
            'score' => 98,
        ];
    }

    public function filename(Report $report): string
    {
        $scanCode = $report->scanJob?->code ?? 'report-'.$report->id;

        return Str::slug('secsys-'.$scanCode.'-'.$report->id).'.pdf';
    }

    private function registerPdfAutoloader(): void
    {
        if (self::$autoloadRegistered) {
            self::$autoloadRegistered = true;

            return;
        }

        $vendorPath = base_path('vendor');

        spl_autoload_register(function (string $class) use ($vendorPath): void {
            $paths = [
                'Dompdf\\' => $vendorPath.'/dompdf/dompdf/src/',
                'FontLib\\' => $vendorPath.'/dompdf/php-font-lib/src/FontLib/',
                'Svg\\' => $vendorPath.'/dompdf/php-svg-lib/src/Svg/',
                'Sabberworm\\CSS\\' => $vendorPath.'/sabberworm/php-css-parser/src/',
                'Masterminds\\' => $vendorPath.'/masterminds/html5/src/',
                'Safe\\Exceptions\\' => [
                    $vendorPath.'/thecodingmachine/safe/lib/Exceptions/',
                    $vendorPath.'/thecodingmachine/safe/generated/Exceptions/',
                ],
            ];

            if ($class === 'Dompdf\\Cpdf') {
                require_once $vendorPath.'/dompdf/dompdf/lib/Cpdf.php';

                return;
            }

            foreach ($paths as $prefix => $basePath) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $relativeClass = substr($class, strlen($prefix));
                $candidatePaths = is_array($basePath) ? $basePath : [$basePath];

                foreach ($candidatePaths as $candidatePath) {
                    $file = $candidatePath.str_replace('\\', '/', $relativeClass).'.php';

                    if (is_file($file)) {
                        require_once $file;

                        return;
                    }
                }

                return;
            }
        });

        foreach ([
            $vendorPath.'/thecodingmachine/safe/lib/special_cases.php',
            $vendorPath.'/thecodingmachine/safe/generated/8.1/classobj.php',
            $vendorPath.'/thecodingmachine/safe/generated/array.php',
            $vendorPath.'/thecodingmachine/safe/generated/filesystem.php',
            $vendorPath.'/thecodingmachine/safe/generated/json.php',
            $vendorPath.'/thecodingmachine/safe/generated/pcre.php',
            $vendorPath.'/thecodingmachine/safe/generated/strings.php',
            $vendorPath.'/thecodingmachine/safe/generated/xml.php',
            $vendorPath.'/sabberworm/php-css-parser/src/Rule/Rule.php',
            $vendorPath.'/sabberworm/php-css-parser/src/RuleSet/RuleContainer.php',
        ] as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }

        self::$autoloadRegistered = true;
    }
}
