package services

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"path/filepath"
	"strings"
	"time"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/models"

	"github.com/redis/go-redis/v9"
	"gorm.io/gorm"
)

type AiRemediationResult struct {
	FindingID           uint64                 `json:"finding_id"`
	FindingCode         string                 `json:"finding_code"`
	RuleID              *string                `json:"rule_id"`
	Title               string                 `json:"title"`
	Severity            string                 `json:"severity"`
	Category            string                 `json:"category"`
	Summary             string                 `json:"summary"`
	Cause               *string                `json:"cause"`
	AttackVector        string                 `json:"attack_vector"`
	BusinessImpact      string                 `json:"business_impact"`
	CodeDiff            string                 `json:"code_diff"`
	VulnerableCode      *string                `json:"vulnerable_code"`
	SecureCode          *string                `json:"secure_code"`
	MitigationChecklist []string               `json:"mitigation_checklist"`
	VerificationCommand *string                `json:"verification_command"`
	Compliance          map[string]interface{}    `json:"compliance"`
	OwaspGuidance       map[string]interface{}    `json:"owasp_guidance"`
	AiModel             string                    `json:"ai_model"`
	AiBadge             string                    `json:"ai_badge"`
	IsLiveLLM           bool                      `json:"is_live_llm"`
	Disclaimer          string                    `json:"disclaimer"`
	NeurolinkSynapses   []models.NeurolinkSynapse `json:"neurolink_synapses,omitempty"`
	NeurolinkMemoryApplied bool                   `json:"neurolink_memory_applied"`
	SynapseMatchCount   int                       `json:"synapse_match_count"`
}

type AiRemediationService struct {
	db        *gorm.DB
	rdb       *redis.Client
	cfg       *config.Config
	neurolink *NeurolinkService
}

func NewAiRemediationService(db *gorm.DB, rdb *redis.Client, cfg *config.Config, neurolink *NeurolinkService) *AiRemediationService {
	return &AiRemediationService{
		db:        db,
		rdb:       rdb,
		cfg:       cfg,
		neurolink: neurolink,
	}
}

// GenerateGuidance produces comprehensive AI remediation guidance
func (s *AiRemediationService) GenerateGuidance(finding *models.Finding) (*AiRemediationResult, error) {
	cacheKey := fmt.Sprintf("ai_remediation_gemini_%d_%d", finding.ID, finding.UpdatedAt.Unix())

	// 1. Check Redis Cache
	if s.rdb != nil {
		cached, err := s.rdb.Get(context.Background(), cacheKey).Result()
		if err == nil && cached != "" {
			var res AiRemediationResult
			if err := json.Unmarshal([]byte(cached), &res); err == nil && res.Summary != "" {
				return &res, nil
			}
		}
	}

	var synapses []models.NeurolinkSynapse
	if s.neurolink != nil {
		synapses = s.neurolink.FindMatchingSynapses(finding)
	}

	var result *AiRemediationResult

	// 2. Attempt Google Gemini LLM Generation if API key is configured
	if s.cfg.GeminiAPIKey != "" {
		res, err := s.callGeminiAPI(finding, synapses)
		if err != nil {
			log.Printf("[AI] Gemini API failed for finding #%d: %v. Falling back to heuristic engine.", finding.ID, err)
		} else if res != nil && res.Summary != "" {
			result = res
		}
	}

	// 3. Fallback to Advanced Cyber Intelligence Heuristic Engine
	if result == nil {
		result = s.generateAdvancedHeuristic(finding, synapses)
	}

	if result != nil {
		result.NeurolinkSynapses = synapses
		result.NeurolinkMemoryApplied = len(synapses) > 0
		result.SynapseMatchCount = len(synapses)

		// Autonomous Continuous Learning: System auto-learns and distills synaptic memory without user input
		if s.neurolink != nil {
			go s.neurolink.AutoLearnRemediation(finding, result)
		}
	}

	// 4. Save to Redis Cache (24 hours TTL)
	if s.rdb != nil && result != nil {
		dataBytes, _ := json.Marshal(result)
		_ = s.rdb.Set(context.Background(), cacheKey, string(dataBytes), 24*time.Hour).Err()
	}

	return result, nil
}

