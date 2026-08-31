<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $content['title'] ?? 'Laporan Audit Keamanan SecSys' }}</title>
    <style>
        @page {
            margin: 20mm 15mm 18mm 15mm;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1e293b;
            font-size: 9.5pt;
            line-height: 1.45;
        }
        h1, h2, h3, h4, h5 {
            margin: 0;
            color: #0f172a;
            font-weight: bold;
        }
        h1 { font-size: 18pt; line-height: 1.25; }
        h2 { font-size: 12.5pt; margin-top: 14pt; margin-bottom: 6pt; padding-bottom: 3pt; border-bottom: 1.5pt solid #cbd5e1; color: #0f172a; }
        h3 { font-size: 10.5pt; margin-bottom: 4pt; color: #1e293b; }
        h4 { font-size: 9.5pt; margin-bottom: 3pt; color: #1e3a8a; }
        p { margin: 0 0 4pt; }
        
        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 8pt;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 5pt 7pt;
            vertical-align: top;
            font-size: 9pt;
        }
        th {
            background: #f1f5f9;
            text-align: left;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            font-weight: bold;
        }
        
        .no-border-table, .no-border-table td, .no-border-table th {
            border: none !important;
            padding: 0 !important;
        }

        .cover-page {
            page-break-after: always;
            padding-top: 10pt;
        }
        .cover-header {
            border-bottom: 3pt solid #1e3a8a;
            padding-bottom: 12pt;
            margin-bottom: 16pt;
        }
        .brand-tag {
            font-size: 8.5pt;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #2563eb;
            margin-bottom: 4pt;
        }
        .confidential-pill {
            display: inline-block;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 2pt 6pt;
            border-radius: 3pt;
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .posture-card {
            border: 1.5pt solid {{ $posture['border_color'] ?? '#cbd5e1' }};
            background: {{ $posture['bg_color'] ?? '#f8fafc' }};
            border-radius: 6pt;
            padding: 12pt 14pt;
            margin-bottom: 16pt;
        }
        .posture-grade {
            font-size: 32pt;
            font-weight: 800;
            color: {{ $posture['color'] ?? '#0f172a' }};
            line-height: 1;
            text-align: center;
        }
        .posture-label {
            font-size: 11pt;
            font-weight: bold;
            color: {{ $posture['color'] ?? '#0f172a' }};
            text-transform: uppercase;
            margin-bottom: 3pt;
        }

        .metric-tile {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            border-radius: 4pt;
            padding: 6pt 8pt;
            text-align: center;
        }
        .metric-tile strong {
            display: block;
            font-size: 14pt;
            font-weight: bold;
            line-height: 1.1;
        }
        .metric-tile span {
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            font-weight: bold;
        }

        .badge {
            display: inline-block;
            padding: 2pt 5pt;
            border-radius: 3pt;
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .critical { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .high { background: #ffedd5; color: #9a3412; border: 1px solid #fb923c; }
        .medium { background: #fef3c7; color: #92400e; border: 1px solid #facc15; }
        .low { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .informational { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .progress-bar-bg {
            background: #e2e8f0;
            border-radius: 2pt;
            height: 7pt;
            width: 100%;
            overflow: hidden;
            display: block;
        }
        .progress-bar-fill {
            height: 7pt;
            display: block;
        }

        .finding-card {
            border: 1px solid #cbd5e1;
            border-radius: 4pt;
            margin-bottom: 10pt;
            page-break-inside: avoid;
            background: #ffffff;
        }
        .finding-card-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 6pt 9pt;
        }
        .finding-card-body {
            padding: 8pt 9pt;
        }

        .remediation-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 4pt;
            padding: 7pt 9pt;
            margin-top: 6pt;
        }
        .remediation-title {
            color: #166534;
            font-weight: bold;
            font-size: 9pt;
            margin-bottom: 3pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .code-block {
            background: #0f172a;
            color: #f1f5f9;
            font-family: monospace;
            font-size: 8pt;
            padding: 5pt 7pt;
            border-radius: 3pt;
            margin: 4pt 0 2pt;
            white-space: pre-wrap;
            word-break: break-all;
            line-height: 1.35;
        }
        .checklist {
            margin: 3pt 0 0 12pt;
            padding: 0;
            font-size: 8.5pt;
        }
        .checklist li {
            margin-bottom: 2pt;
            color: #1e293b;
        }

        .section-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4pt;
            padding: 8pt 10pt;
            margin-bottom: 10pt;
        }
        .running-header {
            position: fixed;
            top: -14mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #94a3b8;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 2pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .running-footer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 2pt;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="running-header">
        <table class="no-border-table" style="width: 100%;">
            <tr>
                <td>SecSys Enterprise Security Assessment Report</td>
                <td style="text-align: right;">Dokumen Rahasia • {{ data_get($content, 'scan.code', '-') }}</td>
            </tr>
        </table>
    </div>

    <div class="running-footer">
        <table class="no-border-table" style="width: 100%;">
            <tr>
                <td>Sistem Audit & Orkestrasi Keamanan SecSys</td>
                <td style="text-align: right;">Klasifikasi: Internal Confidential</td>
            </tr>
        </table>
    </div>

    <!-- ========================================== -->
    <!-- HALAMAN 1: EXECUTIVE COVER & POSTURE PAGE -->
    <!-- ========================================== -->
    <div class="cover-page">
        <!-- Header Banner -->
        <div class="cover-header">
            <table class="no-border-table" style="width: 100%;">
                <tr>
                    <td>
                        <div class="brand-tag">SecSys • Enterprise Security Intelligence</div>
                        <h1>Laporan Audit Keamanan Kode Sumber & Rekomendasi Remediasi</h1>
                        <p style="color: #64748b; font-size: 9.5pt; margin-top: 3pt;">
                            Hasil Evaluasi Multi-Engine Pipeline (SAST, Secrets, SCA, SBOM, Container & IaC)
                        </p>
                    </td>
                    <td style="text-align: right; width: 140pt; vertical-align: top;">
                        <span class="confidential-pill">Dokumen Rahasia</span>
                        <div style="font-size: 8pt; color: #64748b; margin-top: 6pt;">
                            Ref: <strong>#{{ $report->id }}</strong><br>
                            {{ optional($report->generated_at)->format('d/m/Y H:i') }} WIB
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Executive Security Posture Card -->
        <div class="posture-card">
            <table class="no-border-table" style="width: 100%;">
                <tr>
                    <td style="width: 70pt; text-align: center; vertical-align: middle; border-right: 1.5pt solid {{ $posture['border_color'] ?? '#cbd5e1' }}; padding-right: 10pt !important;">
                        <div class="posture-grade">{{ $posture['grade'] ?? 'C' }}</div>
                        <div style="font-size: 8pt; color: #64748b; font-weight: bold; margin-top: 2pt;">Skor: {{ $posture['score'] ?? 0 }}/100</div>
                    </td>
                    <td style="padding-left: 14pt !important; vertical-align: middle;">
                        <div class="posture-label">{{ $posture['label'] ?? 'STATUS RISIKO' }}</div>
                        <p style="font-size: 9pt; color: #334155; margin: 0; line-height: 1.4;">
                            {{ $posture['description'] ?? 'Hasil pemindaian keamanan terpadu multi-engine.' }}
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Target & Scope Metadata Summary -->
        <div class="section-box">
            <h3 style="margin-bottom: 8pt; border-bottom: 1px solid #e2e8f0; padding-bottom: 3pt;">Identitas Pekerjaan Audit & Aset Target</h3>
            <table class="no-border-table" style="width: 100%;">
                <tr>
                    <td style="width: 50%; vertical-align: top; padding-right: 8pt !important;">
                        <p><strong>Proyek:</strong> {{ data_get($content, 'project.name', $report->scanJob?->project?->name) ?? '-' }} ({{ data_get($content, 'project.code', '-') }})</p>
                        <p><strong>Repositori:</strong> {{ data_get($content, 'repository.url', data_get($content, 'repository.name', '-')) }}</p>
                        <p><strong>Default Branch:</strong> <code style="background: #e2e8f0; padding: 1pt 4pt; border-radius: 2pt;">{{ data_get($content, 'repository.default_branch', 'main') }}</code></p>
                    </td>
                    <td style="width: 50%; vertical-align: top; padding-left: 8pt !important;">
                        <p><strong>Kode Pekerjaan:</strong> <code style="font-family: monospace; font-size: 8.5pt;">{{ data_get($content, 'scan.code', $report->scanJob?->code) }}</code></p>
                        <p><strong>Waktu Audit:</strong> {{ optional($report->scanJob?->started_at)->format('d/m/Y H:i:s') ?? '-' }} s/d {{ optional($report->scanJob?->finished_at)->format('H:i:s') ?? '-' }} WIB</p>
                        <p><strong>Auditor / Pemohon:</strong> {{ data_get($content, 'generated_by.name', 'User') }}</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Quick Metrics Counter Grid -->
        <div style="margin-top: 10pt;">
            <h3 style="margin-bottom: 6pt;">Distribusi Tingkat Keparahan Temuan</h3>
            <table class="no-border-table" style="width: 100%;">
                <tr>
                    <td style="width: 16.6%; padding-right: 3pt !important;">
                        <div class="metric-tile" style="border-top: 3pt solid #dc2626;">
                            <strong style="color: #dc2626;">{{ $summary['critical'] ?? 0 }}</strong>
                            <span>Kritis</span>
                        </div>
                    </td>
                    <td style="width: 16.6%; padding-right: 3pt !important; padding-left: 3pt !important;">
                        <div class="metric-tile" style="border-top: 3pt solid #ea580c;">
                            <strong style="color: #ea580c;">{{ $summary['high'] ?? 0 }}</strong>
                            <span>Tinggi</span>
                        </div>
                    </td>
                    <td style="width: 16.6%; padding-right: 3pt !important; padding-left: 3pt !important;">
                        <div class="metric-tile" style="border-top: 3pt solid #d97706;">
                            <strong style="color: #d97706;">{{ $summary['medium'] ?? 0 }}</strong>
                            <span>Sedang</span>
                        </div>
                    </td>
                    <td style="width: 16.6%; padding-right: 3pt !important; padding-left: 3pt !important;">
                        <div class="metric-tile" style="border-top: 3pt solid #2563eb;">
                            <strong style="color: #2563eb;">{{ $summary['low'] ?? 0 }}</strong>
                            <span>Rendah</span>
                        </div>
                    </td>
                    <td style="width: 16.6%; padding-right: 3pt !important; padding-left: 3pt !important;">
                        <div class="metric-tile" style="border-top: 3pt solid #64748b;">
                            <strong style="color: #64748b;">{{ $summary['informational'] ?? 0 }}</strong>
                            <span>Info</span>
                        </div>
                    </td>
                    <td style="width: 16.6%; padding-left: 3pt !important;">
                        <div class="metric-tile" style="border-top: 3pt solid #0f172a; background: #f8fafc;">
                            <strong style="color: #0f172a;">{{ $summary['total'] ?? count($findings) }}</strong>
                            <span>Total</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div style="margin-top: 20pt; font-size: 8pt; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 8pt; text-align: center;">
            Dokumen ini dihasilkan secara otomatis oleh sistem SecSys dan memuat rincian mitigasi teknis untuk tim perekayasa perangkat lunak dan manajemen keamanan.
        </div>
    </div>

    <!-- ========================================== -->
    <!-- HALAMAN 2: EXECUTIVE SUMMARY & PIPELINE    -->
    <!-- ========================================== -->
    <div class="page-break">
        <h2>1. Ringkasan Eksekutif & Matriks Risiko</h2>
        <p style="font-size: 9pt; color: #475569; margin-bottom: 10pt;">
            Evaluasi menyeluruh terhadap kode sumber, dependensi pihak ketiga, artefak kredensial, serta konfigurasi infrastruktur.
        </p>

        <!-- Matriks Proporsi Risiko -->
        @php
            $totalFindings = max(1, $summary['total'] ?? count($findings));
            $critPct = round((($summary['critical'] ?? 0) / $totalFindings) * 100);
            $highPct = round((($summary['high'] ?? 0) / $totalFindings) * 100);
            $medPct = round((($summary['medium'] ?? 0) / $totalFindings) * 100);
            $lowPct = round((($summary['low'] ?? 0) / $totalFindings) * 100);
            $infoPct = round((($summary['informational'] ?? 0) / $totalFindings) * 100);
        @endphp
        <table>
            <thead>
                <tr>
                    <th style="width: 18%;">Tingkat Risiko</th>
                    <th style="width: 14%;">Jumlah Temuan</th>
                    <th style="width: 14%;">Proporsi</th>
                    <th style="width: 54%;">Visualisasi Distribusi Beban Risiko</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="badge critical">Kritis (Critical)</span></td>
                    <td><strong>{{ $summary['critical'] ?? 0 }}</strong> temuan</td>
                    <td>{{ $critPct }}%</td>
                    <td>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: {{ $critPct }}%; background: #dc2626;"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><span class="badge high">Tinggi (High)</span></td>
                    <td><strong>{{ $summary['high'] ?? 0 }}</strong> temuan</td>
                    <td>{{ $highPct }}%</td>
                    <td>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: {{ $highPct }}%; background: #ea580c;"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><span class="badge medium">Sedang (Medium)</span></td>
                    <td><strong>{{ $summary['medium'] ?? 0 }}</strong> temuan</td>
                    <td>{{ $medPct }}%</td>
                    <td>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: {{ $medPct }}%; background: #d97706;"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><span class="badge low">Rendah (Low)</span></td>
                    <td><strong>{{ $summary['low'] ?? 0 }}</strong> temuan</td>
                    <td>{{ $lowPct }}%</td>
                    <td>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: {{ $lowPct }}%; background: #2563eb;"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><span class="badge informational">Informasional</span></td>
                    <td><strong>{{ $summary['informational'] ?? 0 }}</strong> temuan</td>
                    <td>{{ $infoPct }}%</td>
                    <td>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: {{ $infoPct }}%; background: #64748b;"></div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Tabel Verifikasi Multi-Engine Pipeline -->
        <h2 style="margin-top: 14pt;">2. Hasil Verifikasi Multi-Engine Pipeline</h2>
        <p style="font-size: 9pt; color: #475569; margin-bottom: 8pt;">
            Daftar engine scanner yang dieksekusi secara terisolasi dalam container Docker untuk memverifikasi seluruh domain keamanan repositori.
        </p>

        <table>
            <thead>
                <tr>
                    <th style="width: 22%;">Engine Scanner</th>
                    <th style="width: 26%;">Domain Pengujian</th>
                    <th style="width: 20%;">Status Eksekusi</th>
                    <th style="width: 16%;">Durasi</th>
                    <th style="width: 16%;">Hasil Temuan</th>
                </tr>
            </thead>
            <tbody>
                @forelse(($report->scanJob?->scanRuns ?? []) as $run)
                    @php
                        $durationSec = round(data_get($run->runtime_metrics, 'duration_ms', 0) / 1000, 1);
                    @endphp
                    <tr>
                        <td><strong>{{ strtoupper($run->engine_key) }}</strong></td>
                        <td>
                            @if($run->engine_key === 'semgrep')
                                SAST (Analisis Kode Sumber)
                            @elseif($run->engine_key === 'gitleaks')
                                Secrets (Kredensial Bocor)
                            @elseif($run->engine_key === 'trivy')
                                SCA (Vulnerability CVE)
                            @elseif($run->engine_key === 'osv')
                                SCA (Google OSV Database)
                            @elseif($run->engine_key === 'syft')
                                SBOM (Katalog Komponen)
                            @elseif($run->engine_key === 'hadolint')
                                Dockerfile Linting & Security
                            @elseif($run->engine_key === 'checkov')
                                IaC Misconfiguration
                            @else
                                {{ ucfirst($run->engine_key) }}
                            @endif
                        </td>
                        <td>
                            @if($run->status === 'completed')
                                <span style="color: #16a34a; font-weight: bold;">Selesai (Sukses)</span>
                            @elseif($run->status === 'skipped')
                                <span style="color: #64748b; font-style: italic;">Dilewati (Tidak ada target)</span>
                            @else
                                <span style="color: #dc2626; font-weight: bold;">Gagal</span>
                            @endif
                        </td>
                        <td>{{ $durationSec > 0 ? $durationSec.'s' : '-' }}</td>
                        <td><strong>{{ $run->runtime_metrics['finding_count'] ?? 0 }}</strong> temuan</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; color: #64748b;">Belum ada data eksekusi scanner.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- ========================================== -->
    <!-- HALAMAN 3+: RINCIAN TEMUAN & SOLUSI FIX    -->
    <!-- ========================================== -->
    <div>
        <h2>3. Rincian Temuan Kerentanan & Panduan Perbaikan Terpadu</h2>
        <p style="font-size: 9pt; color: #475569; margin-bottom: 12pt;">
            Setiap temuan telah diidentifikasi dan dilengkapi dengan rekomendasi teknis, langkah perbaikan, serta contoh pola kode aman.
        </p>

        @forelse($findings as $idx => $finding)
            <div class="finding-card">
                <!-- Header Temuan -->
                <div class="finding-card-header">
                    <table class="no-border-table" style="width: 100%;">
                        <tr>
                            <td>
                                <span class="badge {{ $finding['severity'] ?? '' }}">{{ $finding['severity'] ?? 'INFO' }}</span>
                                <strong style="font-size: 10pt; margin-left: 5pt; color: #0f172a;">{{ $finding['code'] ?? ('FIND-'.($idx+1)) }}</strong>:
                                <span style="font-weight: bold; color: #1e293b;">{{ $finding['title'] ?? '-' }}</span>
                            </td>
                            <td style="text-align: right; width: 90pt; vertical-align: middle;">
                                <span style="font-size: 8pt; color: #64748b;">Scanner: <strong>{{ strtoupper($finding['engine_key'] ?? '-') }}</strong></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Body Temuan -->
                <div class="finding-card-body">
                    <table class="no-border-table" style="width: 100%; margin-bottom: 4pt;">
                        <tr>
                            <td style="width: 65%; vertical-align: top; padding-right: 6pt !important;">
                                <p style="font-size: 8.5pt;"><strong>Lokasi File:</strong> <code style="font-family: monospace; background: #e2e8f0; padding: 1pt 4pt; border-radius: 2pt;">{{ data_get($finding, 'location.file_path', data_get($finding, 'location.endpoint', '-')) }}@if(data_get($finding, 'location.line_start')):{{ data_get($finding, 'location.line_start') }}@endif</code></p>
                                <p style="font-size: 8.5pt;"><strong>Aturan / Rule ID:</strong> <span style="font-family: monospace; font-size: 8pt;">{{ $finding['rule_id'] ?? '-' }}</span></p>
                            </td>
                            <td style="width: 35%; vertical-align: top; font-size: 8.5pt;">
                                @php
                                    $cve = data_get($finding, 'classification.cve');
                                    $cwe = data_get($finding, 'classification.cwe');
                                    $cvss = data_get($finding, 'classification.cvss');
                                    $evidence = $finding['evidence'] ?? null;
                                @endphp
                                @if(!empty($cve))
                                    <p><strong>CVE:</strong> {{ is_array($cve) ? implode(', ', $cve) : $cve }}</p>
                                @endif
                                @if(!empty($cwe))
                                    <p><strong>CWE:</strong> {{ is_array($cwe) ? implode(', ', $cwe) : $cwe }}</p>
                                @endif
                                @if(!empty($cvss))
                                    <p><strong>CVSS Score:</strong> {{ is_array($cvss) ? json_encode($cvss) : $cvss }}</p>
                                @endif
                            </td>
                        </tr>
                    </table>

                    @if(!empty($evidence))
                        <p style="margin-top: 3pt; color: #475569; font-size: 8pt; font-weight: bold;">Bukti Temuan (Evidence):</p>
                        <div style="background: #f1f5f9; border-left: 2.5pt solid #64748b; padding: 3pt 6pt; font-family: monospace; font-size: 8pt; margin-bottom: 4pt;">
                            {{ is_array($evidence) ? json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $evidence }}
                        </div>
                    @endif

                    <!-- Kotak Rekomendasi Solusi & Solusi Perbaikan -->
                    @if(!empty($finding['remediation']))
                        <div class="remediation-box">
                            <div class="remediation-title">Analisis Keamanan & Panduan Solusi Terpadu:</div>
                            
                            @if(!empty($finding['remediation']['cause']))
                                <p style="color: #0f172a; font-size: 8.5pt; margin-bottom: 3pt;">
                                    <strong>Penyebab Kerentanan:</strong> {{ data_get($finding, 'remediation.cause') }}
                                </p>
                            @endif

                            @if(!empty($finding['remediation']['attack_vector']))
                                <p style="color: #991b1b; font-size: 8.5pt; margin-bottom: 3pt;">
                                    <strong>Vektor Serangan & Risiko:</strong> {{ data_get($finding, 'remediation.attack_vector') }}
                                </p>
                            @endif

                            @if(!empty($finding['remediation']['business_impact']))
                                <p style="color: #9a3412; font-size: 8.5pt; margin-bottom: 4pt;">
                                    <strong>Dampak Kerugian:</strong> {{ data_get($finding, 'remediation.business_impact') }}
                                </p>
                            @endif

                            <p style="color: #15803d; font-size: 8.5pt; margin-top: 4pt; margin-bottom: 3pt;">
                                <strong>Rekomendasi Penanganan:</strong> {{ data_get($finding, 'remediation.summary') }}
                            </p>

                            @if(!empty($finding['remediation']['mitigation_checklist']))
                                <p style="font-size: 8.5pt; font-weight: bold; color: #166534; margin-top: 4pt; margin-bottom: 1pt;">Langkah-langkah Tindakan:</p>
                                <ul class="checklist">
                                    @foreach($finding['remediation']['mitigation_checklist'] as $step)
                                        <li>{{ $step }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            @if(!empty($finding['remediation']['code_diff']['secure_code']))
                                <p style="font-size: 8.5pt; font-weight: bold; color: #166534; margin-top: 5pt; margin-bottom: 1pt;">Contoh Pola Kode / Konfigurasi Aman:</p>
                                <div class="code-block">{{ $finding['remediation']['code_diff']['secure_code'] }}</div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="section-box" style="text-align: center; padding: 18pt;">
                <h3 style="color: #16a34a;">Seluruh Pengujian Aman</h3>
                <p style="color: #64748b; font-size: 9pt; margin-top: 4pt;">
                    Tidak ditemukan kerentanan keamanan pada repositori ini. Semua engine pengujian berhasil menyelesaikan verifikasi dengan status bersih.
                </p>
            </div>
        @endforelse
    </div>

    <!-- ========================================== -->
    <!-- CATATAN & KETENTUAN KEPATUHAN              -->
    <!-- ========================================== -->
    <div style="page-break-inside: avoid; margin-top: 14pt;">
        <h2>4. Ketentuan Kepatuhan & Metodologi Audit</h2>
        <div class="section-box">
            @foreach(($content['notes'] ?? []) as $note)
                <p style="font-size: 8.5pt; color: #475569; margin-bottom: 3pt;">• {{ $note }}</p>
            @endforeach
            <p style="font-size: 8.5pt; color: #475569; margin-top: 6pt;">
                • <strong>Verifikasi Pasca-Remediasi:</strong> Setelah melakukan perbaikan kode atau upgrade pustaka dependensi, jalankan kembali pemindaian melalui SecSys untuk memverifikasi bahwa seluruh temuan telah terselesaikan dan tidak menimbulkan regresi keamanan baru.
            </p>
        </div>
    </div>
</body>
</html>
