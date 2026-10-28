# 06-ROLLBACK: PROSEDUR PEMULIHAN & ROLLBACK PLAN
## ID Perubahan: CHG-20261025-PHP-UPGRADE
**Tanggal**: 2026-10-25  
**Penanggung Jawab**: SOC Reliability & Operations Reviewer (Antigravity)  
**Status**: STANDBY / VERIFIED  

---

### 1. Kondisi yang Mengharuskan Rollback
Rollback wajib dieksekusi apabila:
1. Muncul crash loop tak terduga pada `secsys-backend` atau `secsys-worker` akibat inkompatibilitas PHP 8.4 yang tidak dapat diperbaiki segera.
2. Endpoint API kritis menghasilkan HTTP 500 error secara persisten.
3. Queue worker gagal memproses scan jobs akibat kegagalan ekstensi atau socket.

---

### 2. Komponen yang Akan Dikembalikan
- File `backend/Dockerfile` baris 10: dikembalikan dari `FROM php:8.4-fpm-alpine` ke `FROM php:8.3-fpm-alpine`.
- Image Docker kontainer `tameng-secsys-backend` dan `tameng-secsys-worker`.

---

### 3. Prasyarat & Backup
- Image lama `php:8.3-fpm-alpine` masih tersedia di Docker layer cache lokal atau Docker Hub.
- Volume database `secsys_mysql_data` dan volume Redis `secsys_redis_data` tidak dimodifikasi sama sekali selama proses upgrade, sehingga data tetap aman.

---

### 4. Prosedur Langkah Pemulihan (Rollback Steps)

1. **Revert Baris Dockerfile**:
   ```bash
   sed -i 's/FROM php:8.4-fpm-alpine/FROM php:8.3-fpm-alpine/g' /opt/tameng/tameng/backend/Dockerfile
   ```
   *Atau dengan git checkout*:
   ```bash
   cd /opt/tameng/tameng && git checkout backend/Dockerfile
   ```

2. **Rebuild Image Kontainer**:
   ```bash
   cd /opt/tameng/tameng
   docker compose build secsys-backend secsys-worker
   ```

3. **Re-deploy Kontainer**:
   ```bash
   docker compose up -d secsys-backend secsys-worker
   ```

4. **Verifikasi Layanan Setelah Rollback**:
   ```bash
   docker exec secsys-backend php -v
   curl -i http://127.0.0.1:8000/api/health
   ```
   Pastikan status mengembalikan `200 OK` dengan header `X-Powered-By: PHP/8.3.33`.

---

### 5. Risiko Rollback & Kemungkinan Kehilangan Data
- **Risiko Kehilangan Data**: **NOL (0%)**. Perubahan hanya terjadi pada layer container runtime, bukan pada schema database maupun volume persistent `/var/lib/mysql`.
- **Downtime Rollback**: Diperkirakan sekitar 10–20 detik selama recreation kontainer.

---

### 6. Otorisasi
- Tindakan rollback dioperasikan oleh SOC Engineering Coordinator / Administrator sistem jika terjadi kegagalan operasional.
