# 03-Implementation: Auto-Pull, Health Check Hardening & UI Resilience

- **Tanggal**: 2026-10-28T02:42:00Z
- **Subjek**: Implementasi Perbaikan Menyeluruh Mekanisme Health Check, Auto-Pull, dan Antarmuka Mesin Pemindai

---

## 1. Ringkasan Perubahan

Untuk menjamin kendala engine degraded dan error "image belum di-pull" tidak pernah terjadi lagi di sistem TAMENG:
1. **Pre-provisioning Image 20 Engine**: Seluruh image container dari 20 mesin keamanan telah diunduh, diverifikasi, dan diberi tag lokal yang sesuai.
2. **Hardening Health Check Controller**:
   - Jika image belum ada secara lokal dan parameter `auto_pull=true` dipanggil, backend otomatis melakukan `docker pull` hingga timeout 300 detik.
   - Jika image belum ada dan `auto_pull=false`, status dialihkan ke **`STANDBY`** (bukan `DEGRADED`) dengan pesan informatif dan tombol aksi.
   - Status `DEGRADED` hanya digunakan untuk anomali fatal engine.
3. **Penambahan Endpoint Baru**:
   - `POST /api/security/engines/{engine}/pull-image`: Memicu pengunduhan image langsung secara on-demand.
   - `POST /api/security/engines/sync`: Menjalankan sinkronisasi massal seluruh 20 mesin dengan Docker daemon.
4. **Perintah Artisan CLI**:
   - `php artisan secsys:sync-engines [--pull]`: Skrip otomatisasi server untuk audit dan pre-pull semua image kontainer.
5. **Modernisasi UI Frontend (Tabler)**:
   - Menambahkan tombol **"Sinkronkan Semua"** pada header registri.
   - Menambahkan tombol **"Tarik Image"** dinamis pada baris mesin berstatus `STANDBY` atau `DEGRADED`.
   - Mengubah tipe alert jika image belum ter-cache dari `danger` (merah darurat) menjadi `warning` (kuning informatif).

---

## 2. Rincian File yang Dimodifikasi & Dibuat

### 2.1 Backend Console Command
- **File**: `backend/app/Console/Commands/SyncEngineImagesCommand.php`
- Menjalankan iterasi ke 20 engine, memeriksa status ke Docker daemon via `docker image inspect`, dan memperbarui status di database ke `AVAILABLE` atau `STANDBY`.

### 2.2 Backend API Controller
- **File**: `backend/app/Http/Controllers/Api/SecurityEngineController.php`
- Metode `healthCheck(Request $request, SecurityEngine $engine)`: Logika deteksi cache miss dan auto-pull.
- Metode `pullImage(Request $request, SecurityEngine $engine)`: Penarikan image mandiri disertai audit logging.
- Metode `syncAll(Request $request)`: Sinkronisasi batch seluruh 20 engine.

### 2.3 Backend API Routing
- **File**: `backend/routes/api.php`
- Rute `POST /api/security/engines/sync` (role: super_admin, security_admin, security_analyst).
- Rute `POST /api/security/engines/{engine}/pull-image` (role: super_admin, security_admin).

### 2.4 Frontend Tabler View
- **File**: `frontend-tabler/src/views/EnginesView.vue`
- Menambahkan status `STANDBY` dan `UPDATING`.
- Menambahkan fungsi `syncAllEngines()` dan `pullEngineImage()`.
- Menambahkan tombol interaktif dan alert ramah pengguna.
