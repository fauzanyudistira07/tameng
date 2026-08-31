<?php

namespace App\Services;

use App\Models\Finding;
use Illuminate\Support\Str;

class AiRemediationService
{
    /**
     * Generate comprehensive AI remediation guidance for a security finding.
     */
    public function generateGuidance(Finding $finding): array
    {
        $finding->loadMissing(['project', 'scanJob', 'scanRun']);

        $category = $this->categorizeFinding($finding);
        $fileExt = strtolower(pathinfo($finding->file_path ?? '', PATHINFO_EXTENSION) ?: 'php');

        $explanation = $this->buildExplanation($finding, $category);
        $patch = $this->buildCodePatch($finding, $category, $fileExt);
        $mitigations = $this->buildMitigationSteps($finding, $category);
        $owaspGuidance = $this->buildOwaspGuidance($finding);

        return [
            'finding_id' => $finding->id,
            'finding_code' => $finding->code,
            'rule_id' => $finding->rule_id,
            'title' => $finding->title,
            'severity' => $finding->severity,
            'category' => $category['name'],
            'summary' => $explanation['summary'],
            'cause' => $explanation['cause'] ?? null,
            'attack_vector' => $explanation['attack_vector'],
            'business_impact' => $explanation['impact'],
            'code_diff' => $patch,
            'mitigation_checklist' => $mitigations,
            'compliance' => [
                'cwe' => $finding->cwe ?: $category['cwe'],
                'owasp' => $finding->owasp ?: $category['owasp'],
                'cvss_score' => $finding->cvss ?? $this->defaultCvss($finding->severity),
            ],
            'owasp_guidance' => $owaspGuidance,
            'disclaimer' => 'TAMENG AI Advisory Engine: Rekomendasi perbaikan ini bersifat panduan teknis keamanan. Selalu verifikasi kesesuaian arsitektur aplikasi dan jalankan unit/integration testing sebelum menerapkan patch ke production.',
        ];
    }

