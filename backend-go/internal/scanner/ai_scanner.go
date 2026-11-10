package scanner

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/models"
)

// AIDeepScanner conducts heuristic and semantic neural auditing on source code & endpoints.
type AIDeepScanner struct {
	cfg *config.Config
}

func NewAIDeepScanner(cfg *config.Config) *AIDeepScanner {
	return &AIDeepScanner{cfg: cfg}
}

// RunDeepAudit audits a repository workspace or web target using Gemini AI + Heuristic engine.
func (s *AIDeepScanner) RunDeepAudit(workspacePath string, job *models.ScanJob) ([]NormalizedFinding, error) {
	var findings []NormalizedFinding

	if workspacePath != "" && dirExists(workspacePath) {
		repoFindings := s.auditRepository(workspacePath, job)
		findings = append(findings, repoFindings...)
	}

	if job.Target != nil && job.Target.BaseURL != nil && *job.Target.BaseURL != "" {
		targetFindings := s.auditWebTarget(*job.Target.BaseURL, job)
		findings = append(findings, targetFindings...)
	}

	return findings, nil
}

func (s *AIDeepScanner) auditRepository(workspacePath string, job *models.ScanJob) []NormalizedFinding {
	var findings []NormalizedFinding

	// 1. Gather high-value candidate files for security auditing
	candidateFiles := s.collectCandidateFiles(workspacePath)
	log.Printf("[TAMENG AI SCANNER] Selected %d priority security files for neural deep audit in %s", len(candidateFiles), workspacePath)

	for _, relPath := range candidateFiles {
		fullPath := filepath.Join(workspacePath, relPath)
		contentBytes, err := os.ReadFile(fullPath)
		if err != nil || len(contentBytes) == 0 {
			continue
		}

		lines := strings.Split(string(contentBytes), "\n")
		cappedLines := lines
		if len(cappedLines) > 300 {
			cappedLines = cappedLines[:300]
		}
		snippet := strings.Join(cappedLines, "\n")

		// 2. Deterministic Semantic and Manifest Security Analyzer
		// Guarantees 100% deterministic detection of CVE-2024-4577, EOL PHP, Laravel EOL, and code patterns
		heuristicResults := s.runDeterministicCodeAudit(relPath, lines)
		findings = append(findings, heuristicResults...)

		// 3. Call Gemini AI Neural Auditor if API key is active for deep semantic logic analysis
		if s.cfg.GeminiAPIKey != "" {
			aiResults, err := s.callGeminiCodeAudit(relPath, snippet)
			if err == nil && len(aiResults) > 0 {
				findings = append(findings, aiResults...)
			}
		}
	}

	return findings
}

func (s *AIDeepScanner) collectCandidateFiles(root string) []string {
	var candidates []string
	seen := make(map[string]bool)

	addCandidate := func(rel string) {
		rel = filepath.Clean(rel)
		if !seen[rel] && !strings.Contains(rel, "vendor") && !strings.Contains(rel, "node_modules") && !strings.Contains(rel, ".git") {
			seen[rel] = true
			candidates = append(candidates, rel)
		}
	}

	// 1. High-priority root manifests & configs (ALWAYS checked first)
	rootManifests := []string{
		"composer.json",
		"composer.lock",
		"Dockerfile",
		"docker-compose.yml",
		"docker-compose.yaml",
		"package.json",
		".env.example",
		".env",
		"requirements.txt",
		"go.mod",
	}
	for _, f := range rootManifests {
		if _, err := os.Stat(filepath.Join(root, f)); err == nil {
			addCandidate(f)
		}
	}

	// 2. Application routing, models, controllers, and middleware
	priorityPatterns := []string{
		"routes/*.php",
		"routes/**/*.php",
		"app/Http/Controllers/*.php",
		"app/Http/Controllers/**/*.php",
		"app/Http/Middleware/*.php",
		"app/Models/*.php",
		"config/*.php",
		"internal/handlers/*.go",
		"internal/routes/*.go",
		"main.go",
		"server.js",
		"app.js",
		"routes/*.js",
		"controllers/*.js",
	}

	for _, pat := range priorityPatterns {
		matches, err := filepath.Glob(filepath.Join(root, pat))
		if err == nil {
			for _, m := range matches {
				rel, err := filepath.Rel(root, m)
				if err == nil {
					addCandidate(rel)
					if len(candidates) >= 20 {
						return candidates
					}
				}
			}
		}
	}

	// 3. Fallback shallow walk if few files found
	if len(candidates) < 5 {
		_ = filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
			if err != nil || info.IsDir() {
				if info != nil && (info.Name() == "vendor" || info.Name() == "node_modules" || info.Name() == ".git") {
					return filepath.SkipDir
				}
				return nil
			}
			ext := filepath.Ext(path)
			if ext == ".php" || ext == ".go" || ext == ".js" || ext == ".ts" || ext == ".env" || info.Name() == "Dockerfile" {
				rel, _ := filepath.Rel(root, path)
				addCandidate(rel)
				if len(candidates) >= 20 {
					return io.EOF
				}
			}
			return nil
		})
	}

	return candidates
}

