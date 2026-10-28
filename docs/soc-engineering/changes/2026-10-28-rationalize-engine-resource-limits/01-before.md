# 01-BEFORE: DOKUMENTASI KONDISI AWAL & CHANGE RECORD
## ID Perubahan: CHG-20261028-RATIONALIZE-RESOURCE-LIMITS
**Tanggal**: 2026-10-28  
**Koordinator**: Engineering Coordinator & SRE Agent (Antigravity)  
**Status**: APPROVED FOR IMPLEMENTATION  

---

### 1. Permintaan dan Tujuan
- **Permintaan Pengguna**: "opsi 1" (Melakukan rasionalisasi dan penyesuaian alokasi limit RAM & CPU engine HEAVY agar aman bagi kapasitas hardware host).
- **Tujuan**:
  1. Menghilangkan risiko OOM (Out Of Memory) crash pada host server Linux yang hanya memiliki kapasitas RAM 3,4 GB.
  2. Menyesuaikan konfigurasi memori limit (`memory_limit_mb`) dan CPU limit (`cpu_limit`) pada 3 engine yang sebelumnya berstatus `HEAVY` (`codeql`, `sonarqube`, `zap`) ke batas aman host (1,5 GB - 2 GB).
  3. Mengubah klasifikasi `resource_class` dari `HEAVY` menjadi `MEDIUM` di database dan seeder agar tampilan badge di dashboard TAMENG merefleksikan profil beban kerja yang seimbang.
  4. Memperbarui dokumentasi sistem terkait resource class.

---

### 2. Environment dan Kondisi Baseline Host
- **Host OS**: Ubuntu 26.04 LTS (x86_64).
- **Kapasitas RAM Fisik**: **3.398 MB (~3,4 GB)**.
- **RAM Terpakai Saat Ini**: ~2.030 MB.
- **RAM Tersedia**: ~1.367 MB.
- **Swap**: 3.909 MB.
- **Beban Aktif di Host**: Stack TAMENG (MySQL, Redis, Backend, Worker, Nginx), stack eSurvey (Go, Nginx), Xibo CMS, Zabbix Agent.

---

### 3. Kondisi Sistem Sebelum Perubahan

| Engine | Resource Class Eksisting | Limit RAM Eksisting | Limit CPU Eksisting | Default Timeout |
|---|:---:|:---:|:---:|:---:|
| **GitHub CodeQL** (`codeql`) | `HEAVY` | **8.192 MB (8 GB)** | **4.00 vCPU** | 900 detik |
| **SonarQube Scanner** (`sonarqube`) | `HEAVY` | **4.096 MB (4 GB)** | **2.50 vCPU** | 600 detik |
| **OWASP ZAP** (`zap`) | `HEAVY` | **4.096 MB (4 GB)** | **3.00 vCPU** | 1.200 detik |

---

### 4. Masalah / Gap yang Ditemukan
1. Alokasi 8 GB untuk CodeQL dan 4 GB untuk SonarQube/ZAP melebihi total RAM fisik host server (3,4 GB).
2. Jika engine dijalankan dengan parameter `--memory 8192m` atau `--memory 4096m`, kernel Linux akan memicu *OOM Killer* yang berpotensi mematikan database `secsys-mysql` atau container utama lainnya secara mendadak.
3. Tampilan badge merah `HEAVY` di antarmuka web TAMENG membingungkan pengguna mengenai status beban engine.

---

### 5. Komponen Terdampak
- Database `secsys-mysql` tabel `security_engines`.
- File Seeder: `backend/database/seeders/SecurityEngineSeeder.php`.
- File Dokumentasi: `docs/DOKUMENTASI_ALUR_DAN_CARA_KERJA_TAMENG.md`.

---

### 6. Kriteria Keberhasilan
1. Limit RAM CodeQL disesuaikan menjadi 2.048 MB (2 GB), CPU 1.5, Resource Class `MEDIUM`.
2. Limit RAM SonarQube disesuaikan menjadi 1.536 MB (1,5 GB), CPU 1.5, Resource Class `MEDIUM`.
3. Limit RAM OWASP ZAP disesuaikan menjadi 1.536 MB (1,5 GB), CPU 1.5, Resource Class `MEDIUM`.
4. Endpoint `GET /api/security/engines` memvalidasi bahwa seluruh engine kini aman dalam rentang resource host.
5. Dokumentasi Bagian 3.1 disinkronisasi.