    private function categorizeFinding(Finding $finding): array
    {
        $rule = strtolower($finding->rule_id ?? '');
        $title = strtolower($finding->title ?? '');

        // 1. Clickjacking
        if (Str::contains($rule, ['clickjacking', 'x-frame-options', '10020']) || Str::contains($title, ['clickjacking', 'x-frame-options', 'anti-clickjacking'])) {
            return [
                'type' => 'clickjacking',
                'name' => 'Missing Anti-Clickjacking Header (X-Frame-Options)',
                'cwe' => 'CWE-1021: Improper Restriction of Rendered UI Layers or Frames',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 2. CSP (Content Security Policy)
        if (Str::contains($rule, ['content-security-policy', 'csp', '10038', '10055']) || Str::contains($title, ['content security policy', 'csp header'])) {
            return [
                'type' => 'csp_missing',
                'name' => 'Content Security Policy (CSP) Not Configured',
                'cwe' => 'CWE-358: Improperly Implemented Security Check for Standard',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 3. X-Content-Type-Options
        if (Str::contains($rule, ['x-content-type-options', 'nosniff', '10021']) || Str::contains($title, ['x-content-type-options', 'nosniff'])) {
            return [
                'type' => 'x_content_type_options',
                'name' => 'Missing X-Content-Type-Options Header',
                'cwe' => 'CWE-693: Protection Mechanism Failure',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 4. HSTS (Strict-Transport-Security)
        if (Str::contains($rule, ['strict-transport-security', 'hsts', '10035']) || Str::contains($title, ['strict-transport-security', 'hsts'])) {
            return [
                'type' => 'hsts_missing',
                'name' => 'Missing HTTP Strict Transport Security (HSTS)',
                'cwe' => 'CWE-319: Cleartext Transmission of Sensitive Information',
                'owasp' => 'A02:2021-Cryptographic Failures',
            ];
        }

        // 5. Permissions-Policy
        if (Str::contains($rule, ['permissions-policy', 'feature-policy', '10063']) || Str::contains($title, ['permissions policy', 'feature policy'])) {
            return [
                'type' => 'permissions_policy',
                'name' => 'Missing Permissions-Policy Header',
                'cwe' => 'CWE-693: Protection Mechanism Failure',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 6. COEP / COOP (Cross-Origin Policies)
        if (Str::contains($rule, ['cross-origin-embedder', 'cross-origin-opener', 'coep', 'coop', '90004', '90005']) || Str::contains($title, ['cross-origin-embedder', 'cross-origin-opener', 'coep'])) {
            return [
                'type' => 'coep_coop',
                'name' => 'Missing Cross-Origin Isolation Headers (COEP/COOP)',
                'cwe' => 'CWE-693: Protection Mechanism Failure',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 7. SSL / TLS / Cipher Weaknesses
        if (Str::contains($rule, ['weak-cipher', 'deprecated-tls', 'tls-version', 'ssl-issuer', 'wildcard-tls', 'ssl-dns-names', 'testssl', 'cipher']) || Str::contains($title, ['cipher', 'tls', 'ssl'])) {
            return [
                'type' => 'weak_ssl_ciphers',
                'name' => 'TLS/SSL Cryptographic Configuration & Cipher Suites',
                'cwe' => 'CWE-326: Inadequate Encryption Strength',
                'owasp' => 'A02:2021-Cryptographic Failures',
            ];
        }

        // 8. Cache-Control & Storable Content
        if (Str::contains($rule, ['cache-control', 'storable', 'cacheable', '10015', '10049', '10050']) || Str::contains($title, ['cache-control', 'cacheable', 'cache'])) {
            return [
                'type' => 'cache_control',
                'name' => 'HTTP Cache-Control Directives & Sensitive Data Caching',
                'cwe' => 'CWE-524: Use of Cache Containing Sensitive Information',
                'owasp' => 'A01:2021-Broken Access Control',
            ];
        }

        // 9. Secrets / Credentials
        if (Str::contains($rule, ['secret', 'gitleaks', 'key', 'token', 'password', 'credential', 'auth-token']) || Str::contains($title, ['secret', 'token', 'credential', 'api key', 'password'])) {
            return [
                'type' => 'secret_leak',
                'name' => 'Hardcoded Secrets & Credential Exposure',
                'cwe' => 'CWE-798: Use of Hard-coded Credentials',
                'owasp' => 'A07:2021-Identification and Authentication Failures',
            ];
        }

        // 10. SQL Injection
        if (Str::contains($rule, ['sql', 'sqli', 'injection']) || Str::contains($title, ['sql injection', 'sqli', 'raw query'])) {
            return [
                'type' => 'sql_injection',
                'name' => 'SQL Injection Vulnerability',
                'cwe' => 'CWE-89: Improper Neutralization of Special Elements used in an SQL Command',
                'owasp' => 'A03:2021-Injection',
            ];
        }

        // 11. XSS
        if (Str::contains($rule, ['xss', 'cross-site-scripting', 'dangerouslysetinnerhtml', 'unescaped']) || Str::contains($title, ['cross-site scripting', 'xss'])) {
            return [
                'type' => 'xss',
                'name' => 'Cross-Site Scripting (XSS)',
                'cwe' => 'CWE-79: Improper Neutralization of Input During Web Page Generation',
                'owasp' => 'A03:2021-Injection',
            ];
        }

        // 12. RCE / Command Injection
        if (Str::contains($rule, ['rce', 'eval', 'exec', 'command-injection', 'unserialize', 'system']) || Str::contains($title, ['remote code execution', 'command injection', 'code execution'])) {
            return [
                'type' => 'rce',
                'name' => 'Command Injection / Dangerous Code Execution',
                'cwe' => 'CWE-78: Improper Neutralization of Special Elements used in an OS Command',
                'owasp' => 'A03:2021-Injection',
            ];
        }

        // 13. Vulnerable Dependency (SCA)
        if (Str::contains($rule, ['cve', 'trivy', 'osv', 'package', 'dependency', 'vulnerable-dependency']) || Str::contains($title, ['vulnerable dependency', 'outdated', 'cve-'])) {
            return [
                'type' => 'vulnerable_dependency',
                'name' => 'Vulnerable Third-Party Dependency',
                'cwe' => 'CWE-1395: Dependency on Vulnerable Third-Party Component',
                'owasp' => 'A06:2021-Vulnerable and Outdated Components',
            ];
        }

        // 14. Server Misconfiguration (Nikto / Generic)
        if (Str::contains($rule, ['nikto', 'misconfig', 'cors', 'csrf', 'security-header', 'cookie', 'helmet']) || Str::contains($title, ['nikto', 'cors', 'csrf', 'header', 'cookie', 'server'])) {
            return [
                'type' => 'misconfig',
                'name' => 'Web Server Misconfiguration & Security Headers',
                'cwe' => 'CWE-16: Configuration Errors',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        return [
            'type' => 'generic',
            'name' => 'Security Weakness / Code Quality Issue',
            'cwe' => 'CWE-699: Software Development Concepts',
            'owasp' => 'A04:2021-Insecure Design',
        ];
    }

    private function buildExplanation(Finding $finding, array $category): array
    {
        return match ($category['type']) {
            'clickjacking' => [
                'summary' => 'Server belum menyertakan header keamanan X-Frame-Options atau direktif CSP frame-ancestors.',
                'cause' => 'Konfigurasi web server (Nginx/Apache) atau aplikasi backend belum menambahkan instruksi proteksi frame pada HTTP Response headers.',
                'attack_vector' => 'Penyerang dapat menyematkan (embed) halaman web Anda ke dalam <iframe> transparan di website jahat dan menipu pengguna untuk mengklik tombol penting tanpa disadari.',
                'impact' => 'Pembajakan aksi pengguna (Clickjacking), manipulasi transaksi finansial, perubahan kredensial akun, atau penghapusan data sepihak.',
            ],
            'csp_missing' => [
                'summary' => 'Server belum mengonfigurasi header Content-Security-Policy (CSP).',
                'cause' => 'Tidak adanya deklarasi kebijakan sumber daya (scripts, styles, images) yang diizinkan untuk dimuat oleh browser.',
                'attack_vector' => 'Penyerang memanfaatkan celah injeksi untuk menyisipkan skrip JavaScript berbahaya dari domain eksternal tanpa batasan dari browser.',
                'impact' => 'Pencurian session cookies, token autentikasi JWT, serangan Cross-Site Scripting (XSS), dan defacement visual halaman web.',
            ],
            'x_content_type_options' => [
                'summary' => 'Header X-Content-Type-Options: nosniff tidak ditemukan pada respon HTTP.',
                'cause' => 'Server web belum menginstruksikan browser agar mematuhi tipe MIME yang dideklarasikan secara ketat.',
                'attack_vector' => 'Browser melakukan MIME-sniffing dan mengeksekusi file non-executable (misalnya file gambar .png yang disusupi kode JS) sebagai skrip executable.',
                'impact' => 'Eksekusi kode skrip tidak sah pada browser pengguna dan bypass filter upload berkas.',
            ],
            'hsts_missing' => [
                'summary' => 'Header HTTP Strict-Transport-Security (HSTS) belum diaktifkan pada server HTTPS.',
                'cause' => 'Server web belum memaksa browser klien untuk selalu berkomunikasi menggunakan protokol HTTPS secara eksklusif.',
                'attack_vector' => 'Penyerang pada jaringan lokal (Wi-Fi publik) melakukan serangan SSL Stripping / Man-in-the-Middle (MitM) saat koneksi pertama kali diinisiasi via HTTP.',
                'impact' => 'Penyadapan lalu lintas data sensitif, pencurian password, dan pembajakan sesi komunikasi sebelum dialihkan ke HTTPS.',
            ],
            'permissions_policy' => [
                'summary' => 'Header Permissions-Policy (sebelumnya Feature-Policy) belum dikonfigurasi.',
                'cause' => 'Tidak ada pembatasan eksplisit terhadap fitur perangkat keras browser yang boleh diakses oleh dokumen atau iframe pihak ketiga.',
                'attack_vector' => 'Skrip pihak ketiga (misalnya plugin analitik/iklan) dapat mengakses mikrofon, kamera, atau sensor lokasi pengguna tanpa izin ketat.',
                'impact' => 'Pelanggaran privasi pengguna dan potensi penyalahgunaan perangkat keras browser.',
            ],
            'coep_coop' => [
                'summary' => 'Header isolasi Cross-Origin (COEP / COOP) belum disetel pada server.',
                'cause' => 'Halaman web belum mengaktifkan isolasi lingkungan dokumen dari resource lintas domain (cross-origin).',
                'attack_vector' => 'Penyerang memanfaatkan serangan side-channel memori browser (seperti Spectre) untuk membaca data dari proses yang sama.',
                'impact' => 'Kebocoran data sensitif lintas tab browser dan berkurangnya isolasi memori komputasi.',
            ],
            'weak_ssl_ciphers' => [
                'summary' => 'Audit SSL/TLS menemukan penggunaan cipher suite lama, protokol usang, atau konfigurasi sertifikat yang perlu ditinjau.',
                'cause' => 'Konfigurasi SSL/TLS pada web server masih mengizinkan cipher enkripsi berbasis CBC/3DES atau mendukung versi TLS di bawah 1.2.',
                'attack_vector' => 'Penyerang yang memiliki kapasitas komputasi tinggi memecahkan algoritma enkripsi lalu lintas data yang terekspos.',
                'impact' => 'Dekripsi data transaksi perbankan/kredensial yang lewat di jaringan dan kegagalan kepatuhan standar industri (PCI-DSS, ISO 27001).',
            ],
            'cache_control' => [
                'summary' => 'Halaman web menyimpan cache konten dinamis tanpa direktif kontrol yang ketat.',
                'cause' => 'Header Cache-Control tidak menyertakan instruksi no-store / no-cache pada respon yang memuat informasi akun pengguna.',
                'attack_vector' => 'Pengguna berikutnya di komputer publik (misalnya warnet atau kantor) menekan tombol "Back" dan melihat riwayat data sensitif pengguna sebelumnya dari cache browser.',
                'impact' => 'Kebocoran data pribadi (PII), detail laporan keuangan, atau status akun pengguna.',
            ],
            'secret_leak' => [
                'summary' => 'Ditemukan token autentikasi, API Key, atau kredensial rahasia yang tertulis langsung (hardcoded) pada source code.',
                'cause' => 'Developer menuliskan nilai rahasia langsung di dalam file kode sumber tanpa menggunakan file .env atau Secret Manager.',
                'attack_vector' => 'Penyerang yang memiliki akses ke repositori atau riwayat git commit dapat mengekstrak token ini untuk membajak akun layanan pihak ketiga.',
                'impact' => 'Pengambilalihan akun API, pencurian data sensitif, tagihan tak terduga, dan eskalasi hak akses sistem.',
            ],
            'sql_injection' => [
                'summary' => 'Input pengguna digabungkan langsung ke dalam kueri SQL tanpa melalui parameterized query atau ORM binding yang aman.',
                'cause' => 'Penggunaan konkatenasi string variabel langsung pada method query basis data (contoh: DB::raw atau query SQL mentah).',
                'attack_vector' => 'Penyerang memanipulasi payload input (misalnya input form atau query string HTTP) untuk mengubah struktur query database dan mengeksekusi perintah arbitrer.',
                'impact' => 'Ekstraksi basis data menyeluruh, manipulasi/penghapusan data bisnis, dan potensi pembajakan hak akses administrator.',
            ],
            'xss' => [
                'summary' => 'Data input yang tidak disanitasi dirender langsung ke browser pengguna, memungkinkan injeksi skrip JavaScript jahat.',
                'cause' => 'Aplikasi merender input pengguna tanpa HTML entity escaping (misalnya v-html di Vue atau {!! !!} di Blade).',
                'attack_vector' => 'Penyerang menyematkan payload script ke form atau parameter URL yang dieksekusi di browser korban.',
                'impact' => 'Pencurian session cookie, pembajakan token JWT akun pengguna, manipulasi tampilan web (defacement), dan phishing interaktif.',
            ],
            'rce' => [
                'summary' => 'Penggunaan fungsi berbahaya seperti eval(), exec(), shell_exec(), atau unserialize() dengan parameter yang dapat dikontrol oleh pengguna.',
                'cause' => 'Aplikasi memanggil shell sistem operasi secara langsung menggunakan argumen dinamis dari pengguna.',
                'attack_vector' => 'Penyerang mengirimkan karakter pengontrol shell untuk mengeksekusi proses sistem operasi secara langsung pada server backend.',
                'impact' => 'Server Compromise total, pemasangan web shell, lateral movement ke jaringan internal, dan kehilangan data.',
            ],
            'vulnerable_dependency' => [
                'summary' => 'Paket dependensi / library yang digunakan dalam project memiliki kerentanan keamanan yang telah dipublikasikan (CVE).',
                'cause' => 'Versi library third-party yang tercantum di composer.json / package.json sudah usang dan belum diperbarui ke versi patch aman.',
                'attack_vector' => 'Penyerang memanfaatkan exploit public yang menargetkan fungsi rentan pada versi paket library tersebut.',
                'impact' => 'Bergantung pada CVE terkait, berkisar dari Denial of Service (DoS) hingga Remote Code Execution (RCE).',
            ],
            default => [
                'summary' => "Ditemukan indikasi kelemahan keamanan pada aturan {$finding->rule_id}.",
                'cause' => 'Terdapat implementasi konfigurasi atau baris kode yang belum memenuhi standar pengamanan minimum.',
                'attack_vector' => 'Penyerang dapat memanfaatkan kondisi tidak aman ini untuk melewati kontrol keamanan atau mengeksploitasi logika aplikasi.',
                'impact' => 'Penurunan postur keamanan aplikasi dan potensi eksposur informasi sistem.',
            ],
        };
    }

    private function buildCodePatch(Finding $finding, array $category, string $fileExt): array
    {
        $filePath = $finding->file_path ?: 'src/example.'.$fileExt;

        return match ($category['type']) {
            'clickjacking' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf (atau .htaccess)',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Web server tidak mengirimkan header X-Frame-Options",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header X-Frame-Options \"SAMEORIGIN\" always;\n\n# ATAU PADA APACHE (.htaccess):\nHeader always set X-Frame-Options \"SAMEORIGIN\"",
            ],
            'csp_missing' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Web server tidak membatasi sumber eksekusi script dan aset",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header Content-Security-Policy \"default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:;\" always;\n\n# ATAU PADA LARAVEL MIDDLEWARE:\n\$response->headers->set('Content-Security-Policy', \"default-src 'self'; script-src 'self';\");",
            ],
            'x_content_type_options' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Browser diizinkan menebak MIME-type secara otomatis (MIME sniffing)",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header X-Content-Type-Options \"nosniff\" always;\n\n# ATAU PADA APACHE (.htaccess):\nHeader always set X-Content-Type-Options \"nosniff\"",
            ],
            'hsts_missing' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# HTTPS aktif namun koneksi HTTP awal tidak dipaksa migrasi permanen",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX (Blok Server SSL 443):\nadd_header Strict-Transport-Security \"max-age=31536000; includeSubDomains\" always;\n\n# PASTIKAN JUGA REDIRECT HTTP KE HTTPS:\nserver {\n    listen 80;\n    return 301 https://\$host\$request_uri;\n}",
            ],
            'permissions_policy' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Fitur hardware browser (camera, mic, geolocation) tidak dibatasi",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header Permissions-Policy \"camera=(), microphone=(), geolocation=()\" always;",
            ],
            'coep_coop' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Resource lintas-origin dimuat tanpa isolasi dokumen",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header Cross-Origin-Embedder-Policy \"require-corp\" always;\nadd_header Cross-Origin-Opener-Policy \"same-origin\" always;",
            ],
            'weak_ssl_ciphers' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/nginx.conf',
                'vulnerable_code' => "# KONDISI RENTAN (Protokol dan cipher usang masih aktif):\nssl_protocols TLSv1 TLSv1.1 TLSv1.2;\nssl_ciphers ALL:!aNULL:!eNULL;",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX (Hanya Cipher Kuat & TLS Modern):\nssl_protocols TLSv1.2 TLSv1.3;\nssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;\nssl_prefer_server_ciphers on;",
            ],
            'cache_control' => [
                'language' => 'php',
                'file_path' => 'app/Http/Middleware/PreventBackHistory.php (atau Nginx)',
                'vulnerable_code' => "// KONDISI RENTAN:\n// Respon data akun sensitif disimpan di cache browser publik",
                'secure_code' => "// SOLUSI PERBAIKAN PADA LARAVEL RESPONSE / MIDDLEWARE:\n\$response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');\n\$response->headers->set('Pragma', 'no-cache');\n\$response->headers->set('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');",
            ],
            'secret_leak' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN (Terekspos langsung):\n\$apiKey = \"ghp_9843hf98hf29h3f928h3982h39f28\";\n\$dbPassword = \"super_secret_production_password\";",
                'secure_code' => "// KODE AMAN (Menggunakan Environment Variables & Secret Manager):\n\$apiKey = config('services.github.token') ?: env('GITHUB_API_TOKEN');\n\$dbPassword = env('DB_PASSWORD');\n\n// Pastikan file .env telah masuk dalam daftar .gitignore!",
            ],
            'sql_injection' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN (String Concatenation):\n\$query = DB::select(\"SELECT * FROM users WHERE email = '\" . \$request->input('email') . \"'\");",
                'secure_code' => "// KODE AMAN (Parameter Binding / Eloquent ORM):\n\$user = User::where('email', \$request->input('email'))->first();\n// Atau jika menggunakan raw SQL:\n\$query = DB::select(\"SELECT * FROM users WHERE email = ?\", [\$request->input('email')]);",
            ],
            'xss' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN:\necho \"<div>Halo, \" . \$_GET['name'] . \"</div>\";\n// Pada Vue/React: <div v-html=\"userInput\"></div>",
                'secure_code' => "// KODE AMAN (HTML Escaping):\necho \"<div>Halo, \" . htmlspecialchars(\$_GET['name'], ENT_QUOTES, 'UTF-8') . \"</div>\";\n// Pada Vue/React: <div>{{ userInput }}</div>",
            ],
            'rce' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN:\n\$cmd = \$_GET['target_ip'];\nexec(\"ping -c 4 \" . \$cmd, \$output);",
                'secure_code' => "// KODE AMAN (Whitelisting & Escaping):\n\$target = filter_var(\$_GET['target_ip'], FILTER_VALIDATE_IP);\nif (!\$target) {\n    throw new InvalidArgumentException('Format IP tidak valid.');\n}\nexec(escapeshellcmd(\"ping -c 4 \" . escapeshellarg(\$target)), \$output);",
            ],
            'vulnerable_dependency' => [
                'language' => $fileExt === 'json' ? 'json' : 'bash',
                'file_path' => $fileExt === 'json' ? 'package.json / composer.json' : 'Terminal CLI',
                'vulnerable_code' => "# Versi lama yang memiliki kerentanan CVE:\ncomposer require vendor/package:1.2.0",
                'secure_code' => "# Perbarui ke versi patch terbaru yang telah ditambal:\ncomposer update vendor/package\n# Atau pada Node.js:\nnpm audit fix",
            ],
            default => [
                'language' => 'nginx',
                'file_path' => 'Konfigurasi Web Server / Source Code',
                'vulnerable_code' => "# Tinjau implementasi konfigurasi atau baris kode terkait",
                'secure_code' => "# Terapkan validasi input ketat, aktifkan security headers, dan batasi hak akses",
            ],
        };
    }

    private function buildMitigationSteps(Finding $finding, array $category): array
    {
        return match ($category['type']) {
            'clickjacking' => [
                '1. Tambahkan header `X-Frame-Options: SAMEORIGIN` pada konfigurasi Nginx/Apache atau middleware HTTP.',
                '2. Jika situs perlu disematkan oleh domain tertentu, gunakan direktif CSP `frame-ancestors \'self\' https://trusted-partner.com`.',
                '3. Uji kembali menggunakan browser developer tools untuk memastikan header terkirim pada setiap respon HTTP.',
            ],
            'csp_missing' => [
                '1. Definisikan kebijakan CSP bertahap, mulai dari mode `Content-Security-Policy-Report-Only` untuk memantau resource yang dimuat.',
                '2. Hindari penggunaan `\'unsafe-inline\'` dan `\'unsafe-eval\'` jika memungkinkan.',
                '3. Terapkan header `Content-Security-Policy` final pada konfigurasi Nginx/Apache.',
            ],
            'x_content_type_options' => [
                '1. Tambahkan header `X-Content-Type-Options: nosniff` pada level global web server.',
                '2. Pastikan web server selalu mengirimkan header `Content-Type` yang tepat untuk setiap file statis (misal image/png, application/json).',
            ],
            'hsts_missing' => [
                '1. Pastikan seluruh sertifikat SSL/TLS valid dan tidak kadaluarsa.',
                '2. Tambahkan header `Strict-Transport-Security: max-age=31536000; includeSubDomains` pada blok server port 443.',
                '3. Tambahkan aturan redirect otomatis dari HTTP (port 80) ke HTTPS (port 443).',
            ],
            'permissions_policy' => [
                '1. Tambahkan header `Permissions-Policy` dengan mematikan fitur hardware yang tidak digunakan (contoh: `camera=(), microphone=(), geolocation=()`).',
                '2. Terapkan pada level reverse proxy Nginx/Cloudflare.',
            ],
            'coep_coop' => [
                '1. Pasang header `Cross-Origin-Embedder-Policy: require-corp` dan `Cross-Origin-Opener-Policy: same-origin`.',
                '2. Verifikasi bahwa aset eksternal (CDN/font) mendukung header CORS `Cross-Origin-Resource-Policy`.',
            ],
            'weak_ssl_ciphers' => [
                '1. Nonaktifkan protokol usang SSLv2, SSLv3, TLS 1.0, dan TLS 1.1 pada file konfigurasi Nginx/Apache.',
                '2. Aktifkan hanya TLS 1.2 dan TLS 1.3.',
                '3. Konfigurasikan cipher suite kuat berbasis AEAD (GCM / Poly1305) dan aktifkan `ssl_prefer_server_ciphers on`.',
            ],
            'cache_control' => [
                '1. Pada halaman yang memuat data pengguna atau formulir autentikasi, set header `Cache-Control: no-store, no-cache, must-revalidate`.',
                '2. Hanya izinkan caching (`public, max-age=...`) pada aset statis publik seperti gambar, CSS, dan file JavaScript yang tidak sensitif.',
            ],
            'secret_leak' => [
                '1. Segera lakukan REVOKE / ROTASI pada token yang bocor melalui provider terkait.',
                '2. Pindahkan nilai kredensial ke file `.env` atau Vault / Secret Manager terpusat.',
                '3. Periksa riwayat git commit menggunakan `git-filter-repo` atau BFG Repo-Cleaner untuk menghapus jejak rahasia dari histori git.',
                '4. Pasang pre-commit hook (misalnya pre-commit + Gitleaks) agar rahasia tidak ter-commit lagi di masa mendatang.',
            ],
            'sql_injection' => [
                '1. Ganti semua query string manual dengan Parameterized Prepared Statements atau ORM.',
                '2. Hindari penggunaan interpolasi variabel langsung di dalam klausa `DB::raw()`, `WHERE`, atau `ORDER BY`.',
                '3. Validasi tipe data input (misal pastikan integer menggunakan `validate([\'id\' => \'integer\'])`).',
            ],
            'xss' => [
                '1. Gunakan mekanisme escaping otomatis template engine (misal `{{ $var }}` di Blade atau `{{ var }}` di Vue).',
                '2. Hindari penggunaan method `v-html`, `dangerouslySetInnerHTML`, atau `{!! $var !!}` pada data input publik.',
                '3. Terapkan header HTTP Content-Security-Policy (CSP) untuk membatasi eksekusi skrip tidak terpercaya.',
            ],
            'rce' => [
                '1. Hindari pemanggilan command shell sistem jika tersedia fungsi native di bahasa pemrograman.',
                '2. Jika eksekusi shell tidak dapat dihindari, gunakan whitelist argumen dan gunakan `escapeshellarg()`.',
                '3. Batasi permission user sistem operasi yang menjalankan worker web server.',
            ],
            'vulnerable_dependency' => [
                '1. Jalankan `composer update` atau `npm audit fix` untuk menaikkan versi paket ke versi aman.',
                '2. Pastikan file `composer.lock` atau `package-lock.json` di-commit ke repositori.',
                '3. Aktifkan dependabot atau GitHub Security Alerts pada repositori project.',
            ],
            default => [
                '1. Lakukan code review terfokus pada lokasi file dan baris yang dilaporkan.',
                '2. Terapkan prinsip least privilege (hak akses minimal) dan validasi input berlapis.',
                '3. Tambahkan unit test untuk skenario serangan batas (edge cases).',
            ],
        };
    }

    private function buildOwaspGuidance(Finding $finding): array
    {
        return [
            'top_10_category' => $finding->owasp ?: 'OWASP Top 10 Application Security Risks',
            'defense_in_depth' => 'Terapkan validasi input (Positive Validation), otorisasi berlapis (RBAC/ABAC), dan enkripsi data end-to-end.',
            'verification_method' => 'Jalankan ulang pemindaian TAMENG (Re-run Scan) setelah menerapkan perbaikan untuk memastikan kerentanan telah bersih.',
        ];
    }

    private function defaultCvss(string $severity): float
    {
        return match ($severity) {
            'critical' => 9.8,
            'high' => 8.2,
            'medium' => 5.5,
            'low' => 3.1,
            default => 0.0,
        };
    }
}