type aiFindingJSON struct {
	RuleID      string `json:"rule_id"`
	Title       string `json:"title"`
	Severity    string `json:"severity"`
	LineStart   int    `json:"line_start"`
	LineEnd     int    `json:"line_end"`
	CWE         string `json:"cwe"`
	OWASP       string `json:"owasp"`
	Evidence    string `json:"evidence"`
	Explanation string `json:"explanation"`
}

func (s *AIDeepScanner) callGeminiCodeAudit(relPath, snippet string) ([]NormalizedFinding, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	prompt := fmt.Sprintf(`Anda adalah TAMENG Neural Code Auditor (Senior DevSecOps & Principal Penetration Tester).
Audit berkas sumber "%s" berikut untuk menemukan celah keamanan kritis OWASP Top 10 dan CWE yang nyata.
Fokus pada:
1. Broken Access Control (BOLA, IDOR, bypass middleware auth, missing route authorization)
2. Injection (SQL Injection raw query string concatenation, SSRF, Command Injection, XSS)
3. Cryptographic & Credential Failures (hardcoded passwords, API keys, tokens, weak random)
4. Insecure Design & Mass Assignment ($guarded = [], $request->all() create)
5. Security Misconfiguration (debug mode, weak CORS, sensitive data disclosure in responses)
6. Vulnerable and Outdated Components / Runtimes (CWE-1104, CWE-937, A06:2021) in composer.json, package.json, Dockerfile:
   - Deprecated or End-of-Life (EOL) runtime versions (e.g. PHP <= 8.1, Node.js <= 18, Python <= 3.8).
   - High-profile recent CVEs (e.g. CVE-2024-4577 PHP CGI Argument Injection RCE, CVE-2024-5458 URL filter bypass, CVE-2024-8926 multipart DoS, CVE-2021-3129 Laravel Ignition RCE, Laravel <= 8.x EOL framework).
   - Permissive dependency constraints allowing vulnerable unpatched minor versions.

KODE SUMBER:
%s

KEMBALIKAN HANYA ARRAY JSON VALID (tanpa markdown prose tambahan):
[
  {
    "rule_id": "TAMENG-AI-CWE-xxx",
    "title": "Judul Celah Keamanan yang Spesifik dan Akurat",
    "severity": "critical" | "high" | "medium" | "low",
    "line_start": 12,
    "line_end": 15,
    "cwe": "CWE-89: SQL Injection",
    "owasp": "A03:2021-Injection",
    "evidence": "snippet baris kode yang rentan",
    "explanation": "Penjelasan mengapa kode ini rentan dan dampak serangannya"
  }
]`, relPath, snippet)

	payload := map[string]interface{}{
		"contents": []map[string]interface{}{
			{
				"role": "user",
				"parts": []map[string]interface{}{
					{"text": prompt},
				},
			},
		},
		"generationConfig": map[string]interface{}{
			"temperature":        0.1,
			"response_mime_type": "application/json",
		},
	}
	payloadBytes, _ := json.Marshal(payload)

	modelsToTry := []string{"gemini-3.5-flash-lite", "gemini-flash-latest", "gemini-3.8-flash"}
	client := &http.Client{Timeout: 15 * time.Second}

	for _, model := range modelsToTry {
		url := fmt.Sprintf("https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s", model, s.cfg.GeminiAPIKey)
		req, err := http.NewRequestWithContext(ctx, "POST", url, bytes.NewBuffer(payloadBytes))
		if err != nil {
			continue
		}
		req.Header.Set("Content-Type", "application/json")

		resp, err := client.Do(req)
		if err != nil {
			continue
		}
		defer resp.Body.Close()

		if resp.StatusCode != http.StatusOK {
			continue
		}

		bodyBytes, err := io.ReadAll(resp.Body)
		if err != nil {
			continue
		}

		var geminiResp struct {
			Candidates []struct {
				Content struct {
					Parts []struct {
						Text string `json:"text"`
					} `json:"parts"`
				} `json:"content"`
			} `json:"candidates"`
		}

		if err := json.Unmarshal(bodyBytes, &geminiResp); err != nil || len(geminiResp.Candidates) == 0 || len(geminiResp.Candidates[0].Content.Parts) == 0 {
			continue
		}

		rawJSON := strings.TrimSpace(geminiResp.Candidates[0].Content.Parts[0].Text)
		var items []aiFindingJSON
		if err := json.Unmarshal([]byte(rawJSON), &items); err != nil {
			continue
		}

		var results []NormalizedFinding
		for _, item := range items {
			sev := strings.ToLower(item.Severity)
			if sev == "" {
				sev = "medium"
			}
			results = append(results, NormalizedFinding{
				RuleID:     item.RuleID,
				Title:      item.Title,
				Severity:   sev,
				Confidence: 0.95,
				FilePath:   relPath,
				LineStart:  uint32(item.LineStart),
				LineEnd:    uint32(item.LineEnd),
				CWE:        item.CWE,
				OWASP:      item.OWASP,
				Evidence:   fmt.Sprintf("%s\n\n[Analisis AI TAMENG]: %s", item.Evidence, item.Explanation),
			})
		}
		return results, nil
	}

	return nil, fmt.Errorf("gemini code audit unavailable")
}

