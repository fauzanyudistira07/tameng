# 01-BEFORE: DOKUMENTASI KONDISI AWAL & CHANGE RECORD
## ID Perubahan: CHG-20261028-IMPLEMENT-PLANNED-ENGINES
**Tanggal & Waktu**: 2026-10-28 02:05:00 UTC  
**Koordinator**: Engineering Coordinator & Implementation Agent (Antigravity)  
**Status**: DRAFT / PROPOSED FOR SCOPE APPROVAL  

---

### 1. Permintaan dan Tujuan
- **Permintaan Pengguna**:
  Melakukan pengecekan terhadap daftar 20 security detection engine TAMENG dan mengimplementasikan seluruh engine yang saat ini berstatus *Skeleton / Planned*.
- **Tujuan**:
  1. Mengimplementasikan adapter konkret untuk 7 engine yang masih skeleton: `codeql`, `sonarqube`, `trufflehog`, `dependency_check`, `kubescape`, `wapiti`, dan `wuppiefuzz`.
  2. Mendaftarkan definisi arsitektur dan safety controls untuk ke-7 engine tersebut di `App\Services\Engines\EngineRegistry`.
  3. Memetakan ke-7 adapter baru ke dalam `App\Services\Engines\EngineAdapterResolver`.
  4. Memperbarui status adapter `mobsf` di `EngineRegistry` dari `adapter_skeleton` menjadi `adapter_ready` (karena `MobSFAdapter` telah diimplementasikan).
  5. Menambahkan konfigurasi default container image di `config/secsys.php`.
  6. Memperbarui dokumentasi katalog 20 engine di `docs/DOKUMENTASI_ALUR_DAN_CARA_KERJA_TAMENG.md` sehingga seluruh 20 engine berstatus 🟢 Implemented.

---

### 2. Environment dan Komponen Terdampak
- **Environment**: Docker Engine Host Ubuntu 26.04 LTS (`docker-compose` stack `tameng`).
- **Komponen Terdampak**:
  - `secsys-backend` (Laravel 12 API Control Plane).
  - `secsys-worker` (Background scan execution worker).
  - File Adapter: `app/Services/Engines/Adapters/` (7 adapter baru).
  - File Registry: `app/Services/Engines/EngineRegistry.php`.
  - File Resolver: `app/Services/Engines/EngineAdapterResolver.php`.
  - File Konfigurasi: `config/secsys.php`.
  - File Dokumentasi: `docs/DOKUMENTASI_ALUR_DAN_CARA_KERJA_TAMENG.md`.

---

### 3. Kondisi Sistem Sebelum Perubahan
- **Database (`security_engines`)**:
  20 engine sudah terdata lengkap dengan metadata resource class, timeout, dan batas memori.
- **Engine Registry (`EngineRegistry.php`)**:
  Hanya mendefinisikan 13 engine (`semgrep`, `gitleaks`, `trivy`, `osv`, `zap`, `testssl`, `nikto`, `nuclei`, `hadolint`, `checkov`, `syft`, `grype`, `mobsf`).
  7 engine tidak ada di registry (`codeql`, `sonarqube`, `trufflehog`, `dependency_check`, `kubescape`, `wapiti`, `wuppiefuzz`).
- **Engine Resolver (`EngineAdapterResolver.php`)**:
  Hanya menangani 13 engine. Panggilan untuk 7 engine lainnya dialihkan ke `NullEngineAdapter`.
- **Baseline Eksekusi Tinker Aktual**:
  ```text
  semgrep            | InRegistry: YES | Status: adapter_ready    | Adapter: SemgrepAdapter
  codeql             | InRegistry: NO  | Status: NOT_FOUND        | Adapter: NullEngineAdapter
  sonarqube          | InRegistry: NO  | Status: NOT_FOUND        | Adapter: NullEngineAdapter
  gitleaks           | InRegistry: YES | Status: adapter_ready    | Adapter: GitleaksAdapter
  trufflehog         | InRegistry: NO  | Status: NOT_FOUND        | Adapter: NullEngineAdapter
  trivy              | InRegistry: YES | Status: adapter_ready    | Adapter: TrivyAdapter
  osv                | InRegistry: YES | Status: adapter_ready    | Adapter: OsvAdapter
  dependency_check   | InRegistry: NO  | Status: NOT_FOUND        | Adapter: NullEngineAdapter
  syft               | InRegistry: YES | Status: adapter_ready    | Adapter: SyftAdapter
  grype              | InRegistry: YES | Status: adapter_ready    | Adapter: GrypeAdapter
  hadolint           | InRegistry: YES | Status: adapter_ready    | Adapter: HadolintAdapter
  checkov            | InRegistry: YES | Status: adapter_ready    | Adapter: CheckovAdapter
  kubescape          | InRegistry: NO  | Status: NOT_FOUND        | Adapter: NullEngineAdapter
  zap                | InRegistry: YES | Status: adapter_ready    | Adapter: ZapAdapter
  nuclei             | InRegistry: YES | Status: adapter_ready    | Adapter: NucleiAdapter
  nikto              | InRegistry: YES | Status: adapter_ready    | Adapter: NiktoAdapter
  wapiti             | InRegistry: NO  | Status: NOT_FOUND        | Adapter: NullEngineAdapter
  wuppiefuzz         | InRegistry: NO  | Status: NOT_FOUND        | Adapter: NullEngineAdapter
  mobsf              | InRegistry: YES | Status: adapter_skeleton | Adapter: MobSFAdapter
  testssl            | InRegistry: YES | Status: adapter_ready    | Adapter: TestSslAdapter
  ```

