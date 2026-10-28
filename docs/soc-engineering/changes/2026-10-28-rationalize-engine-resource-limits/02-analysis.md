# 02-ANALYSIS: ANALISIS TEKNIS & RENCANA EKSEKUSI
## ID Perubahan: CHG-20261028-RATIONALIZE-RESOURCE-LIMITS
**Tanggal**: 2026-10-28  
**Analis**: SRE & Backend Architect (Antigravity)  
**Status**: APPROVED FOR EXECUTION  

---

### 1. Analisis Kapasitas & Bottleneck
- Kapasitas RAM Server: 3.398 MB fisik.
- Container aktif mengonsumsi ~2.030 MB untuk MySQL, Redis, Nginx, PHP-FPM, dsb.
- Sisa RAM aman untuk eksekusi on-demand sandbox engine adalah kisaran **1.000 MB – 2.048 MB**.
- Alokasi 8.192 MB (CodeQL) dan 4.096 MB (SonarQube & ZAP) adalah konfigurasi enterprise berukuran besar yang tidak realistis untuk node tunggal ini dan membahayakan uptime seluruh sistem.

---

### 2. Rencana Perubahan Data

| Code Engine | Nilai Lama (Before) | Nilai Baru (After) | Alasan Teknis |
|---|---|---|---|
| `codeql` | `resource_class`: HEAVY<br>`memory_limit_mb`: 8192<br>`cpu_limit`: 4.00 | **`resource_class`: MEDIUM**<br>**`memory_limit_mb`: 2048**<br>**`cpu_limit`: 1.50** | Batas maksimal aman 2 GB RAM & 1.5 core vCPU agar tidak memicu OOM killer. |
| `sonarqube` | `resource_class`: HEAVY<br>`memory_limit_mb`: 4096<br>`cpu_limit`: 2.50 | **`resource_class`: MEDIUM**<br>**`memory_limit_mb`: 1536**<br>**`cpu_limit`: 1.50** | Alokasi 1,5 GB cukup untuk Java heap CLI scanner pada codebase ukuran menengah. |
| `zap` | `resource_class`: HEAVY<br>`memory_limit_mb`: 4096<br>`cpu_limit`: 3.00 | **`resource_class`: MEDIUM**<br>**`memory_limit_mb`: 1536**<br>**`cpu_limit`: 1.50** | Alokasi 1,5 GB stabil untuk DAST baseline scan tanpa membebani browser daemon. |

---

### 3. Rencana Langkah Eksekusi (Coding / Database)
1. Jalankan query Eloquent update langsung pada database live `secsys` melalui artisan command/tinker.
2. Modifikasi file `SecurityEngineSeeder.php` pada entri `codeql`, `sonarqube`, dan `zap` agar seed data konsisten di masa mendatang.
3. Modifikasi tabel katalog engine pada `DOKUMENTASI_ALUR_DAN_CARA_KERJA_TAMENG.md` (ubah kolom Resource Class ke `MEDIUM`).

---

### 4. Strategi Pengujian (Quality Control)
1. Verifikasi langsung isi tabel `security_engines` di database bahwa `codeql`, `sonarqube`, dan `zap` telah tersimpan dengan `resource_class = MEDIUM`, `cpu_limit = 1.50`, dan memori `1536` / `2048`.
2. Validasi endpoint API `GET /api/security/engines` untuk memastikan response JSON menyajikan data terbaru ke frontend.
3. Verifikasi syntax seeder dan health check API.
