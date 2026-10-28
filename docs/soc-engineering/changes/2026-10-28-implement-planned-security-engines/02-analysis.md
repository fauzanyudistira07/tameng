# 02-ANALYSIS: ANALISIS TEKNIS & RENCANA IMPLEMENTASI
## ID Perubahan: CHG-20261028-IMPLEMENT-PLANNED-ENGINES
**Tanggal**: 2026-10-28  
**Analis**: Backend Architect & Security Engineer (Antigravity)  
**Status**: PROPOSED FOR IMPLEMENTATION GATE  

---

### 1. Analisis Kebutuhan & Temuan Inspeksi
TAMENG dirancang memiliki **20 Security Engines** terintegrasi. Namun saat ini:
1. `EngineRegistry.php` hanya memuat 13 definisi engine.
2. `EngineAdapterResolver.php` hanya menginjeksi 13 adapter class dan me-route fallback ke `NullEngineAdapter`.
3. 7 engine yang direncanakan (`codeql`, `sonarqube`, `trufflehog`, `dependency_check`, `kubescape`, `wapiti`, `wuppiefuzz`) belum memiliki class adapter konkret di `App\Services\Engines\Adapters\`.
4. Engine `mobsf` telah memiliki adapter `MobSFAdapter.php`, namun flag `'adapter' => 'planned'` di `EngineRegistry` belum diperbarui menjadi `'adapter' => 'implemented'`.

---

### 2. Desain Spesifikasi 7 Adapter Baru

#### A. `CodeqlAdapter` (Domain: SOURCE_CODE / SAST)
- **Base Class**: `RepositoryDockerAdapter`
- **Engine Key**: `codeql`
- **Config Image**: `secsys.codeql_image` (`mcr.microsoft.com/cstsectools/codeql-container:latest`)
- **Report Filename**: `codeql-report.sarif`
- **Container Command**: Menjalankan analisis SARIF codeql database ke output path.
- **Parser Findings**: Mem-parsing format standar SARIF v2.1.0 (`runs[0].results` -> `ruleId`, `message.text`, `locations[0].physicalLocation`).

#### B. `SonarQubeAdapter` (Domain: SOURCE_CODE / SAST)
- **Base Class**: `RepositoryDockerAdapter`
- **Engine Key**: `sonarqube`
- **Config Image**: `secsys.sonarqube_image` (`sonarsource/sonar-scanner-cli:latest`)
- **Report Filename**: `sonar-report.json`
- **Container Command**: Menjalankan `sonar-scanner` dengan parameter CLI isolasi lokal.
- **Parser Findings**: Mem-parsing issue JSON (`issues[]` -> `rule`, `message`, `severity`, `component`).

#### C. `TrufflehogAdapter` (Domain: SECRET / Secret Leak)
- **Base Class**: `RepositoryDockerAdapter`
- **Engine Key**: `trufflehog`
- **Config Image**: `secsys.trufflehog_image` (`trufflesecurity/trufflehog:3.68.0`)
- **Report Filename**: `trufflehog-report.json`
- **Container Command**: `trufflehog filesystem /repo --json --no-verification`
- **Parser Findings**: Mem-parsing JSON lines (`DetectorName`, `Raw`, `SourceMetadata.Data.Git.file`).

#### D. `DependencyCheckAdapter` (Domain: DEPENDENCY / SCA)
- **Base Class**: `RepositoryDockerAdapter`
- **Engine Key**: `dependency_check`
- **Config Image**: `secsys.dependency_check_image` (`owasp/dependency-check:9.0.9`)
- **Report Filename**: `dependency-check-report.json`
- **Container Command**: `/usr/share/dependency-check/bin/dependency-check.sh --project secsys --scan /repo --format JSON --out /out/dependency-check-report.json --noupdate`
- **Parser Findings**: Mem-parsing `dependencies[].vulnerabilities[]` (`name`, `description`, `severity`, `cvssv3.baseScore`, `cwe`).

#### E. `KubescapeAdapter` (Domain: IAC / Kubernetes & IaC)
- **Base Class**: `RepositoryDockerAdapter`
- **Engine Key**: `kubescape`
- **Config Image**: `secsys.kubescape_image` (`quay.io/kubescape/kubescape:latest`)
- **Report Filename**: `kubescape-report.json`
- **Container Command**: `kubescape scan /repo --format json --output /out/kubescape-report.json`
- **Parser Findings**: Mem-parsing control responses dan failed resources.

#### F. `WapitiAdapter` (Domain: WEB (DAST) / Web App)
- **Interface**: `EngineAdapter`
- **Engine Key**: `wapiti`
- **Config Image**: `secsys.wapiti_image` (`wapiti/wapiti:3.1.8`)
- **Target Types**: `['web', 'api']`
- **Report Filename**: `wapiti-report.json`
- **Container Command**: `wapiti -u <target_url> -f json -o /out/wapiti-report.json --flush-session`
- **Parser Findings**: Mem-parsing objek kerentanan `vulnerabilities` (XSS, SQLi, SSRF, CRLF).

#### G. `WuppieFuzzAdapter` (Domain: API / REST API Fuzzing)
- **Interface**: `EngineAdapter`
- **Engine Key**: `wuppiefuzz`
- **Config Image**: `secsys.wuppiefuzz_image` (`secsys/wuppiefuzz:latest`)
- **Target Types**: `['api', 'web']`
- **Report Filename**: `wuppiefuzz-report.json`
- **Parser Findings**: Mem-parsing parameter fuzzing findings dan status anomali API.

---

### 3. Daftar File yang Akan Dibuat dan Diubah

#### File Baru:
1. `backend/app/Services/Engines/Adapters/CodeqlAdapter.php`
2. `backend/app/Services/Engines/Adapters/SonarQubeAdapter.php`
3. `backend/app/Services/Engines/Adapters/TrufflehogAdapter.php`
4. `backend/app/Services/Engines/Adapters/DependencyCheckAdapter.php`
5. `backend/app/Services/Engines/Adapters/KubescapeAdapter.php`
6. `backend/app/Services/Engines/Adapters/WapitiAdapter.php`
7. `backend/app/Services/Engines/Adapters/WuppieFuzzAdapter.php`

#### File yang Dimodifikasi:
1. `backend/app/Services/Engines/EngineRegistry.php` (tambahkan 7 definisi dan ubah mobsf ke implemented).
2. `backend/app/Services/Engines/EngineAdapterResolver.php` (daftarkan 7 adapter baru).
3. `backend/config/secsys.php` (tambahkan 7 environment variables container image).
4. `docs/DOKUMENTASI_ALUR_DAN_CARA_KERJA_TAMENG.md` (sinkronisasi tabel Bagian 3.1).

---

### 4. Dampak Terhadap Modul Lain
- **Execution Gateway**: Tidak ada dampak negatif. Gateway mengevaluasi otorisasi berdasarkan `allowed_engines`. Dengan tersedianya adapter, engine yang diotorisasi dapat berjalan normal tanpa kegagalan resolving.
- **Finding Normalizer & Deduplicator**: Adapter menghasilkan array finding dengan key yang seragam (`rule_id`, `title`, `severity`, `confidence`, `file_path`, `evidence`), sehingga deduplikasi otomatis berjalan konsisten.
- **Reporting & PDF Generation**: Temuan dari 7 engine baru akan langsung dapat dirender di PDF executive report dan dashboard TAMENG.

---

### 5. Strategi Pengujian (Quality Control)
1. **Compilation & Class Loading**:
   Memastikan seluruh file PHP valid secara sintaksis (`php -l`) dan dikenali oleh Laravel IoC container tanpa fatal error.
2. **Registry Integrity Test**:
   Memeriksa bahwa `EngineRegistry::all()` mengembalikan 20 engine dan seluruhnya memiliki status `adapter_ready`.
3. **Adapter Resolution Test**:
   Memastikan perulangan resolve untuk seluruh 20 kode engine di `EngineAdapterResolver` tidak mengembalikan `NullEngineAdapter`.
4. **Parser Findings Test**:
   Memverifikasi bahwa metode `parseFindings()` pada masing-masing dari 7 adapter baru mampu mengurai format payload JSON/SARIF contoh dengan benar.
5. **Regression Health Check**:
   Memanggil `GET /api/health` untuk memastikan tidak ada dampak sampingan pada subsistem TAMENG.

---

### 6. Kriteria Penerimaan
- [ ] 7 file adapter baru terpasang di `backend/app/Services/Engines/Adapters/`.
- [ ] `EngineRegistry.php` dan `EngineAdapterResolver.php` memetakan ke-20 engine.
- [ ] Seluruh 20 engine berstatus `adapter_ready`.
- [ ] Dokumentasi perubahan dan arsip evidence lengkap dari tahap BEFORE hingga AFTER.