---

### 4. Masalah atau Gap yang Ditemukan
1. Jika pengguna memilih profile `FULL_DEFENSE` atau `SOURCE_ADVANCED` yang memuat `codeql`, `sonarqube`, `trufflehog`, atau `dependency_check`, `EngineRunner::plan()` akan melempar `ValidationException: Engine {key} is not registered` karena engine belum didefinisikan di `EngineRegistry`.
2. Jika engine didaftarkan tanpa adapter, `EngineAdapterResolver` akan mengembalikan `NullEngineAdapter` yang menyebabkan status scan run menjadi `skipped` (`ENGINE_ADAPTER_NOT_IMPLEMENTED`).
3. Adapter `mobsf` sudah memiliki implementasi lengkap di `MobSFAdapter.php`, namun status di `EngineRegistry.php` masih tercatat `'adapter' => 'planned'`, sehingga status sistem menandainya sebagai `adapter_skeleton`.
4. `config/secsys.php` belum memiliki default container image environment variables untuk ke-7 engine tersebut.

---

### 5. File dan Komponen Relevan
- `app/Services/Engines/EngineRegistry.php`
- `app/Services/Engines/EngineAdapterResolver.php`
- `app/Services/Engines/Adapters/CodeqlAdapter.php` (BARU)
- `app/Services/Engines/Adapters/SonarQubeAdapter.php` (BARU)
- `app/Services/Engines/Adapters/TrufflehogAdapter.php` (BARU)
- `app/Services/Engines/Adapters/DependencyCheckAdapter.php` (BARU)
- `app/Services/Engines/Adapters/KubescapeAdapter.php` (BARU)
- `app/Services/Engines/Adapters/WapitiAdapter.php` (BARU)
- `app/Services/Engines/Adapters/WuppieFuzzAdapter.php` (BARU)
- `config/secsys.php`
- `docs/DOKUMENTASI_ALUR_DAN_CARA_KERJA_TAMENG.md`

---

### 6. Perilaku Aktual vs Perilaku yang Diharapkan

| Kasus Uji | Perilaku Aktual (BEFORE) | Perilaku Diharapkan (AFTER) |
|---|---|---|
| `EngineRegistry::all()` count | 13 engines | 20 engines |
| Status adapter `mobsf` | `adapter_skeleton` | `adapter_ready` |
| `EngineAdapterResolver::resolve('codeql')` | `NullEngineAdapter` | `CodeqlAdapter` |
| `EngineAdapterResolver::resolve('sonarqube')` | `NullEngineAdapter` | `SonarQubeAdapter` |
| `EngineAdapterResolver::resolve('trufflehog')` | `NullEngineAdapter` | `TrufflehogAdapter` |
| `EngineAdapterResolver::resolve('dependency_check')` | `NullEngineAdapter` | `DependencyCheckAdapter` |
| `EngineAdapterResolver::resolve('kubescape')` | `NullEngineAdapter` | `KubescapeAdapter` |
| `EngineAdapterResolver::resolve('wapiti')` | `NullEngineAdapter` | `WapitiAdapter` |
| `EngineAdapterResolver::resolve('wuppiefuzz')` | `NullEngineAdapter` | `WuppieFuzzAdapter` |
| Normalisasi Finding Tiap Engine | Tidak dapat mem-parsing output report | Memiliki parser findings terstandarisasi |

---

### 7. Risiko, Dependensi, dan Mitigasi
- **Risiko Image Availability**: Image container untuk ke-7 engine baru belum tentu ter-cache di local host Docker.
  - *Mitigasi*: Seluruh adapter mewarisi kontrak `RepositoryDockerAdapter` / `EngineAdapter` yang memiliki mekanisme graceful error handling. Jika image belum ditarik atau gagal dieksekusi di runtime, adapter mengembalikan status `failed` terisolasi dengan failure reason yang jelas tanpa mematikan proses antrean kontainer `secsys-worker`.
- **Risiko Regresi**: Penambahan adapter tidak boleh mengubah perilaku 13 adapter eksisting yang sudah stabil dan lulus audit.
  - *Mitigasi*: Menjaga arsitektur adapter terisolasi per file class, tidak mengubah kontrak `UniversalEngineAdapterContract`, dan melakukan verifikasi regresi pada 13 engine lama.

---

### 8. Batasan Ruang Lingkup
- Hanya mencakup implementasi 7 adapter baru, pembaruan `EngineRegistry`, `EngineAdapterResolver`, `config/secsys.php`, dan dokumentasi terkait.
- Tidak melakukan refactoring pada 13 adapter eksisting.
- Tidak mengubah skema tabel database atau memodifikasi seeder database yang sudah berjalan.

---

### 9. Kriteria Keberhasilan
1. Seluruh 20 engine terdaftar di `EngineRegistry::all()`.
2. Seluruh 20 engine berstatus `adapter_ready` (tidak ada lagi `adapter_skeleton` atau `NullEngineAdapter`).
3. Panggilan `EngineAdapterResolver::resolve($code)` untuk ke-20 engine berhasil memetakan instance adapter yang tepat.
4. Parsing mock/real report berhasil diverifikasi untuk masing-masing adapter baru.
5. `php artisan route:list` dan service health check API tetap bernilai 200 OK.
