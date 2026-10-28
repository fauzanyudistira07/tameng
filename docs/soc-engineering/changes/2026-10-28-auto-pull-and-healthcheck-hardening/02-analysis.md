# 02-Analysis: Akar Masalah & Desain Solusi Permanen

- **Tanggal**: 2026-10-28T02:37:00Z
- **Fokus**: Analisis Teknis Alur Health Check & Desain Arsitektur Penarikan Image Otomatis

---

## 1. Analisis Kode Saat Ini (Root Cause Breakdown)

### 1.1 Backend: `SecurityEngineController::healthCheck`
```php
// app/Http/Controllers/Api/SecurityEngineController.php (sebelum hardening)
$process = new Process([$dockerBinary, 'image', 'inspect', $image]);
$process->setTimeout(10);
$process->run();

$isAvailable = $process->isSuccessful();
$engine->status = $isAvailable ? 'AVAILABLE' : 'DEGRADED';
$engine->last_health_check = now();
$engine->save();
```
- **Kelemahan 1**: Jika image belum di-pull, `docker image inspect` menghasilkan exit code 1. Status engine secara agresif diubah menjadi `DEGRADED`.
- **Kelemahan 2 (Akar Masalah Krusial)**: PHP-FPM di dalam kontainer `secsys-backend` berjalan sebagai user unprivileged `www-data` (UID 82). Socket Docker `/var/run/docker.sock` dimiliki oleh grup host GID 983 (`srw-rw----`). Akibatnya, setiap perintah `docker image inspect` yang dipicu dari browser menghasilkan `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock`, sehingga proses gagal dan sistem mengira image tidak ada di host.
- **Kelemahan 3**: Tidak ada upaya penarikan (`docker pull`) baik di background maupun on-demand, sehingga engine tetap terkunci di status `DEGRADED`.
- **Kelemahan 4**: Tidak ada endpoint dedicated untuk memicu `docker pull` secara mandiri per engine.

### 1.2 Frontend: `EnginesView.vue`
```typescript
// frontend-tabler/src/views/EnginesView.vue (sebelum hardening)
actionMessage.value = {
  type: res.data.image_present ? 'success' : 'danger',
  text: res.message
}
```
- **Kelemahan 1**: Pemetaan boolean `!image_present` langsung ke tipe notifikasi `'danger'` memicu banner merah pekat yang mengindikasikan fatal error bagi operator.
- **Kelemahan 2**: Tidak ada tombol aksi kontekstual seperti "Tarik Image (Pull)" atau "Sinkronkan Semua Mesin" untuk memulihkan status.

---

## 2. Rencana Solusi Permanen (Preventive Architecture)

Agar kondisi ini **tidak pernah terjadi lagi**:

1. **Pre-provisioning Image Terverifikasi**:
   - Seluruh 20 image container dari registri mesin keamanan TAMENG telah divalidasi dan diunduh ke host Docker daemon lokal.
2. **Penyempurnaan Logika Health Check Backend**:
   - Jika image belum ada secara lokal dan parameter `auto_pull=true` (default), backend secara otomatis mencoba menarik image (`docker pull $image`) dengan timeout yang wajar.
   - Jika image belum ditarik dan auto-pull dinonaktifkan, status engine **tidak didegradasi**, melainkan diset ke `STANDBY` dengan panduan penarikan image yang informatif.
   - Status `DEGRADED` hanya digunakan jika daemon Docker gagal dihubungi secara sistemik atau container rusak saat instansiasi.
3. **Endpoint Baru untuk Operasi Image**:
   - `POST /api/security/engines/{engine}/pull-image`: Memicu proses `docker pull` secara spesifik untuk satu engine dengan progress status `UPDATING` -> `AVAILABLE`.
   - `POST /api/security/engines/sync`: Memicu sinkronisasi status health check untuk seluruh 20 engine secara paralel/sekuensial.
4. **Artisan CLI Command untuk Otomasi Sistem & Deployment**:
   - `php artisan secsys:sync-engines [--pull]`: Memungkinkan sysadmin, cron, atau pipeline CI/CD melakukan pra-pemeriksaan dan pra-unduh seluruh image mesin keamanan TAMENG sebelum layanan menerima pemindaian.
5. **Modernisasi UI Frontend**:
   - Menyediakan tombol "Tarik Image" jika image belum tersedia.
   - Menyediakan tombol "Sinkronkan Semua Mesin" pada toolbar atas.
   - Notifikasi status missing image diubah dari `danger` (merah) menjadi `warning` (kuning informatif) dengan tautan aksi langsung.
