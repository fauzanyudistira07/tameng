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