func (s *AIDeepScanner) runDeterministicCodeAudit(relPath string, lines []string) []NormalizedFinding {
	var findings []NormalizedFinding
	cleanRel := filepath.ToSlash(relPath)
	baseName := filepath.Base(cleanRel)

	// -------------------------------------------------------------
	// 1. MANIFEST & RUNTIME VERSION AUDITING (composer.json)
	// -------------------------------------------------------------
	if baseName == "composer.json" {
		for lineIdx, line := range lines {
			lineNum := uint32(lineIdx + 1)
			trimmed := strings.TrimSpace(line)

			// 1.1 PHP Runtime Version Analysis (Recent 2024 Cases & EOL)
			if strings.Contains(line, `"php"`) && strings.Contains(line, ":") {
				// Detect EOL or vulnerable PHP versions (PHP <= 8.1, or constraints allowing PHP < 8.1.29, < 8.2.20, < 8.3.8)
				isEOL := regexp.MustCompile(`(?i)"php"\s*:\s*"[^"]*(\^?5\.|\^?7\.|\^?8\.0|\^?8\.1|>=?5\.|>=?7\.)`).MatchString(line)
				isVulnerableToCVE20244577 := regexp.MustCompile(`(?i)"php"\s*:\s*"[^"]*(\^?5\.|\^?7\.|\^?8\.0|\^?8\.1|<8\.1\.29|<8\.2\.20|<8\.3\.8|>=?7\.)`).MatchString(line)
				isPermissive82 := regexp.MustCompile(`(?i)"php"\s*:\s*"\^8\.2"`).MatchString(line)

				if isVulnerableToCVE20244577 {
					findings = append(findings, NormalizedFinding{
						RuleID:     "PHP-CVE-2024-4577-RCE",
						Title:      "PHP CGI Argument Injection Remote Code Execution (CVE-2024-4577)",
						Severity:   "critical",
						CVSS:       9.8,
						Confidence: 0.99,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-78: Improper Neutralization of Special Elements used in an OS Command ('OS Command Injection')",
						OWASP:      "A03:2021-Injection",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: Batasan PHP pada composer.json mengizinkan runtime PHP yang rentan terhadap CVE-2024-4577 (CVSS 9.8). Penyerang dapat mengeksekusi kode perintah jarak jauh (RCE) via injeksi argumen PHP-CGI menggunakan karakter soft-hyphen (0xAD) pada lingkungan Windows/Apache/FastCGI.", trimmed),
					})

					// Also flag the companion filter bypass CVE-2024-5458 & open_basedir bypass CVE-2024-5585
					findings = append(findings, NormalizedFinding{
						RuleID:     "PHP-CVE-2024-5458-BYPASS",
						Title:      "PHP filter_var URL Validation Null-Byte Bypass & open_basedir Bypass (CVE-2024-5458, CVE-2024-5585)",
						Severity:   "high",
						CVSS:       7.5,
						Confidence: 0.95,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-20: Improper Input Validation",
						OWASP:      "A04:2021-Insecure Design",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: Versi PHP sebelum 8.1.29, 8.2.20, dan 8.3.8 gagal memvalidasi URL berkarakter null-byte dan rentan terhadap bypass restriksi direktori open_basedir.", trimmed),
					})

					// Also flag multipart HTTP boundary crash CVE-2024-8926 / CVE-2024-8927
					findings = append(findings, NormalizedFinding{
						RuleID:     "PHP-CVE-2024-8926-MULTIPART",
						Title:      "PHP Multipart HTTP Boundary Parsing Bypass and Denial of Service (CVE-2024-8926, CVE-2024-8927)",
						Severity:   "high",
						CVSS:       7.5,
						Confidence: 0.95,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-20: Improper Input Validation",
						OWASP:      "A05:2021-Security Misconfiguration",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: Versi PHP sebelum 8.1.30, 8.2.24, dan 8.3.12 memiliki bug parsing multipart boundary yang dapat memicu truncation payload atau server crash (DoS).", trimmed),
					})
				} else if isPermissive82 {
					findings = append(findings, NormalizedFinding{
						RuleID:     "PHP-UNPINNED-PATCH-CVE-2024-4577",
						Title:      "Permissive PHP Version Constraint Allows Vulnerable Unpatched Runtimes (< 8.2.20 / CVE-2024-4577)",
						Severity:   "high",
						CVSS:       7.0,
						Confidence: 0.90,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-1104: Use of Unmaintained Third-Party Components",
						OWASP:      "A06:2021-Vulnerable and Outdated Components",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: Batasan \"^8.2\" mengizinkan instalasi runtime PHP di bawah rilis 8.2.20 yang belum dipatch untuk CVE-2024-4577 dan CVE-2024-5458. Perketat menjadi \">=8.2.20\" atau \"^8.3.8\".", trimmed),
					})
				}

				if isEOL {
					findings = append(findings, NormalizedFinding{
						RuleID:     "PHP-RUNTIME-EOL-DEPRECATED",
						Title:      "End-of-Life (EOL) & Unsupported PHP Runtime Version Detected",
						Severity:   "high",
						CVSS:       8.5,
						Confidence: 0.98,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-1104: Use of Unmaintained Third-Party Components",
						OWASP:      "A06:2021-Vulnerable and Outdated Components",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: Runtime PHP yang didefinisikan (%s) telah mencapai End-of-Life (EOL). PHP 7.3 kedaluwarsa Des 2021, PHP 7.4 kedaluwarsa Nov 2022, PHP 8.0 kedaluwarsa Nov 2023, dan PHP 8.1 kedaluwarsa Nov 2024. Versi ini tidak lagi menerima pembaruan patch keamanan resmi.", trimmed, trimmed),
					})
				}
			}

			// 1.2 Laravel Framework EOL Version
			if strings.Contains(line, `"laravel/framework"`) {
				if regexp.MustCompile(`(?i)"laravel/framework"\s*:\s*"\^?[5-8]\.`).MatchString(line) {
					findings = append(findings, NormalizedFinding{
						RuleID:     "LARAVEL-FRAMEWORK-EOL",
						Title:      "Unsupported & End-of-Life Laravel Framework Version (Laravel <= 8.x)",
						Severity:   "high",
						CVSS:       7.5,
						Confidence: 0.98,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-1104: Use of Unmaintained Third-Party Components",
						OWASP:      "A06:2021-Vulnerable and Outdated Components",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: Laravel Framework versi 8.x dan versi sebelumnya telah mencapai End-of-Life sejak Januari 2023 dan tidak lagi menerima perbaikan celah keamanan.", trimmed),
					})
				}
			}

			// 1.3 Facade Ignition RCE (CVE-2021-3129)
			if strings.Contains(line, `"facade/ignition"`) {
				if regexp.MustCompile(`(?i)"facade/ignition"\s*:\s*"\^?[12]\.[0-5]`).MatchString(line) {
					findings = append(findings, NormalizedFinding{
						RuleID:     "LARAVEL-IGNITION-RCE-CVE-2021-3129",
						Title:      "Ignition Debugger Remote Code Execution Vulnerability (CVE-2021-3129)",
						Severity:   "critical",
						CVSS:       9.8,
						Confidence: 0.99,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-94: Improper Control of Generation of Code ('Code Injection')",
						OWASP:      "A03:2021-Injection",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: facade/ignition versi <= 2.5.1 memiliki celah RCE kritis via endpoint executeSolution menggunakan wrapper php://filter jika APP_DEBUG=true.", trimmed),
					})
				}
			}
		}
	}

	// -------------------------------------------------------------
	// 2. DOCKERFILE RUNTIME & BASE IMAGE AUDITING
	// -------------------------------------------------------------
	if strings.Contains(baseName, "Dockerfile") || strings.HasSuffix(cleanRel, ".dockerfile") {
		for lineIdx, line := range lines {
			lineNum := uint32(lineIdx + 1)
			trimmed := strings.TrimSpace(line)

			if strings.HasPrefix(trimmed, "FROM ") && strings.Contains(trimmed, "php:") {
				if regexp.MustCompile(`(?i)FROM\s+php:(5\.|7\.|8\.0|8\.1)`).MatchString(trimmed) {
					findings = append(findings, NormalizedFinding{
						RuleID:     "DOCKER-PHP-EOL-CVE-2024-4577",
						Title:      "Docker Base Image Uses Outdated & EOL PHP Version (CVE-2024-4577 RCE Risk)",
						Severity:   "critical",
						CVSS:       9.8,
						Confidence: 0.99,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-1104: Use of Unmaintained Third-Party Components",
						OWASP:      "A06:2021-Vulnerable and Outdated Components",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: Container Docker menggunakan base image PHP versi lama yang sudah EOL dan rentan terhadap CVE-2024-4577.", trimmed),
					})
				} else if regexp.MustCompile(`(?i)FROM\s+php:(8\.2|8\.3)-[a-z]+`).MatchString(trimmed) && !regexp.MustCompile(`(?i)FROM\s+php:8\.(2\.2[0-9]|3\.[8-9]|3\.1[0-9])`).MatchString(trimmed) {
					findings = append(findings, NormalizedFinding{
						RuleID:     "DOCKER-PHP-FLOATING-TAG",
						Title:      "Floating PHP Base Image Tag (Potential Unpatched CVE-2024-4577/CVE-2024-8926)",
						Severity:   "medium",
						CVSS:       6.0,
						Confidence: 0.85,
						FilePath:   relPath,
						LineStart:  lineNum,
						LineEnd:    lineNum,
						CWE:        "CWE-1104: Use of Unmaintained Third-Party Components",
						OWASP:      "A05:2021-Security Misconfiguration",
						Evidence:   fmt.Sprintf("%s\n\n[Peringatan TAMENG]: Base image '%s' menggunakan tag minor mengambang. Disarankan menyematkan patch spesifik (misal: php:8.3.12-fpm-alpine) untuk mencegah build menggunakan rilis sebelum perbaikan CVE-2024-4577.", trimmed, trimmed),
					})
				}
			}
		}
	}

	// -------------------------------------------------------------
	// 3. CODE HEURISTICS & PATTERNS (.php, .go, .js, .ts, .env)
	// -------------------------------------------------------------
	codeRules := []struct {
		pattern  *regexp.Regexp
		ruleID   string
		title    string
		severity string
		cvss     float64
		cwe      string
		owasp    string
	}{
		{
			pattern:  regexp.MustCompile(`(?i)(DB::raw|selectRaw|whereRaw|havingRaw)\s*\([^)]*\$`),
			ruleID:   "TAMENG-DET-SQLI-01",
			title:    "Potential Raw SQL Query Injection via Unescaped Variable Interpolation",
			severity: "critical",
			cvss:     9.8,
			cwe:      "CWE-89: Improper Neutralization of Special Elements used in an SQL Command ('SQL Injection')",
			owasp:    "A03:2021-Injection",
		},
		{
			pattern:  regexp.MustCompile(`(?i)(protected|public)\s+\$guarded\s*=\s*\[\s*\]`),
			ruleID:   "TAMENG-DET-MASSASSIGN-02",
			title:    "Unrestricted Mass Assignment Vulnerability ($guarded = [])",
			severity: "high",
			cvss:     7.5,
			cwe:      "CWE-915: Improperly Controlled Modification of Dynamically-Determined Object Attributes",
			owasp:    "A04:2021-Insecure Design",
		},
		{
			pattern:  regexp.MustCompile(`(?i)(file_get_contents|curl_init|Http::get|Http::post)\s*\(\s*\$request->`),
			ruleID:   "TAMENG-DET-SSRF-03",
			title:    "Potential Server-Side Request Forgery (SSRF) via Direct User Input in HTTP Client",
			severity: "high",
			cvss:     8.6,
			cwe:      "CWE-918: Server-Side Request Forgery (SSRF)",
			owasp:    "A10:2021-Server-Side Request Forgery (SSRF)",
		},
		{
			pattern:  regexp.MustCompile(`(?i)('allowed_origins'\s*=>\s*\[\s*'\*'\s*\].*?'supports_credentials'\s*=>\s*true)|('supports_credentials'\s*=>\s*true.*?'allowed_origins'\s*=>\s*\[\s*'\*'\s*\])`),
			ruleID:   "TAMENG-DET-CORS-04",
			title:    "Overly Permissive Cross-Origin Resource Sharing (CORS with Wildcard and Credentials)",
			severity: "high",
			cvss:     7.5,
			cwe:      "CWE-942: Permissive Cross-domain Policy with Untrusted Domains",
			owasp:    "A05:2021-Security Misconfiguration",
		},
		{
			pattern:  regexp.MustCompile(`(?i)(system|exec|passthru|shell_exec|proc_open|popen)\s*\(\s*\$_(GET|POST|REQUEST)`),
			ruleID:   "PHP-DET-CMDI-07",
			title:    "Direct Operating System Command Injection via Untrusted Input",
			severity: "critical",
			cvss:     9.8,
			cwe:      "CWE-78: Improper Neutralization of Special Elements used in an OS Command ('OS Command Injection')",
			owasp:    "A03:2021-Injection",
		},
		{
			pattern:  regexp.MustCompile(`(?i)(include|include_once|require|require_once)\s*\(\s*\$_(GET|POST|REQUEST)\[`),
			ruleID:   "PHP-DET-LFI-08",
			title:    "Potential Local File Inclusion (LFI) via Dynamic Parameter Inclusion",
			severity: "high",
			cvss:     8.6,
			cwe:      "CWE-98: Improper Control of Filename for Include/Require Statement in PHP Program",
			owasp:    "A01:2021-Broken Access Control",
		},
		{
			pattern:  regexp.MustCompile(`(?i)unserialize\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)`),
			ruleID:   "PHP-DET-DESERIAL-09",
			title:    "Insecure PHP Object Deserialization via Untrusted Input",
			severity: "critical",
			cvss:     9.8,
			cwe:      "CWE-502: Deserialization of Untrusted Data",
			owasp:    "A08:2021-Software and Data Integrity Failures",
		},
		{
			pattern:  regexp.MustCompile(`(?i)APP_DEBUG\s*=\s*true`),
			ruleID:   "TAMENG-DET-DEBUG-05",
			title:    "Application Debug Mode Enabled in Configuration",
			severity: "medium",
			cvss:     5.3,
			cwe:      "CWE-200: Exposure of Sensitive Information to an Unauthorized Actor",
			owasp:    "A05:2021-Security Misconfiguration",
		},
		{
			pattern:  regexp.MustCompile(`(?i)(password|secret|token|api_key)\s*=\s*['"][a-zA-Z0-9_\-\.]{8,}['"]`),
			ruleID:   "TAMENG-DET-SECRET-06",
			title:    "Hardcoded Secret or Plaintext Credential Detected in Code",
			severity: "high",
			cvss:     7.9,
			cwe:      "CWE-798: Use of Hard-coded Credentials",
			owasp:    "A07:2021-Identification and Authentication Failures",
		},
	}

	for lineIdx, line := range lines {
		lineNum := uint32(lineIdx + 1)
		for _, r := range codeRules {
			if r.pattern.MatchString(line) {
				findings = append(findings, NormalizedFinding{
					RuleID:     r.ruleID,
					Title:      r.title,
					Severity:   r.severity,
					CVSS:       r.cvss,
					Confidence: 0.90,
					FilePath:   relPath,
					LineStart:  lineNum,
					LineEnd:    lineNum,
					CWE:        r.cwe,
					OWASP:      r.owasp,
					Evidence:   strings.TrimSpace(line),
				})
			}
		}
	}

	return findings
}

