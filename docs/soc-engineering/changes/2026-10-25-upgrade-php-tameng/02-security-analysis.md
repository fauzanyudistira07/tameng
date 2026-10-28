# 02-SECURITY-ANALYSIS: ANALISIS KEAMANAN & RENCANA IMPLEMENTASI
## ID Perubahan: CHG-20261025-PHP-UPGRADE
**Tanggal**: 2026-10-25  
**Reviewer**: SOC Security Reviewer & Backend Architect (Antigravity)  
**Status**: APPROVED TO IMPLEMENT  

---

### 1. Root Cause & Kebutuhan Bisnis
- **Kebutuhan**: Memperbarui runtime PHP pada sistem TAMENG ke versi resmi terbaru (PHP 8.4) guna mendapatkan peningkatan performa JIT/Zend Engine, efisiensi konsumsi memori pada analisis data temuan keamanan, serta penutupan potensi celah keamanan (security patches) pada runtime lawas.
- **Root Cause**: Versi image di `backend/Dockerfile` sebelumnya di-hardcode ke `php:8.3-fpm-alpine`.

---

### 2. Komponen & Trust Boundary yang Terdampak
- **Trust Boundary**:
  - Batas komunikasi antara reverse proxy Nginx (`secsys-backend-nginx`) dengan PHP-FPM (`secsys-backend`) pada port `9000`.
  - Batas eksekusi background worker (`secsys-worker`) terhadap antrian database/Redis.
  - Komunikasi ke socket Docker daemon (`docker-proxy` / `/var/run/docker.sock`) untuk menjalankan security scanners.
- **Dampak Boundary**: Tidak ada perubahan arsitektur jaringan, port, maupun trust boundary. Port, volume, dan permissions www-data tetap dipertahankan.

---

### 3. Alur Data & Alur Event
- Client -> Nginx (HTTP 8000) -> FastCGI (9000) -> PHP-FPM 8.4 (Laravel 12 API) -> MySQL (3307) / Redis (6380).
- Queue Worker -> Redis / DB -> Eksekusi Scanner CLI via Docker Socket -> Hasil Scan dinormalisasi -> Database.
- Seluruh alur data tetap identik, tanpa pengubahan format payload maupun schema database.

---

### 4. Dampak terhadap CIA Triad (Confidentiality, Integrity, Availability)
- **Confidentiality**: [CONFIRMED POSITIVE] Runtime yang lebih baru membawa patch keamanan pustaka bawaan (libzip, oniguruma, openssl). Tidak ada secret atau key yang terpapar.
- **Integrity**: [CONFIRMED POSITIVE] Integritas data database dan token autentikasi Sanctum tetap konsisten karena driver `pdo_mysql` dan hashing password Bcrypt kompatibel penuh.
- **Availability**: [CONFIRMED LOW RISK] Terjadi downtime singkat (sekitar 5–15 detik) saat container di-restart dengan image baru.

---

### 5. Authentication, Authorization & Validasi
- **Autentikasi**: Laravel Sanctum session & token cookies tidak terpengaruh.
- **Otorisasi**: RBAC middleware (`super_admin`, `security_admin`, `security_analyst`, `developer`, `viewer`) tetap berfungsi normal.
- **Input Validation**: FormRequest dan validator bawaan Laravel 12 kompatibel penuh dengan PHP 8.4.

---

### 6. Analisis Dependensi & Kompatibilitas
- **Pemeriksaan Platform Requirement** [CONFIRMED]:
  - `composer check-platform-reqs` telah diuji dan menghasilkan status `success` untuk PHP 8.4 dan PHP 8.5.
  - Batasan `"php": "^8.2"` pada `composer.json` secara alami mencakup PHP 8.4.
  - Library `dompdf/dompdf ^3.1` dan `laravel/framework ^12.0` secara resmi mendukung PHP 8.4.
- **Ekstensi Alpine Linux & PECL** [CONFIRMED]:
  - Ekstensi PHP yang dibutuhkan: `bcmath`, `intl`, `mbstring`, `pdo_mysql`, `zip`, dan PECL `redis`.
  - Tersedia paket Alpine untuk kompilasi PHP 8.4.

---

### 7. Matriks Rencana Perubahan Spesifik
| File/Komponen | Perubahan | Alasan | Risiko | Kategori Temuan |
|---|---|---|---|---|
| `backend/Dockerfile` | Ubah baris 10: `FROM php:8.3-fpm-alpine` menjadi `FROM php:8.4-fpm-alpine` | Menggunakan runtime resmi PHP 8.4 | Potensi error kompilasi PECL `redis` saat build | CONFIRMED |
| `secsys-backend` | Rebuild image & recreate container via Docker Compose | Menjalankan API pada PHP 8.4 | Interupsi koneksi sesaat | CONFIRMED |
| `secsys-worker` | Rebuild image & recreate container via Docker Compose | Menjalankan worker pada PHP 8.4 | Job pending selama reload | CONFIRMED |

---

### 8. Strategi Testing & Verifikasi QC
1. **Build Verification**:
   - Jalankan `docker compose build secsys-backend` dan amati proses kompilasi ekstensi (terutama PECL redis dan pdo_mysql).
2. **Container Recreation & Up**:
   - Jalankan `docker compose up -d secsys-backend secsys-worker`.
3. **Runtime & CLI Verification**:
   - `docker exec secsys-backend php -v` (harus `PHP 8.4.x`).
   - `docker exec secsys-worker php -v` (harus `PHP 8.4.x`).
   - `docker exec secsys-backend php artisan --version` (harus `Laravel Framework 12.x`).
4. **Functional & Health Check**:
   - `curl -i http://127.0.0.1:8000/api/health` -> HTTP 200 OK, `X-Powered-By: PHP/8.4.x`.
   - Verifikasi status database, redis, storage, queue, repository_workspace, engine_runtime.
5. **Log Inspection**:
   - `docker logs --tail 50 secsys-backend` dan `secsys-worker` untuk memastikan tidak ada fatal error / unhandled exception.

---

### 9. Rencana Rollback
- Jika build gagal atau timbul issue kritis:
  - Kembalikan `backend/Dockerfile` ke `FROM php:8.3-fpm-alpine`.
  - Jalankan `docker compose build secsys-backend secsys-worker` dan `docker compose up -d`.
  - Verifikasi ulang dengan `curl http://127.0.0.1:8000/api/health`.
- Detail lengkap dicatat pada `06-rollback.md`.
