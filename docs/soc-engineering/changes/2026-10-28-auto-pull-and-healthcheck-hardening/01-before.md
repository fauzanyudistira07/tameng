# 01-Before: Auto-Pull & Preflight Health Check Hardening

- **Tanggal/Waktu**: 2026-10-28T02:35:00Z
- **Subjek**: Investigasi Engine Degraded dan Error Health Check Image Belum Di-Pull
- **Target Komponen**:
  - `backend/app/Http/Controllers/Api/SecurityEngineController.php`
  - `frontend-tabler/src/views/EnginesView.vue`
  - `backend/routes/api.php`
  - Database table `security_engines`

---

## 1. Kondisi Awal (Baseline State)

Saat pengguna mengakses antarmuka **Registri Mesin Keamanan (Security Engine Registry)** di URL `http://127.0.0.1:5174/engines`:
1. Ditemukan beberapa mesin pemindai keamanan dengan badge status warna kuning **`DEGRADED`** (antara lain: `codeql`, `sonarqube`, `trufflehog`, `dependency_check`, `kubescape`, `wapiti`, `wuppiefuzz`).
2. Ketika pengguna atau administrator menekan tombol **"Cek Image"** (Health Check):
   - Sistem backend menjalankan `docker image inspect <image>`.
   - Karena image Docker belum selesai diunduh ke host lokal, perintah inspect gagal.
   - Kontroler backend (`SecurityEngineController::healthCheck`) langsung menetapkan kolom `status = 'DEGRADED'` di basis data `security_engines`.
   - Respon API mengembalikan pesan: `Image <image> belum di-pull di docker daemon.`
   - Antarmuka frontend Tabler menangkap respon tersebut dan menampilkan banner pesan bahaya berwarna merah (`type: 'danger'`), seolah-olah terjadi kerusakan fatal pada sistem.

## 2. Temuan Masalah & Dampak

| Masalah | Dampak |
|---|---|
| **Status Flapping ke DEGRADED** | Status `DEGRADED` seharusnya menandakan kegagalan fungsi internal engine atau dependensi rusak, bukan sekadar file container image belum di-cache secara lokal. |
| **Tidak Ada Opsi Auto-Pull / Pull On-Demand** | Admin tidak memiliki tombol interaktif untuk memicu penarikan (`docker pull`) langsung dari dashboard UI. |
| **False-Alarm Error Banner Merah** | Frontend memperlakukan cache miss sebagai `danger` error toast daripada peringatan informatif (`warning` / `info`). |
| **Tidak Ada Fitur Sinkronisasi Massal** | Harus menekan tombol satu per satu tanpa fitur re-inspeksi otomatis saat sistem atau kontainer booting. |

---

## 3. Bukti Awal (Evidence)

File artefak baseline disimpan pada:
`evidence/baseline_engine_status.json`
