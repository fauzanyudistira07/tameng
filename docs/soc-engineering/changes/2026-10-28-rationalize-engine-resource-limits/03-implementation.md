# 03-IMPLEMENTATION: IMPLEMENTASI RASIONALISASI RESOURCE LIMITS
## ID Perubahan: CHG-20261028-RATIONALIZE-RESOURCE-LIMITS
**Tanggal**: 2026-10-28  
**Implementer**: Software Engineer & SRE Specialist (Antigravity)  
**Status**: COMPLETED  

---

### 1. Ringkasan Perubahan
Telah berhasil disesuaikan alokasi RAM dan CPU limit untuk engine yang sebelumnya berstatus `HEAVY` (`codeql`, `sonarqube`, `zap`) agar proporsional dengan kapasitas RAM host server (3,4 GB):
1. **GitHub CodeQL (`codeql`)**:
   - `resource_class`: Diubah dari `HEAVY` ke `MEDIUM`.
   - `memory_limit_mb`: Diturunkan dari `8192` (8 GB) ke `2048` (2 GB).
   - `cpu_limit`: Diturunkan dari `4.00` ke `1.50`.
   - `default_timeout`: Disesuaikan ke `600` detik.
2. **SonarQube Scanner (`sonarqube`)**:
   - `resource_class`: Diubah dari `HEAVY` ke `MEDIUM`.
   - `memory_limit_mb`: Diturunkan dari `4096` (4 GB) ke `1536` (1,5 GB).
   - `cpu_limit`: Diturunkan dari `2.50` ke `1.50`.
   - `default_timeout`: Disesuaikan ke `450` detik.
3. **OWASP ZAP (`zap`)**:
   - `resource_class`: Diubah dari `HEAVY` ke `MEDIUM`.
   - `memory_limit_mb`: Diturunkan dari `4096` (4 GB) ke `1536` (1,5 GB).
   - `cpu_limit`: Diturunkan dari `3.00` ke `1.50`.
   - `default_timeout`: Disesuaikan ke `600` detik.

---

### 2. Komponen & File yang Berubah
1. **Live Database MySQL (`secsys-mysql`)**:
   Record pada tabel `security_engines` berhasil di-update untuk ketiga engine tersebut via Eloquent ORM.
2. **`backend/database/seeders/SecurityEngineSeeder.php`**:
   Data seeder disinkronisasi sehingga jika seeder dijalankan ulang di masa depan, alokasi memori tetap aman.
3. **`docs/DOKUMENTASI_ALUR_DAN_CARA_KERJA_TAMENG.md`**:
   Tabel katalog Bagian 3.1 diperbarui dari `HEAVY` menjadi `MEDIUM`.

---

### 3. Hasil Perubahan di Database
```json
[
    {
        "code": "codeql",
        "resource_class": "MEDIUM",
        "memory_limit_mb": 2048,
        "cpu_limit": "1.50",
        "default_timeout": 600
    },
    {
        "code": "sonarqube",
        "resource_class": "MEDIUM",
        "memory_limit_mb": 1536,
        "cpu_limit": "1.50",
        "default_timeout": 450
    },
    {
        "code": "zap",
        "resource_class": "MEDIUM",
        "memory_limit_mb": 1536,
        "cpu_limit": "1.50",
        "default_timeout": 600
    }
]
```
