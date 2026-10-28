# 05-AFTER: DOKUMENTASI KONDISI AKHIR & SINKRONISASI
## ID Perubahan: CHG-20261028-RATIONALIZE-RESOURCE-LIMITS
**Tanggal**: 2026-10-28  
**Auditor**: Documentation Agent (Antigravity)  
**Status Akhir**: COMPLETED  

---

### 1. Kondisi Akhir Sistem
Batas alokasi memori dan CPU untuk seluruh security engine di TAMENG kini telah diselaraskan dengan kapasitas hardware server host (RAM fisik 3,4 GB).

Tidak ada lagi engine yang meminta alokasi 8 GB atau 4 GB yang membahayakan stabilitas server.

---

### 2. Perbandingan Kondisi BEFORE vs AFTER

| Parameter | BEFORE (Kondisi Awal) | AFTER (Kondisi Akhir) | Status |
|---|---|---|---|
| **CodeQL Resource Class** | `HEAVY` | **`MEDIUM`** | DIUBAH |
| **CodeQL Limit RAM** | 8.192 MB (8 GB) | **2.048 MB (2 GB)** | DIOPTIMALKAN |
| **CodeQL Limit CPU** | 4.00 vCPU | **1.50 vCPU** | DIOPTIMALKAN |
| **SonarQube Resource Class** | `HEAVY` | **`MEDIUM`** | DIUBAH |
| **SonarQube Limit RAM** | 4.096 MB (4 GB) | **1.536 MB (1,5 GB)** | DIOPTIMALKAN |
| **SonarQube Limit CPU** | 2.50 vCPU | **1.50 vCPU** | DIOPTIMALKAN |
| **OWASP ZAP Resource Class** | `HEAVY` | **`MEDIUM`** | DIUBAH |
| **OWASP ZAP Limit RAM** | 4.096 MB (4 GB) | **1.536 MB (1,5 GB)** | DIOPTIMALKAN |
| **OWASP ZAP Limit CPU** | 3.00 vCPU | **1.50 vCPU** | DIOPTIMALKAN |
| **Risiko Linux OOM Killer** | Tinggi (Host 3,4 GB diminta 8 GB) | **Aman & Terisolasi** | MITIGASI BERHASIL |
| **Status API `GET /api/health`** | 200 OK | **200 OK** | STABIL |

---

### 3. File yang Berubah
1. **Live Database**: Tabel `security_engines` (record `codeql`, `sonarqube`, `zap`).
2. **`backend/database/seeders/SecurityEngineSeeder.php`**: Penyesuaian nilai default seeder.
3. **`docs/DOKUMENTASI_ALUR_DAN_CARA_KERJA_TAMENG.md`**: Pembaruan tabel Bagian 3.1.
4. **Dokumentasi Perubahan**:
   - `docs/soc-engineering/changes/2026-10-28-rationalize-engine-resource-limits/01-before.md`
   - `docs/soc-engineering/changes/2026-10-28-rationalize-engine-resource-limits/02-analysis.md`
   - `docs/soc-engineering/changes/2026-10-28-rationalize-engine-resource-limits/03-implementation.md`
   - `docs/soc-engineering/changes/2026-10-28-rationalize-engine-resource-limits/04-qc.md`
   - `docs/soc-engineering/changes/2026-10-28-rationalize-engine-resource-limits/05-after.md`
   - `docs/soc-engineering/changes/2026-10-28-rationalize-engine-resource-limits/evidence/baseline_heavy_engines.json`
   - `docs/soc-engineering/changes/2026-10-28-rationalize-engine-resource-limits/evidence/qc_verified_limits.json`

---

### 4. Langkah Rollback
Jika kelak server di-upgrade ke spesifikasi yang jauh lebih besar (misal RAM 32 GB atau 64 GB) dan ingin mengembalikan CodeQL ke alokasi 8 GB:
```sql
UPDATE security_engines SET resource_class = 'HEAVY', memory_limit_mb = 8192, cpu_limit = 4.00, default_timeout = 900 WHERE code = 'codeql';
UPDATE security_engines SET resource_class = 'HEAVY', memory_limit_mb = 4096, cpu_limit = 2.50, default_timeout = 600 WHERE code = 'sonarqube';
UPDATE security_engines SET resource_class = 'HEAVY', memory_limit_mb = 4096, cpu_limit = 3.00, default_timeout = 1200 WHERE code = 'zap';
```
