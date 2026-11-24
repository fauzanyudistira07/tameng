package scanner

import (
	"os"
	"strings"
	"testing"

	"tameng-backend-go/internal/config"
)

func TestCloudflareDualAgentPlaybookAndRecon(t *testing.T) {
	cfg := &config.Config{}
	s := NewAIDeepScanner(cfg)

	if s.skillDir == "" {
		t.Fatalf("expected Cloudflare security-audit skill directory to be loaded")
	}
	if !strings.Contains(s.hunterDoctrine, "CLOUDFLARE SECURITY-AUDIT SKILL DOCTRINE") {
		t.Fatalf("expected hunterDoctrine to be populated from HUNTING.md / ATTACK-CLASSES.md")
	}
	if !strings.Contains(s.verifierDoctrine, "CLOUDFLARE VERIFIER DOCTRINE") {
		t.Fatalf("expected verifierDoctrine to be populated from VALIDATION-AND-REPORTING.md")
	}

	// Test deterministic audit on vulnerable composer.json snippet
	composerLines := []string{
		`{`,
		`  "require": {`,
		`    "php": "^7.3|^8.0",`,
		`    "laravel/framework": "^8.75"`,
		`  },`,
		`  "require-dev": {`,
		`    "facade/ignition": "^2.5"`,
		`  }`,
		`}`,
	}
	findings := s.runDeterministicCodeAudit("composer.json", composerLines)
	if len(findings) < 4 {
		t.Fatalf("expected at least 4 deterministic findings for vulnerable PHP/Laravel/Ignition manifest, got %d", len(findings))
	}
	for _, f := range findings {
		if f.VerificationStatus != "confirmed" || !f.DualAgentConsensus {
			t.Errorf("finding %s missing confirmed dual-agent consensus metadata", f.RuleID)
		}
	}

	// Test Phase 1 Recon if sample Laravel workspace exists
	sampleWorkspace := "/data/repository-workspaces/32-sanzadtpresensigps"
	if _, err := os.Stat(sampleWorkspace); err == nil {
		arch := s.buildArchitectureContext(sampleWorkspace)
		if !strings.Contains(arch, "routes/web.php") || !strings.Contains(arch, "composer.json") {
			t.Errorf("expected architecture context to include routes/web.php and composer.json")
		}
		companions := s.selectCompanionHints(sampleWorkspace)
		if companions == "" {
			t.Errorf("expected non-empty Cloudflare companion hints")
		}
	}
}

func TestCloudflareDualAgentLiveQC(t *testing.T) {
	if os.Getenv("RUN_LIVE_QC") != "1" {
		t.Skip("set RUN_LIVE_QC=1 to run live Gemini Hunter-vs-Verifier QC")
	}

	// Load .env from backend-go root
	envBytes, err := os.ReadFile("../../.env")
	if err != nil {
		t.Fatalf("failed reading .env: %v", err)
	}
	var apiKey string
	for _, line := range strings.Split(string(envBytes), "\n") {
		if strings.HasPrefix(line, "GEMINI_API_KEY=") {
			apiKey = strings.TrimSpace(strings.TrimPrefix(line, "GEMINI_API_KEY="))
		}
	}
	if apiKey == "" {
		t.Fatalf("GEMINI_API_KEY empty in .env")
	}

	s := NewAIDeepScanner(&config.Config{GeminiAPIKey: apiKey})
	ws := "/data/repository-workspaces/32-sanzadtpresensigps"
	arch := s.buildArchitectureContext(ws)
	companions := s.selectCompanionHints(ws)

	// QC 1: Live Hunter vs Verifier on KaryawanController.php
	karyawanBytes, err := os.ReadFile(ws + "/app/Http/Controllers/KaryawanController.php")
	if err != nil {
		t.Fatalf("failed reading KaryawanController.php: %v", err)
	}
	results, stats, err := s.runDualAgentAudit("app/Http/Controllers/KaryawanController.php", string(karyawanBytes), arch, companions)
	if err != nil {
		t.Fatalf("runDualAgentAudit failed: %v", err)
	}
	t.Logf("[QC LIVE DUAL-AGENT] KaryawanController stats: Proposed=%d Confirmed=%d NeedsValidation=%d Rejected=%d Kept=%d",
		stats.Proposed, stats.Confirmed, stats.NeedsValidation, stats.Rejected, len(results))
	if stats.Proposed == 0 || len(results) == 0 {
		t.Fatalf("expected Hunter+Verifier to find and accept vulnerabilities in KaryawanController.php")
	}
	for _, f := range results {
		t.Logf("  -> [%s] (%s) %s | Rationale: %s", strings.ToUpper(f.VerificationStatus), strings.ToUpper(f.Severity), f.Title, f.VerifierRationale)
		if !f.DualAgentConsensus || f.VerifierRationale == "" {
			t.Errorf("finding %s missing DualAgentConsensus or VerifierRationale", f.RuleID)
		}
	}

	// QC 2: Cross-Engine Verifier False-Positive Refutation Test
	// Inject 1 deliberate FALSE POSITIVE (IDOR on PresensiController::updateprofile, mitigated by Auth::guard('karyawan')->user()->nis)
	// and 1 TRUE POSITIVE (Hardcoded password '1' in KaryawanController::store)
	syntheticExternal := []NormalizedFinding{
		{
			RuleID:    "SEMGREP-FP-IDOR-UPDATEPROFILE",
			Title:     "Potential IDOR via {nis} URL parameter in updateprofile",
			Severity:  "high",
			FilePath:  "app/Http/Controllers/PresensiController.php",
			LineStart: 197,
			LineEnd:   207,
			CWE:       "CWE-639",
			Evidence:  "public function updateprofile(Request $request) { $nis = Auth::guard('karyawan')->user()->nis; ... }",
		},
		{
			RuleID:    "SEMGREP-TP-HARDCODED-PASS",
			Title:     "Hardcoded default password '1' in KaryawanController::store",
			Severity:  "high",
			FilePath:  "app/Http/Controllers/KaryawanController.php",
			LineStart: 38,
			LineEnd:   44,
			CWE:       "CWE-798",
			Evidence:  "$password = Hash::make('1');",
		},
	}

	filtered := s.VerifyExternalFindings(ws, "semgrep", syntheticExternal)
	t.Logf("[QC LIVE CROSS-VERIFIER] Input=%d Output=%d", len(syntheticExternal), len(filtered))
	keptFP := false
	keptTP := false
	for _, f := range filtered {
		t.Logf("  -> KEPT [%s] Status=%s | Rationale: %s", f.RuleID, f.VerificationStatus, f.VerifierRationale)
		if f.RuleID == "SEMGREP-FP-IDOR-UPDATEPROFILE" {
			keptFP = true
		}
		if f.RuleID == "SEMGREP-TP-HARDCODED-PASS" {
			keptTP = true
		}
	}
	if keptFP {
		t.Errorf("expected Cloudflare Verifier to REJECT false positive SEMGREP-FP-IDOR-UPDATEPROFILE, but it was kept!")
	}
	if !keptTP {
		t.Errorf("expected Cloudflare Verifier to KEEP true positive SEMGREP-TP-HARDCODED-PASS, but it was dropped!")
	}
}