func (s *AiRemediationService) callGeminiAPI(finding *models.Finding, synapses []models.NeurolinkSynapse) (*AiRemediationResult, error) {
	modelsToTry := []string{s.cfg.GeminiModel, "gemini-3.5-flash-lite", "gemini-3.5-flash", "gemini-3.8-flash"}
	apiKey := s.cfg.GeminiAPIKey
	prompt := s.buildPrompt(finding, synapses)

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
			"temperature": 0.2,
		},
	}
	payloadBytes, _ := json.Marshal(payload)

	client := &http.Client{
		Timeout: time.Duration(s.cfg.AITimeoutSeconds) * time.Second,
	}

	for _, model := range modelsToTry {
		if model == "" {
			continue
		}
		url := fmt.Sprintf("https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s", model, apiKey)
		req, err := http.NewRequest("POST", url, bytes.NewBuffer(payloadBytes))
		if err != nil {
			continue
		}
		req.Header.Set("Content-Type", "application/json")

		resp, err := client.Do(req)
		if err != nil {
			log.Printf("[AI] Request error on %s: %v", model, err)
			continue
		}
		defer resp.Body.Close()

		bodyBytes, err := io.ReadAll(resp.Body)
		if err != nil || resp.StatusCode != http.StatusOK {
			log.Printf("[AI] Model %s returned status %d: %s", model, resp.StatusCode, string(bodyBytes))
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

		rawText := geminiResp.Candidates[0].Content.Parts[0].Text
		cleanJSON := strings.TrimSpace(rawText)
		cleanJSON = strings.TrimPrefix(cleanJSON, "```json")
		cleanJSON = strings.TrimPrefix(cleanJSON, "```")
		cleanJSON = strings.TrimSuffix(cleanJSON, "```")
		cleanJSON = strings.TrimSpace(cleanJSON)

		var parsed map[string]interface{}
		if err := json.Unmarshal([]byte(cleanJSON), &parsed); err != nil {
			log.Printf("[AI] Failed to parse JSON from %s: %v", model, err)
			continue
		}

		modelBadge := fmt.Sprintf("Google Gemini (%s)", strings.ToUpper(strings.TrimPrefix(model, "gemini-")))
		return s.formatLlmResult(finding, parsed, modelBadge, "gemini"), nil
	}

	return nil, fmt.Errorf("all gemini models failed")
}

