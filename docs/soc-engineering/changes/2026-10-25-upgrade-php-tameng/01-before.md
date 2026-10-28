# 01-BEFORE: DOKUMENTASI KONDISI AWAL & CHANGE RECORD
## ID Perubahan: CHG-20261025-PHP-UPGRADE
**Tanggal**: 2026-10-25  
**Koordinator**: SOC Engineering Assistant (Antigravity)  
**Status**: APPROVED FOR ANALYSIS  

---

### 1. Permintaan dan Tujuan
- **Permintaan Pengguna**: "update versi php ke versi terbaru pada tameng"
- **Tujuan**: Memperbarui basis runtime PHP pada aplikasi TAMENG (SecSys) dari PHP 8.3 (`php:8.3-fpm-alpine`) ke versi stabil terbaru PHP 8.4 (`php:8.4-fpm-alpine`) untuk backend API control plane dan background worker, memastikan kompatibilitas penuh dengan Laravel 12, ekstensi keamanan, dan zero breaking changes.

---

### 2. Environment dan Komponen Terdampak
- **Environment**: Local Docker Development (`APP_ENV=local`, `APP_DEBUG=true`).
- **Komponen Terdampak**:
  - `secsys-backend` (Container PHP-FPM API).
  - `secsys-worker` (Container queue worker pemrosesan scan & background jobs).
  - File definisi container: `/opt/tameng/tameng/backend/Dockerfile`.

---

### 3. Kondisi Sistem Sebelum Perubahan
- **Git State**:
  - Repository: `/opt/tameng/tameng`
  - Branch: `main`
  - Commit HEAD: `c4fa4bbfed12de5cdb21bda031ced974ede0fbbd`
  - Working Tree: Clean (tidak ada uncommitted changes).
- **Runtime PHP Eksisting**:
  - Container `secsys-backend`: `PHP 8.3.33 (cli) (built: Jul 30 2026 22:53:30) (NTS)`
  - Framework: `Laravel Framework 12.67.0`
  - Host OS CLI: `PHP 8.5.4 (cli) (built: Oct 19 2026 14:35:18)`
  - Docker base image: `FROM php:8.3-fpm-alpine` di `backend/Dockerfile` (line 10).
- **Composer Constraints**:
  - `backend/composer.json`: `"php": "^8.2"` (mendukung PHP 8.2 s.d. 8.4+).
  - Dependensi utama: `laravel/framework: ^12.0`, `dompdf/dompdf: ^3.1`, `laravel/sanctum: ^4.3`.
- **Baseline Health Check API**:
  ```text
  HTTP/1.1 200 OK
  Server: nginx/1.27.5
  X-Powered-By: PHP/8.3.33
  Content-Type: application/json

  {
    "status": "ok",
    "checked_at": "2026-10-25T11:27:24.881755Z",
    "checks": {
      "database": { "ok": true, "connection": "mysql", "migrations_table": true },
      "storage": { "ok": true, "paths": [...] },
      "queue": { "ok": true, "connection": "database", "jobs_table": true, "failed_jobs_table": true },
      "repository_workspace": { "ok": true, "root": "/data/repository-workspaces", "exists": true, "writable": true },
      "engine_runtime": { "ok": true, "real_engines_enabled": true, "runtime": "docker", "gitleaks_image": "zricethezav/gitleaks:v8.28.0" }
    }
  }
  ```

---

### 4. Masalah atau Kebutuhan yang Ditemukan
- Versi image PHP dalam `backend/Dockerfile` masih terkunci pada `php:8.3-fpm-alpine`.
- Rilis stabil terbaru di ekosistem PHP dan Docker Hub resmi untuk production container adalah branch PHP 8.4 (`php:8.4-fpm-alpine`).
- Perlu dipastikan kompilasi ekstensi `pdo_mysql`, `bcmath`, `intl`, `mbstring`, `zip`, dan ekstensi PECL `redis` berhasil tanpa hambatan pada basis Alpine PHP 8.4.

---

### 5. File, Konfigurasi, dan Dependensi Terkait
| File | Lokasi | Perubahan yang Direncanakan |
|---|---|---|
| `Dockerfile` | `backend/Dockerfile` | Update `FROM php:8.3-fpm-alpine` -> `FROM php:8.4-fpm-alpine` |
| `composer.json` | `backend/composer.json` | Verifikasi batasan `"php": "^8.2"` tetap valid (tidak perlu diubah bila sudah mencakup 8.4) |
| `docker-compose.yml` | `docker-compose.yml` | Menjalankan build ulang kontainer `secsys-backend` dan `secsys-worker` |

---

### 6. Risiko Awal
1. **Kegagalan Build Ekstensi**:
   - `pecl install redis` pada PHP 8.4 Alpine mungkin memerlukan versi phpredis tertentu atau build dependencies khusus.
2. **Deprecation Notice / Warnings**:
   - PHP 8.4 memperkenalkan perubahan pada pemanggilan parameter nullable tanpa tipe eksplisit dan deprecasi fungsi tertentu.
3. **Downtime Kontainer**:
   - Saat kontainer di-rebuild dan di-restart (`secsys-backend` & `secsys-worker`), API akan mengalami interupsi beberapa detik.

---

### 7. Batasan Ruang Lingkup
- **IN SCOPE**:
  - Update `backend/Dockerfile` ke `php:8.4-fpm-alpine`.
  - Validasi build lokal image kontainer.
  - Restart kontainer backend & worker dengan image baru.
  - Verifikasi fungsional health check, database connectivity, queue readiness, dan response header `X-Powered-By`.
- **OUT OF SCOPE**:
  - Modifikasi kode business logic Laravel (`app/`).
  - Perubahan schema database atau migrasi baru.
  - Modifikasi frontend Vue (`frontend/` atau `frontend-tabler/`).
  - Perubahan engine scanner eksternal (Semgrep, Gitleaks, Trivy, ZAP, dll.).

---

### 8. Acceptance Criteria
1. `backend/Dockerfile` baris 10 menggunakan `FROM php:8.4-fpm-alpine`.
2. Build image Docker `secsys-backend` dan `secsys-worker` berhasil 100% tanpa error.
3. Container `secsys-backend` dan `secsys-worker` berstatus `Up` dan healthy.
4. Perintah `docker exec secsys-backend php -v` menampilkan versi `PHP 8.4.x`.
5. Endpoint `GET http://127.0.0.1:8000/api/health` mengembalikan HTTP 200 OK dengan `X-Powered-By: PHP/8.4.x` dan seluruh health checks bernilai `true`.
6. Dokumentasi lengkap BEFORE, SECURITY ANALYSIS, IMPLEMENTATION, QC REPORT, AFTER, dan ROLLBACK tersedia.

---

### 9. Bukti dan Metode Pengumpulan
- Metode: CLI read-only (`docker exec`, `curl`, `docker manifest inspect`, `git status`).
- Log baseline disimpan di direktori `evidence/` pada change folder ini.
