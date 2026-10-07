export interface FailureExplanation {
  title: string
  cause: string
  remediation: string
}

export function getFailureExplanation(reason?: string | null, engineKey?: string | null): FailureExplanation {
  if (!reason && !engineKey) {
    return {
      title: 'Kendala Eksekusi Pemindaian',
      cause: 'Proses scanner tidak dapat diselesaikan atau terhenti sebelum menghasilkan laporan akhir.',
      remediation: 'Periksa ketersediaan target dan coba jalankan kembali pemindaian.',
    }
  }

  const raw = (reason || '').toUpperCase().trim()
  const engine = (engineKey || '').toLowerCase().trim()

  // 1. Specific Nuclei Timeout / Error
  if (raw.includes('NUCLEI_PROCESS_TIMEOUT')) {
    return {
      title: 'Batas Waktu Pemindaian Nuclei Terlampaui (Scan Timeout)',
      cause: 'Proses Nuclei melebihi batas waktu maksimum alokasi runner. Hal ini umumnya terjadi karena server target lambat merespons request, proteksi rate-limiting / Cloudflare / WAF aktif pada target, atau target memiliki struktur endpoint yang sangat luas.',
      remediation: 'Pastikan server target online dan responsif, verifikasi IP scanner TAMENG tidak diblokir oleh firewall/WAF target, atau gunakan profil "Web Safe Scan" yang membatasi pengujian pada celah penting saja.',
    }
  }

  // 2. Specific ZAP Timeout / Error
  if (raw.includes('ZAP_PROCESS_TIMEOUT')) {
    return {
      title: 'Batas Waktu OWASP ZAP Terlampaui (DAST Timeout)',
      cause: 'Proses spidering crawling dan active scanning OWASP ZAP membutuhkan waktu lebih lama dari batas timeout sistem karena struktur aplikasi web target yang kompleks.',
      remediation: 'Batasi kedalaman tautan pada URL target atau lakukan pemindaian bertahap pada sub-path tertentu.',
    }
  }

  // 3. Specific TestSSL Timeout / Error
  if (raw.includes('TESTSSL_PROCESS_TIMEOUT')) {
    return {
      title: 'Batas Waktu Audit SSL/TLS Terlampaui',
      cause: 'Koneksi handshake SSL/TLS pada port 443 target lambat merespons saat pengujian puluhan cipher suite dan protokol kriptografi lawas.',
      remediation: 'Pastikan port HTTPS (443) target terbuka, stabil, dan tidak mengalami packet drop saat menerima probe handshake SSL.',
    }
  }

  // 4. Specific Nikto Timeout / Error
  if (raw.includes('NIKTO_PROCESS_TIMEOUT')) {
    return {
      title: 'Batas Waktu Scanner Nikto Terlampaui',
      cause: 'Web server target membatasi laju permintaan (rate limiting) atau koneksi HTTP mengalami latensi tinggi saat proses enumerasi file sensitif.',
      remediation: 'Periksa respon server target dan pastikan firewall tidak memblokir IP scanner TAMENG.',
    }
  }

  // 5. Generic Engine Timeout
  if (raw.includes('TIMEOUT') || raw.includes('TIME_OUT')) {
    return {
      title: `Batas Waktu Eksekusi ${engine ? engine.toUpperCase() : 'Engine'} Terlampaui`,
      cause: `Engine scanner ${engine ? engine.toUpperCase() : ''} tidak dapat menyelesaikan pemindaian dalam alokasi waktu maksimum sistem.`,
      remediation: 'Periksa konektivitas jaringan ke target atau kurangi intensitas profil scan agar proses dapat selesai lebih cepat.',
    }
  }

  // 6. Docker daemon or container runner not responding
  if (raw.includes('DOCKER_CLI_NOT_RESPONDING') || raw.includes('DOCKER_DAEMON')) {
    return {
      title: 'Layanan Docker Runner Tidak Merespons',
      cause: 'Host runner TAMENG tidak dapat mengakses Docker socket atau daemon Docker sedang sibuk / mengalami kendala izin akses.',
      remediation: 'Periksa status layanan Docker di server host (systemctl status docker) dan pastikan alokasi resource CPU & RAM mencukupi.',
    }
  }

  // 7. Workspace Git sync failure
  if (raw.includes('WORKSPACE_SYNC_FAILED') || raw.includes('GIT_CLONE_FAILED')) {
    return {
      title: 'Gagal Sinkronisasi Repositori Kode Sumber',
      cause: 'Sistem TAMENG gagal mengkloning repositori kode target. Penyebab umum: URL repositori salah, token autentikasi (PAT/SSH Key) kadaluarsa, atau cabang (branch) tidak ditemukan.',
      remediation: 'Periksa pengaturan URL repositori, perbarui Personal Access Token (PAT) atau kunci SSH pada menu Manajemen Repositori.',
    }
  }

  // 7b. Dockerfile not found / Hadolint skipped gracefully
  if (raw.includes('NO_DOCKERFILE_FOUND') || raw.includes('HADOLINT_REQUIRES_DOCKERFILE')) {
    return {
      title: 'Dilewati: Tidak Ada Berkas Dockerfile',
      cause: 'Repositori proyek ini tidak menggunakan Docker atau tidak memiliki berkas "Dockerfile". Mesin Hadolint (Dockerfile Linter) dilewati secara normal (skipped) karena tidak ada berkas kontainer yang perlu diperiksa.',
      remediation: 'Bukan masalah/error. Jika proyek Anda nantinya menambahkan berkas Dockerfile, Hadolint akan otomatis memindai dan memverifikasi konfigurasi kontainer tersebut.',
    }
  }

  // 8a. Authorization expired or not in valid window
  if (raw.includes('AUTHORIZATION_NOT_IN_VALID_WINDOW') || raw.includes('NOT_IN_VALID_WINDOW')) {
    return {
      title: 'Masa Berlaku Izin Pengguna Biasa Telah Berakhir (7 Hari)',
      cause: 'Sesuai tata kelola keamanan (Rule of Engagement), akun pengguna biasa memiliki batas jendela waktu pengujian maksimal 7 hari sejak disetujui. Masa berlaku izin aset ini telah habis sehingga sistem menolak eksekusi scanner otomatis.',
      remediation: 'Pengguna biasa dapat mengajukan permohonan scan baru pada menu "Form Pengajuan Scan" atau menghubungi Administrator SOC/Keamanan untuk memperpanjang izin. Akun Administrator memiliki izin permanen tanpa batas masa berlaku.',
    }
  }

  // 8b. Authorization not active
  if (raw.includes('AUTHORIZATION_NOT_ACTIVE')) {
    return {
      title: 'Status Otorisasi Scan Tidak Aktif',
      cause: 'Surat izin pemindaian aset ini berstatus non-aktif, dibekukan, atau dicabut oleh Administrator Keamanan.',
      remediation: 'Periksa status otorisasi di menu Tata Kelola Otorisasi dan aktifkan kembali izin sebelum menjalankan scan.',
    }
  }

  // 8c. Target or Repository not verified
  if (raw.includes('REPOSITORY_NOT_VERIFIED') || raw.includes('TARGET_NOT_VERIFIED')) {
    return {
      title: 'Aset Belum Terverifikasi Kepemilikannya',
      cause: 'Repositori atau target URL belum diverifikasi kepemilikannya oleh Administrator Keamanan (verification_status != verified).',
      remediation: 'Buka menu Inventaris Aset dan lakukan verifikasi kepemilikan aset terlebih dahulu sebelum memindai.',
    }
  }

  // 8d. Engine not allowed
  if (raw.includes('ENGINE_NOT_ALLOWED')) {
    return {
      title: 'Mesin Pemindai Tidak Diizinkan dalam Otorisasi',
      cause: 'Engine yang dicoba dijalankan tidak terdaftar dalam daftar mesin yang disetujui (allowed_engines) pada otorisasi proyek ini.',
      remediation: 'Sesuaikan profil pemindaian dengan izin otorisasi yang telah disetujui.',
    }
  }

  // 8e. General Policy denied
  if (raw.includes('POLICY_DENIED') || raw.includes('DENIED') || raw.includes('OUT_OF_SCOPE') || raw.includes('UNAUTHORIZED')) {
    return {
      title: 'Pemindaian Ditolak oleh Kebijakan Keamanan',
      cause: 'Eksekusi scan dibatalkan karena target, domain, atau IP berada di luar batas izin otorisasi kepatuhan (Rule of Engagement) TAMENG.',
      remediation: 'Pastikan target telah terdaftar resmi dalam aset yang diizinkan dan ajukan otorisasi scan ke Admin Keamanan.',
    }
  }

  // 9. Host unreachable or connection refused
  if (raw.includes('CONNECTION_REFUSED') || raw.includes('HOST_UNREACHABLE') || raw.includes('NAME_NOT_RESOLVED')) {
    return {
      title: 'Host Target Tidak Dapat Dijangkau',
      cause: 'Scanner tidak dapat membuat koneksi jaringan ke host target. Kemungkinan domain tidak aktif, DNS gagal resolve, atau firewall memblokir paket probe.',
      remediation: 'Pastikan domain atau IP target aktif dan dapat diakses dari jaringan server TAMENG sebelum menjalankan scan.',
    }
  }

  // 10. Queue worker timeout
  if (raw.includes('QUEUE_JOB_TIMEOUT')) {
    return {
      title: 'Batas Waktu Antrean Worker Terlampaui',
      cause: 'Pekerjaan scanner dalam antrean worker melebihi batas waktu eksekusi global sehingga dihentikan secara otomatis untuk mencegah worker hang.',
      remediation: 'Jalankan scan ulang dengan cakupan pengujian yang lebih ringkas atau periksa antrean background worker.',
    }
  }

  // 11. Process failed / Exit non-zero
  if (raw.includes('PROCESS_FAILED') || raw.includes('EXIT_CODE')) {
    return {
      title: `Proses ${engine ? engine.toUpperCase() : 'Engine'} Berhenti dengan Kode Error`,
      cause: `Container scanner ${engine ? engine.toUpperCase() : ''} mengembalikan kode status kesalahan saat memproses target. Hal ini bisa terjadi karena format target tidak kompatibel atau berkas konfigurasi tidak valid.`,
      remediation: 'Verifikasi kesesuaian tipe target (URL Web vs Repositori Kode) dan periksa rincian log audit untuk melihat pesan teknis scanner.',
    }
  }

  // 12. Execution error with detail
  if (raw.includes('EXECUTION_ERROR')) {
    const detail = reason?.replace(/^.*EXECUTION_ERROR:?\s*/i, '').trim() || ''
    return {
      title: `Kendala Eksekusi Internal ${engine ? engine.toUpperCase() : 'Scanner'}`,
      cause: detail ? `Detail pesan error runner: "${detail}"` : 'Terjadi kendala saat runner mempersiapkan lingkungan pemindaian.',
      remediation: 'Coba jalankan ulang pemindaian. Jika kendala berulang, periksa log runner server TAMENG.',
    }
  }

  // Fallback with original message
  return {
    title: `Kendala Pemindaian ${engine ? engine.toUpperCase() : 'Engine'}`,
    cause: reason || 'Engine pemindai tidak dapat menyelesaikan proses analisis secara normal.',
    remediation: 'Periksa status target dan lakukan pemindaian ulang jika target sudah siap.',
  }
}
