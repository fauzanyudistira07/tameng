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

		// Cap file content to first 300 lines for AI efficiency
		lines := strings.Split(string(contentBytes), "\n")
		cappedLines := lines
		if len(cappedLines) > 300 {
			cappedLines = cappedLines[:300]
		}
		snippet := strings.Join(cappedLines, "\n")

		// 2. Call Gemini AI Neural Auditor if API key is active
		if s.cfg.GeminiAPIKey != "" {
			aiResults, err := s.callGeminiCodeAudit(relPath, snippet)
			if err == nil && len(aiResults) > 0 {
				findings = append(findings, aiResults...)
				continue
			}
		}

		// 3. Fallback to Deterministic Semantic Static Analyzer
		heuristicResults := s.runDeterministicCodeAudit(relPath, lines)
		findings = append(findings, heuristicResults...)
	}

	return findings
}

func (s *AIDeepScanner) collectCandidateFiles(root string) []string {
	var candidates []string
	priorityPatterns := []string{
		"routes/*.php",
		"routes/**/*.php",
		"app/Http/Controllers/*.php",
		"app/Http/Controllers/**/*.php",
		"app/Http/Middleware/*.php",
		"app/Models/*.php",
		"config/*.php",
		".env.example",
		".env",
		"internal/handlers/*.go",
		"internal/routes/*.go",
		"main.go",
		"server.js",
		"app.js",
		"routes/*.js",
		"controllers/*.js",
		"Dockerfile",
		"docker-compose.yml",
	}

	for _, pat := range priorityPatterns {
		matches, err := filepath.Glob(filepath.Join(root, pat))
		if err == nil {
			for _, m := range matches {
				rel, err := filepath.Rel(root, m)
				if err == nil && !strings.Contains(rel, "vendor") && !strings.Contains(rel, "node_modules") {
					candidates = append(candidates, rel)
					if len(candidates) >= 12 { // Audit up to 12 top priority files
						return candidates
					}
				}
			}
		}
	}

	// If no matches by glob, walk shallowly
	if len(candidates) == 0 {
		_ = filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
			if err != nil || info.IsDir() {
				if info != nil && (info.Name() == "vendor" || info.Name() == "node_modules" || info.Name() == ".git") {
					return filepath.SkipDir
				}
				return nil
			}
			ext := filepath.Ext(path)
			if ext == ".php" || ext == ".go" || ext == ".js" || ext == ".ts" || ext == ".env" {
				rel, _ := filepath.Rel(root, path)
				candidates = append(candidates, rel)
				if len(candidates) >= 10 {
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
			"temperature":       0.1,
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

	// Semantic Regex Rule Patterns
	rules := []struct {
		pattern  *regexp.Regexp
		ruleID   string
		title    string
		severity string
		cwe      string
		owasp    string
	}{
		{
			pattern:  regexp.MustCompile(`(?i)(DB::raw|selectRaw|whereRaw|havingRaw)\s*\([^)]*\$`),
			ruleID:   "TAMENG-DET-SQLI-01",
			title:    "Potential Raw SQL Query Injection via Unescaped Variable Interpolation",
			severity: "critical",
			cwe:      "CWE-89: Improper Neutralization of Special Elements used in an SQL Command ('SQL Injection')",
			owasp:    "A03:2021-Injection",
		},
		{
			pattern:  regexp.MustCompile(`(?i)(protected|public)\s+\$guarded\s*=\s*\[\s*\]`),
			ruleID:   "TAMENG-DET-MASSASSIGN-02",
			title:    "Unrestricted Mass Assignment Vulnerability ($guarded = [])",
			severity: "high",
			cwe:      "CWE-915: Improperly Controlled Modification of Dynamically-Determined Object Attributes",
			owasp:    "A04:2021-Insecure Design",
		},
		{
			pattern:  regexp.MustCompile(`(?i)(file_get_contents|curl_init|Http::get|Http::post)\s*\(\s*\$request->`),
			ruleID:   "TAMENG-DET-SSRF-03",
			title:    "Potential Server-Side Request Forgery (SSRF) via Direct User Input in HTTP Client",
			severity: "high",
			cwe:      "CWE-918: Server-Side Request Forgery (SSRF)",
			owasp:    "A10:2021-Server-Side Request Forgery (SSRF)",
		},
		{
			pattern:  regexp.MustCompile(`(?i)('allowed_origins'\s*=>\s*\[\s*'\*'\s*\].*?'supports_credentials'\s*=>\s*true)|('supports_credentials'\s*=>\s*true.*?'allowed_origins'\s*=>\s*\[\s*'\*'\s*\])`),
			ruleID:   "TAMENG-DET-CORS-04",
			title:    "Overly Permissive Cross-Origin Resource Sharing (CORS with Wildcard and Credentials)",
			severity: "high",
			cwe:      "CWE-942: Permissive Cross-domain Policy with Untrusted Domains",
			owasp:    "A05:2021-Security Misconfiguration",
		},
		{
			pattern:  regexp.MustCompile(`(?i)APP_DEBUG\s*=\s*true`),
			ruleID:   "TAMENG-DET-DEBUG-05",
			title:    "Application Debug Mode Enabled in Configuration",
			severity: "medium",
			cwe:      "CWE-200: Exposure of Sensitive Information to an Unauthorized Actor",
			owasp:    "A05:2021-Security Misconfiguration",
		},
		{
			pattern:  regexp.MustCompile(`(?i)(password|secret|token|api_key)\s*=\s*['"][a-zA-Z0-9_\-\.]{8,}['"]`),
			ruleID:   "TAMENG-DET-SECRET-06",
			title:    "Hardcoded Secret or Plaintext Credential Detected in Code",
			severity: "high",
			cwe:      "CWE-798: Use of Hard-coded Credentials",
			owasp:    "A07:2021-Identification and Authentication Failures",
		},
	}

	for lineIdx, line := range lines {
		lineNum := uint32(lineIdx + 1)
		for _, r := range rules {
			if r.pattern.MatchString(line) {
				findings = append(findings, NormalizedFinding{
					RuleID:     r.ruleID,
					Title:      r.title,
					Severity:   r.severity,
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