func (s *AiRemediationService) buildPrompt(finding *models.Finding, synapses []models.NeurolinkSynapse) string {
	projectName := "Aplikasi Web"
	if finding.Project.Name != "" {
		projectName = finding.Project.Name
	}
	engine := "Security Scanner"
	if finding.EngineKey != "" {
		engine = finding.EngineKey
	}
	filePath := "Web Server Configuration"
	if finding.FilePath != nil && *finding.FilePath != "" {
		filePath = *finding.FilePath
	} else if finding.Endpoint != nil && *finding.Endpoint != "" {
		filePath = *finding.Endpoint
	}

	lineInfo := "Header HTTP / Konfigurasi Global"
	if finding.LineStart != nil {
		if finding.LineEnd != nil && *finding.LineEnd > *finding.LineStart {
			lineInfo = fmt.Sprintf("Baris %d-%d", *finding.LineStart, *finding.LineEnd)
		} else {
			lineInfo = fmt.Sprintf("Baris %d", *finding.LineStart)
		}
	}

	ruleID := "-"
	if finding.RuleID != nil {
		ruleID = *finding.RuleID
	}
	cwe := "-"
	if finding.CWE != nil {
		cwe = *finding.CWE
	}
	owasp := "-"
	if finding.OWASP != nil {
		owasp = *finding.OWASP
	}
	cvss := "-"
	if finding.CVSS != nil {
		cvss = fmt.Sprintf("%.1f", *finding.CVSS)
	}

	synapseContext := ""
	if len(synapses) > 0 {
		var sb strings.Builder
		sb.WriteString("\nMEMORI SINAPTIK NEUROLINK (CONTINUOUS LEARNING INJECTION):\n")
		sb.WriteString("TAMENG NeuroLink mendeteksi riwayat pembelajaran dari insiden/triase serupa sebelumnya:\n")
		for i, syn := range synapses {
			patch := "-"
			if syn.RemediationPatch != nil && *syn.RemediationPatch != "" {
				patch = *syn.RemediationPatch
			}
			notes := "-"
			if syn.ResolutionNotes != nil && *syn.ResolutionNotes != "" {
				notes = *syn.ResolutionNotes
			}
			sb.WriteString(fmt.Sprintf("%d. [Sinapse: %s | Kategori: %s | Skor Bobot: %.1f]\n   - Solusi Terbukti: %s\n   - Catatan Triase Tim: %s\n   - Konteks: %s\n",
				i+1, syn.SynapseCode, syn.Category, syn.AccuracyWeight, patch, notes, syn.LearnedContext))
		}
		sb.WriteString("PETUNJUK: Manfaatkan dan selaraskan solusi perbaikan dengan memori sinaptik di atas agar konsisten dengan standar keamanan tim.\n")
		synapseContext = sb.String()
	}

	return fmt.Sprintf(`Anda adalah AI Spesialis Keamanan Siber Aplikasi (Senior DevSecOps & Certified Penetration Tester) tingkat dunia untuk platform TAMENG.
Berikan analisis kerentanan dan rekomendasi perbaikan kode (remediation patch) yang mendalam, presisi, canggih, dan dapat langsung dieksekusi oleh developer.%s

KONTEKS TEMUAN:
- Judul Temuan: %s
- Scanner Engine: %s
- Aturan / Rule ID: %s
- Tingkat Keparahan: %s
- Project Target: %s
- Lokasi Berkas / Endpoint: %s
- Baris Kode: %s
- CWE: %s
- OWASP: %s
- CVSS: %s

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
  "code_diff": "Perbandingan jelas kode sebelum dan sesudah perbaikan",
  "mitigation_checklist": [
    "Langkah perbaikan 1 yang spesifik",
    "Langkah perbaikan 2",
    "Langkah perbaikan 3",
    "Langkah perbaikan 4"
  ],
  "verification_command": "Perintah CLI atau metode testing untuk memverifikasi perbaikan (contoh: perintah curl, composer audit, npm audit)",
  "defense_in_depth": "Rekomendasi arsitektur keamanan berlapis tambahan untuk mencegah celah serupa"
}`, synapseContext, finding.Title, engine, ruleID, finding.Severity, projectName, filePath, lineInfo, cwe, owasp, cvss)
}

func (s *AiRemediationService) formatLlmResult(finding *models.Finding, parsed map[string]interface{}, modelName, badgeType string) *AiRemediationResult {
	summary, _ := parsed["summary"].(string)
	category, _ := parsed["category"].(string)
	if category == "" {
		category = finding.Title
	}
	cause, _ := parsed["cause"].(string)
	attackVector, _ := parsed["attack_vector"].(string)
	businessImpact, _ := parsed["business_impact"].(string)
	vulnerableCode, _ := parsed["vulnerable_code"].(string)
	secureCode, _ := parsed["secure_code"].(string)
	codeDiff, _ := parsed["code_diff"].(string)
	verificationCommand, _ := parsed["verification_command"].(string)
	defenseInDepth, _ := parsed["defense_in_depth"].(string)

	if codeDiff == "" && (vulnerableCode != "" || secureCode != "") {
		codeDiff = fmt.Sprintf("--- KONDISI RENTAN:\n%s\n\n+++ SOLUSI PERBAIKAN AMAN:\n%s", vulnerableCode, secureCode)
	}

	var checklist []string
	if list, ok := parsed["mitigation_checklist"].([]interface{}); ok {
		for _, item := range list {
			if str, ok := item.(string); ok {
				checklist = append(checklist, str)
			}
		}
	}

	cwe := "CWE-699: Software Development Concepts"
	if finding.CWE != nil && *finding.CWE != "" {
		cwe = *finding.CWE
	}
	owasp := "OWASP Top 10"
	if finding.OWASP != nil && *finding.OWASP != "" {
		owasp = *finding.OWASP
	}
	cvss := s.defaultCvss(finding.Severity)
	if finding.CVSS != nil {
		cvss = *finding.CVSS
	}

	var causePtr *string
	if cause != "" {
		causePtr = &cause
	}
	var vulnPtr *string
	if vulnerableCode != "" {
		vulnPtr = &vulnerableCode
	}
	var secPtr *string
	if secureCode != "" {
		secPtr = &secureCode
	}
	var verifPtr *string
	if verificationCommand != "" {
		verifPtr = &verificationCommand
	}

	return &AiRemediationResult{
		FindingID:           finding.ID,
		FindingCode:         finding.Code,
		RuleID:              finding.RuleID,
		Title:               finding.Title,
		Severity:            finding.Severity,
		Category:            category,
		Summary:             summary,
		Cause:               causePtr,
		AttackVector:        attackVector,
		BusinessImpact:      businessImpact,
		CodeDiff:            codeDiff,
		VulnerableCode:      vulnPtr,
		SecureCode:          secPtr,
		MitigationChecklist: checklist,
		VerificationCommand: verifPtr,
		Compliance: map[string]interface{}{
			"cwe":        cwe,
			"owasp":      owasp,
			"cvss_score": cvss,
		},
		OwaspGuidance: map[string]interface{}{
			"top_10_category":     owasp,
			"defense_in_depth":    defenseInDepth,
			"verification_method": verificationCommand,
		},
		AiModel:    modelName,
		AiBadge:    badgeType,
		IsLiveLLM:  true,
		Disclaimer: fmt.Sprintf("TAMENG %s: Analisis keamanan otomatis berstandar OWASP & CIS Benchmarks. Selalu tinjau arsitektur aplikasi dan jalankan testing sebelum deploy ke production.", modelName),
	}
}