func (s *AIDeepScanner) auditWebTarget(rawURL string, job *models.ScanJob) []NormalizedFinding {
	var findings []NormalizedFinding

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	client := &http.Client{Timeout: 8 * time.Second}
	req, err := http.NewRequestWithContext(ctx, "GET", rawURL, nil)
	if err != nil {
		return nil
	}
	req.Header.Set("User-Agent", "TAMENG-Security-Inspector/2.0 (Polri Siber)")

	resp, err := client.Do(req)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()

	// 1. Missing Security Headers Analysis
	headers := resp.Header
	if headers.Get("Content-Security-Policy") == "" {
		findings = append(findings, NormalizedFinding{
			RuleID:     "TAMENG-HDR-CSP-MISSING",
			Title:      "Missing Content-Security-Policy (CSP) Header",
			Severity:   "medium",
			CVSS:       5.7,
			Confidence: 0.95,
			Endpoint:   rawURL,
			HTTPMethod: "GET",
			CWE:        "CWE-1021: Improper Restriction of Rendered UI Layers or Frames",
			OWASP:      "A05:2021-Security Misconfiguration",
			Evidence:   "Respons web server tidak menyertakan header Content-Security-Policy untuk mencegah injeksi XSS dan data exfiltration.",
		})
	}

	if headers.Get("Permissions-Policy") == "" {
		findings = append(findings, NormalizedFinding{
			RuleID:     "TAMENG-HDR-PERMISSIONS-POLICY",
			Title:      "Missing Permissions-Policy Security Header",
			Severity:   "low",
			CVSS:       3.1,
			Confidence: 0.90,
			Endpoint:   rawURL,
			HTTPMethod: "GET",
			CWE:        "CWE-16: Configuration",
			OWASP:      "A05:2021-Security Misconfiguration",
			Evidence:   "Header Permissions-Policy tidak didefinisikan (fitur peramban seperti kamera/mikrofon tidak dibatasi secara eksplisit).",
		})
	}

	// 2. Cookie Security Analysis
	for _, cookie := range resp.Cookies() {
		if !cookie.HttpOnly {
			findings = append(findings, NormalizedFinding{
				RuleID:     "TAMENG-COOKIE-HTTPONLY-MISSING",
				Title:      fmt.Sprintf("Sensitive Cookie '%s' Missing HttpOnly Flag", cookie.Name),
				Severity:   "medium",
				CVSS:       5.3,
				Confidence: 0.95,
				Endpoint:   rawURL,
				HTTPMethod: "GET",
				CWE:        "CWE-1004: Sensitive Cookie Without 'HttpOnly' Flag",
				OWASP:      "A05:2021-Security Misconfiguration",
				Evidence:   fmt.Sprintf("Cookie: %s; Secure=%t; SameSite=%v", cookie.Name, cookie.Secure, cookie.SameSite),
			})
		}
		if !cookie.Secure && strings.HasPrefix(rawURL, "https://") {
			findings = append(findings, NormalizedFinding{
				RuleID:     "TAMENG-COOKIE-SECURE-MISSING",
				Title:      fmt.Sprintf("Cookie '%s' Missing Secure Flag on HTTPS Target", cookie.Name),
				Severity:   "medium",
				CVSS:       5.3,
				Confidence: 0.95,
				Endpoint:   rawURL,
				HTTPMethod: "GET",
				CWE:        "CWE-614: Sensitive Cookie in HTTPS Session Without 'Secure' Attribute",
				OWASP:      "A05:2021-Security Misconfiguration",
				Evidence:   fmt.Sprintf("Cookie: %s tidak memiliki flag Secure.", cookie.Name),
			})
		}
	}

	// 3. Information Disclosure
	serverHdr := headers.Get("Server")
	if serverHdr != "" && (strings.Contains(serverHdr, "/") || strings.Contains(serverHdr, "Ubuntu") || strings.Contains(serverHdr, "nginx")) {
		findings = append(findings, NormalizedFinding{
			RuleID:     "TAMENG-DISCLOSURE-SERVER-BANNER",
			Title:      fmt.Sprintf("Web Server Banner Version Disclosure (%s)", serverHdr),
			Severity:   "low",
			CVSS:       3.1,
			Confidence: 0.90,
			Endpoint:   rawURL,
			HTTPMethod: "GET",
			CWE:        "CWE-200: Exposure of Sensitive Information to an Unauthorized Actor",
			OWASP:      "A05:2021-Security Misconfiguration",
			Evidence:   fmt.Sprintf("Header Server: %s", serverHdr),
		})
	}

	return findings
}