func (s *AiRemediationService) generateAdvancedHeuristic(finding *models.Finding, synapses []models.NeurolinkSynapse) *AiRemediationResult {
	title := strings.ToLower(finding.Title)
	rule := ""
	if finding.RuleID != nil {
		rule = strings.ToLower(*finding.RuleID)
	}
	filePath := ""
	if finding.FilePath != nil {
		filePath = strings.ToLower(*finding.FilePath)
	}
	engine := strings.ToLower(finding.EngineKey)

	categoryName := "Security Vulnerability"
	cwe := "CWE-699: Software Development Concepts"
	owasp := "A05:2021-Security Misconfiguration"
	summary := fmt.Sprintf("Ditemukan potensi celah keamanan pada aset %s. Kerentanan ini berisiko dieksploitasi oleh pihak yang tidak berwenang untuk merusak integritas atau kerahasiaan sistem.", finding.AssetIdentifier)
	cause := "Konfigurasi atau pustaka kode belum mematuhi standar pengamanan DevSecOps yang direkomendasikan."
	attackVector := "Penyerang dapat mengirimkan payload berbahaya secara terarah melalui antarmuka atau memanfaatkan eksploit publik yang relevan."
	impact := "Dapat menyebabkan kebocoran data sensitif, penurunan reputasi institusi, dan kegagalan kepatuhan audit keamanan siber."
	vulnerableCode := "// Contoh kondisi rentan\n$input = $_GET['data'];\necho $input;"
	secureCode := "// Solusi perbaikan aman\n$input = htmlspecialchars($_GET['data'] ?? '', ENT_QUOTES, 'UTF-8');\necho $input;"
	checklist := []string{
		"Lakukan sanitasi dan validasi terhadap seluruh input eksternal.",
		"Perbarui dependensi dan pustaka usang ke versi patch terbaru.",
		"Terapkan least privilege access control pada sistem terkait.",
		"Jalankan regression testing dan scan ulang TAMENG untuk verifikasi.",
	}
	verifCommand := "curl -I https://target.domain/api"

	if len(synapses) > 0 {
		syn := synapses[0]
		if syn.RemediationPatch != nil && *syn.RemediationPatch != "" {
			secureCode = *syn.RemediationPatch
		}
		if syn.ResolutionNotes != nil && *syn.ResolutionNotes != "" {
			cause += fmt.Sprintf(" [Memori NeuroLink: %s]", *syn.ResolutionNotes)
		}
	}

	// Specialized Heuristic Detection for PHP & Framework Critical Vulnerabilities
	if strings.Contains(rule, "cve-2024-4577") || strings.Contains(title, "4577") || strings.Contains(title, "cgi argument") {
		categoryName = "Remote Code Execution via PHP CGI Argument Injection (CVE-2024-4577)"
		cwe = "CWE-78: Improper Neutralization of Special Elements used in an OS Command ('OS Command Injection')"
		owasp = "A03:2021-Injection"
		summary = "Terdeteksi konfigurasi runtime PHP yang rentan terhadap CVE-2024-4577 (PHP CGI Argument Injection). Kerentanan berperingkat CVSS 9.8 (Critical) ini memungkinkan penyerang mengeksekusi kode perintah jarak jauh (RCE) secara unauthenticated melalui karakter best-fit (0xAD soft hyphen) pada sistem Windows/FastCGI/Apache mod_cgi."
		cause = "Versi PHP yang diizinkan pada composer.json atau lingkungan server berada di bawah patch keamanan resmi (PHP < 8.1.29, < 8.2.20, < 8.3.8, atau cabang PHP 7.x/8.0.x yang sudah End-of-Life)."
		attackVector = "Penyerang mengirim request HTTP: GET /index.php?%add+allow_url_include%3d1+%add+auto_prepend_file%3dphp://input HTTP/1.1 dengan payload PHP arbitrer pada request body untuk memperoleh web shell."
		impact = "Pengambilalihan server secara penuh (Full Server Compromise), pembobolan basis data, kebocoran berkas lingkungan (.env), dan potensi infeksi ransomware."
		vulnerableCode = "// composer.json (Versi usang/EOL rentan CVE-2024-4577)\n\"require\": {\n    \"php\": \"^7.3|^8.0\"\n}"
		secureCode = "// composer.json (Perbarui ke versi aman dengan patch terbaru)\n\"require\": {\n    \"php\": \">=8.2.20\"\n}\n\n// Pastikan environment runtime server/Docker menggunakan minimal PHP 8.2.20 atau 8.3.8+"
		checklist = []string{
			"Segera mutakhirkan runtime interpreter PHP server produksi/Docker ke versi minimal PHP 8.2.20 atau PHP 8.3.8+.",
			"Perbarui batasan versi pada composer.json menjadi \">=8.2.20\" atau \"^8.3.8\".",
			"Pada server web Apache/Windows, tambahkan aturan mod_rewrite pemblokir parameter %ad jika migrasi belum selesai: RewriteCond %{QUERY_STRING} ^.*%ad.*$ [NC] RewriteRule ^(.*)$ - [F,L]",
			"Jalankan 'composer update --lock' dan uji seluruh fungsionalitas modul aplikasi.",
		}
		verifCommand = "php -v # Pastikan rilis interpreter >= 8.2.20 atau >= 8.3.8"
	} else if strings.Contains(rule, "eol") || strings.Contains(title, "end-of-life") || strings.Contains(title, "unsupported") {
		categoryName = "Unsupported & End-of-Life Runtime or Framework Version"
		cwe = "CWE-1104: Use of Unmaintained Third-Party Components"
		owasp = "A06:2021-Vulnerable and Outdated Components"
		summary = "Aplikasi mengonfigurasi atau menggunakan versi runtime atau framework yang telah resmi mencapai status End-of-Life (EOL)."
		cause = "Proyek masih menggunakan dependensi atau compiler lama yang sudah tidak lagi mendapatkan pembaruan patch keamanan dari maintainer resmi."
		attackVector = "Penyerang dapat dengan bebas mengeksploitasi celah keamanan yang telah dipublikasikan (known CVEs) karena tidak ada vendor patch resmi yang tersedia."
		impact = "Ketidakmampuan memitigasi kerentanan zero-day / one-day publik, kegagalan kepatuhan audit keamanan (ISO 27001, SOC2), dan risiko pembajakan sistem."
		vulnerableCode = "// composer.json (EOL Runtime / Framework)\n\"require\": {\n    \"php\": \"^7.3|^8.0\",\n    \"laravel/framework\": \"^8.75\"\n}"
		secureCode = "// composer.json (Didukung Aktif & Menerima Security Patch)\n\"require\": {\n    \"php\": \"^8.2.20|^8.3\",\n    \"laravel/framework\": \"^10.0|^11.0\"\n}"
		checklist = []string{
			"Susun rencana migrasi bertahap untuk beralih ke versi PHP aktif (PHP 8.2+ atau 8.3+).",
			"Upgrade framework Laravel ke versi yang didukung aktif (Laravel 10, 11, atau 12).",
			"Jalankan 'composer check-platform-reqs' untuk mengecek kesesuaian modul ekstensi.",
			"Jalankan automated test suite sebelum merilis ke server produksi.",
		}
		verifCommand = "composer check-platform-reqs"
	} else if strings.Contains(rule, "2021-3129") || strings.Contains(title, "ignition") {
		categoryName = "Laravel Ignition Debugger Remote Code Execution (CVE-2021-3129)"
		cwe = "CWE-94: Improper Control of Generation of Code ('Code Injection')"
		owasp = "A03:2021-Injection"
		summary = "Pustaka facade/ignition versi <= 2.5.1 memiliki kerentanan Remote Code Execution (RCE) kritis melalui endpoint `_ignition/execute-solution`."
		cause = "Komponen pemecah masalah (solution provider) mengeksekusi metode tanpa validasi yang memadai ketika mode debugging (APP_DEBUG=true) aktif."
		attackVector = "Penyerang mengirimkan payload Phar deserialization menggunakan filter wrapper php://filter ke endpoint `_ignition/execute-solution` untuk memicu eksekusi kode PHP arbitrer."
		impact = "Pengambilalihan server seketika (RCE) tanpa autentikasi, eksfiltrasi credential .env, dan kompromi total aplikasi."
		vulnerableCode = "// composer.json (Versi rentan)\n\"require-dev\": {\n    \"facade/ignition\": \"^2.5\"\n}\n\n// .env (Rentan di produksi)\nAPP_DEBUG=true"
		secureCode = "// composer.json (Versi aman)\n\"require-dev\": {\n    \"facade/ignition\": \"^2.5.2\"\n}\n\n// .env (Wajib nonaktif di produksi)\nAPP_DEBUG=false"
		checklist = []string{
			"Segera pastikan APP_DEBUG=false pada berkas konfigurasi .env lingkungan produksi.",
			"Perbarui facade/ignition ke minimal versi 2.5.2 atau beralih ke spatie/laravel-ignition untuk Laravel 9+.",
			"Hapus package debugging dari dependensi produksi (hanya gunakan di require-dev).",
		}
		verifCommand = "composer update facade/ignition --with-all-dependencies"
	} else if strings.Contains(rule, "cve-2024-5458") || strings.Contains(title, "5458") || strings.Contains(title, "filter_var") {
		categoryName = "PHP filter_var URL Validation Null-Byte Bypass (CVE-2024-5458)"
		cwe = "CWE-20: Improper Input Validation"
		owasp = "A04:2021-Insecure Design"
		summary = "Fungsi filter_var() dengan FILTER_VALIDATE_URL pada PHP sebelum 8.1.29, 8.2.20, dan 8.3.8 gagal memvalidasi URL yang mengandung karakter null-byte."
		cause = "Kelemahan implementasi internal C pada modul filter PHP yang menganggap string URL aman meskipun mengandung pemisah null terminator tersembunyi."
		attackVector = "Penyerang menyisipkan karakter null-byte dalam parameter URL untuk mengelabui validasi domain internal, memicu Server-Side Request Forgery (SSRF)."
		impact = "Bypass filter keamanan aplikasi, akses ke endpoint internal restricted, dan SSRF."
		vulnerableCode = "// PHP < 8.2.20\n$url = filter_var($_GET['url'], FILTER_VALIDATE_URL);\nHttp::get($url);"
		secureCode = "// PHP >= 8.2.20 atau sanitasi eksplisit\n$raw = str_replace(\"\\0\", '', $_GET['url'] ?? '');\nif (filter_var($raw, FILTER_VALIDATE_URL) && parse_url($raw, PHP_URL_SCHEME) === 'https') {\n    Http::get($raw);\n}"
		checklist = []string{
			"Perbarui PHP ke rilis >= 8.2.20 atau >= 8.3.8.",
			"Lakukan validasi skema dan hostname URL secara eksplisit sebelum melakukan pemanggilan HTTP keluar.",
		}
		verifCommand = "php -r \"echo PHP_VERSION;\""
	} else if strings.Contains(rule, "gitleaks") || strings.Contains(title, "secret") || strings.Contains(title, "token") || strings.Contains(title, "key") {
		categoryName = "Hardcoded Secret & Credential Leak"
		cwe = "CWE-798: Use of Hard-coded Credentials"
		owasp = "A07:2021-Identification and Authentication Failures"
		summary = "Ditemukan kredensial rahasia, token API, atau private key yang tertanam secara langsung (hardcoded) dalam kode sumber repositori."
		cause = "Pengembang menyimpan rahasia otentikasi di dalam source control tanpa menggunakan Environment Variable atau Secrets Manager."
		attackVector = "Aktor ancaman yang memperoleh akses ke source control dapat mengekstrak token rahasia dan membajak layanan backend terkait."
		impact = "Pengambilalihan akun infrastruktur cloud, kebocoran basis data, dan pelanggaran kerahasiaan tingkat kritikal."
		vulnerableCode = "const API_KEY = 'ghp_abc123456789xyzSecretToken';"
		secureCode = "const API_KEY = process.env.API_KEY || '';"
		checklist = []string{
			"Segera cabut (revoke) dan rotasi kredensial yang terpapar.",
			"Pindahkan seluruh secret ke berkas .env atau Kubernetes Secrets / HashiCorp Vault.",
			"Bersihkan jejak commit Git menggunakan git-filter-repo atau BFG Repo-Cleaner.",
			"Pasang pre-commit hook Gitleaks agar rahasia tidak pernah ter-commit di masa mendatang.",
		}
		verifCommand = "gitleaks detect --source . -v"
	} else if strings.Contains(engine, "trivy") || strings.Contains(engine, "osv") || strings.Contains(filePath, "composer.lock") || strings.Contains(filePath, "package.json") || strings.Contains(filePath, "yarn.lock") {
		categoryName = "Vulnerable Third-Party Component (SCA)"
		cwe = "CWE-1395: Dependency on Vulnerable Third-Party Component"
		owasp = "A06:2021-Vulnerable and Outdated Components"
		summary = fmt.Sprintf("Ditemukan dependensi pustaka pihak ketiga yang memiliki kerentanan keamanan publik (CVE/GHSA) pada berkas %s.", filepath.Base(filePath))
		cause = "Versi pustaka yang terkunci (locked) pada proyek belum diperbarui ke rilis stabil yang menyertakan patch keamanan."
		attackVector = "Penyerang dapat memanfaatkan exploit POC publik yang menargetkan fungsi rentan pada modul dependensi tersebut."
		impact = "Dapat berujung pada Remote Code Execution (RCE), denial of service, atau bypass otorisasi tergantung CVE spesifik."
		vulnerableCode = `// composer.json / package.json lama
"dependencies": {
  "vulnerable-package": "<=1.2.0"
}`
		secureCode = `// composer.json / package.json aman
"dependencies": {
  "vulnerable-package": "^1.2.5" // Versi yang sudah dipatch
}`
		checklist = []string{
			"Tinjau catatan rilis (changelog) untuk memverifikasi kompatibilitas patch.",
			"Jalankan 'composer update <package>' atau 'npm update <package>' untuk mengupgrade pustaka.",
			"Lakukan automated testing untuk memastikan tidak ada breaking changes.",
			"Kunci versi baru di composer.lock / package-lock.json dan deploy ke staging.",
		}
		verifCommand = "composer audit || npm audit"
	} else if strings.Contains(title, "x-frame-options") || strings.Contains(rule, "clickjacking") || strings.Contains(title, "clickjacking") {
		categoryName = "Missing Anti-Clickjacking Header (X-Frame-Options)"
		cwe = "CWE-1021: Improper Restriction of Rendered UI Layers or Frames"
		owasp = "A05:2021-Security Misconfiguration"
		summary = "Web server atau aplikasi tidak mengirimkan header HTTP X-Frame-Options, memungkinkan halaman dimuat di dalam iframe situs asing."
		cause = "Konfigurasi server HTTP (Nginx/Apache) atau middleware keamanan aplikasi belum mengaktifkan proteksi framing."
		attackVector = "Penyerang menyematkan halaman web korban ke dalam iframe transparan pada situs jebakan untuk mencuri klik pengguna otentikasi."
		impact = "Tindakan tanpa izin (unauthorized actions) yang dilakukan atas nama pengguna tanpa disadari (Clickjacking)."
		vulnerableCode = "# Nginx konfigurasi lama (tanpa header proteksi iframe)"
		secureCode = "# Nginx konfigurasi aman:\nadd_header X-Frame-Options \"DENY\" always;\nadd_header Content-Security-Policy \"frame-ancestors 'none';\" always;"
		checklist = []string{
			"Tambahkan header X-Frame-Options 'DENY' atau 'SAMEORIGIN' pada konfigurasi Nginx/Apache.",
			"Gunakan juga direktif CSP frame-ancestors untuk browser modern.",
			"Reload konfigurasi web server (nginx -s reload).",
		}
		verifCommand = "curl -I -s https://target.domain | grep -i X-Frame-Options"
	} else if strings.Contains(title, "content security policy") || strings.Contains(rule, "csp") {
		categoryName = "Content Security Policy (CSP) Not Configured"
		cwe = "CWE-358: Improperly Implemented Security Check for Standard"
		owasp = "A05:2021-Security Misconfiguration"
		summary = "Aplikasi belum mengonfigurasi header Content-Security-Policy (CSP) yang membatasi sumber eksekusi skrip dan pemuatan aset."
		cause = "Header CSP belum didefinisikan pada konfigurasi server web atau response middleware."
		attackVector = "Penyerang dapat menyuntikkan skrip pihak ketiga (XSS) yang akan langsung dieksekusi oleh browser klien tanpa rintangan kebijakan sumber."
		impact = "Pencurian sesi (cookie hijacking), defacement tampilan, dan eksfiltrasi data pengguna."
		vulnerableCode = "# Header CSP absen"
		secureCode = "add_header Content-Security-Policy \"default-src 'self'; script-src 'self'; object-src 'none';\" always;"
		checklist = []string{
			"Tentukan whitelist domain asal skrip, font, gambar, dan koneksi API.",
			"Terapkan Content-Security-Policy secara bertahap menggunakan Content-Security-Policy-Report-Only.",
			"Setelah stabil, aktifkan header Content-Security-Policy secara penuh.",
		}
		verifCommand = "curl -I -s https://target.domain | grep -i Content-Security-Policy"
	}

	codeDiff := fmt.Sprintf("--- SEBELUM (Kondisi Rentan):\n%s\n\n+++ SESUDAH (Solusi Perbaikan Direkomendasikan):\n%s", vulnerableCode, secureCode)

	cvss := s.defaultCvss(finding.Severity)
	if finding.CVSS != nil {
		cvss = *finding.CVSS
	}

	return &AiRemediationResult{
		FindingID:           finding.ID,
		FindingCode:         finding.Code,
		RuleID:              finding.RuleID,
		Title:               finding.Title,
		Severity:            finding.Severity,
		Category:            categoryName,
		Summary:             summary,
		Cause:               &cause,
		AttackVector:        attackVector,
		BusinessImpact:      impact,
		CodeDiff:            codeDiff,
		VulnerableCode:      &vulnerableCode,
		SecureCode:          &secureCode,
		MitigationChecklist: checklist,
		VerificationCommand: &verifCommand,
		Compliance: map[string]interface{}{
			"cwe":        cwe,
			"owasp":      owasp,
			"cvss_score": cvss,
		},
		OwaspGuidance: map[string]interface{}{
			"top_10_category":     owasp,
			"defense_in_depth":    "Terapkan validasi input ketat, otorisasi berlapis (RBAC), dan enkripsi transmisi data.",
			"verification_method": verifCommand,
		},
		AiModel:    "TAMENG Cyber Intelligence Engine v3.0 (Expert Heuristic)",
		AiBadge:    "tameng-ai",
		IsLiveLLM:  false,
		Disclaimer: "TAMENG Cyber Intelligence: Analisis keamanan otomatis berstandar OWASP & CIS Benchmarks. Selalu tinjau arsitektur aplikasi dan jalankan testing sebelum deploy ke production.",
	}
}

func (s *AiRemediationService) defaultCvss(severity string) float64 {
	switch strings.ToLower(severity) {
	case "critical":
		return 9.8
	case "high":
		return 8.2
	case "medium":
		return 5.5
	case "low":
		return 3.1
	default:
		return 0.0
	}
}
