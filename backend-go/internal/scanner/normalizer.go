package scanner

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"tameng-backend-go/internal/models"

	"gorm.io/gorm"
)

type NormalizedFinding struct {
	RuleID          string
	Title           string
	Severity        string
	Confidence      float64
	AssetType       string
	AssetIdentifier string
	FilePath        string
	LineStart       uint32
	LineEnd         uint32
	Endpoint        string
	HTTPMethod      string
	CWE             string
	OWASP           string
	CVE             string
	CVSS            float64
	Evidence        string
}

// IngestFinding persists a normalized finding into the database with deduplication.
func IngestFinding(db *gorm.DB, scanJob *models.ScanJob, scanRun *models.ScanRun, engineKey string, f NormalizedFinding) error {
	assetType := f.AssetType
	if assetType == "" {
		if scanJob.RepositoryID != nil {
			assetType = "repository"
		} else {
			assetType = "target"
		}
	}

	assetIdentifier := f.AssetIdentifier
	if assetIdentifier == "" {
		if scanJob.Repository != nil {
			assetIdentifier = scanJob.Repository.Name
		} else if scanJob.Target != nil {
			if scanJob.Target.Hostname != nil {
				assetIdentifier = *scanJob.Target.Hostname
			} else {
				assetIdentifier = scanJob.Target.Name
			}
		} else {
			assetIdentifier = "asset"
		}
	}

	// Compute Dedup Key
	dedupRaw := fmt.Sprintf("%d|%s|%s|%s|%s|%s|%s|%d",
		scanJob.ID,
		engineKey,
		f.RuleID,
		assetType,
		assetIdentifier,
		f.FilePath,
		f.Endpoint,
		f.LineStart,
	)
	hasher := sha256.New()
	hasher.Write([]byte(dedupRaw))
	dedupKey := hex.EncodeToString(hasher.Sum(nil))

	sev := strings.ToLower(f.Severity)
	if sev == "" {
		sev = "medium"
	}

	conf := f.Confidence
	if conf == 0 {
		conf = 0.85
	}

	now := time.Now()
	findingCode := fmt.Sprintf("FIND-%s-%s", now.Format("20060102150405"), strings.ToUpper(dedupKey[:6]))

	var existing models.Finding
	if err := db.Where("scan_job_id = ? AND dedup_key = ?", scanJob.ID, dedupKey).First(&existing).Error; err == nil {
		// Update existing finding
		existing.UpdatedAt = now
		return db.Save(&existing).Error
	}

	var filePathPtr *string
	if f.FilePath != "" {
		filePathPtr = &f.FilePath
	}
	var lineStartPtr *uint32
	if f.LineStart > 0 {
		lineStartPtr = &f.LineStart
	}
	var lineEndPtr *uint32
	if f.LineEnd > 0 {
		lineEndPtr = &f.LineEnd
	}
	var endpointPtr *string
	if f.Endpoint != "" {
		endpointPtr = &f.Endpoint
	}
	var httpMethodPtr *string
	if f.HTTPMethod != "" {
		httpMethodPtr = &f.HTTPMethod
	}
	var cwePtr *string
	if f.CWE != "" {
		cwePtr = &f.CWE
	}
	var owaspPtr *string
	if f.OWASP != "" {
		owaspPtr = &f.OWASP
	}
	var cvePtr *string
	if f.CVE != "" {
		cvePtr = &f.CVE
	}
	var cvssPtr *float64
	if f.CVSS > 0 {
		cvssPtr = &f.CVSS
	}
	var ruleIDPtr *string
	if f.RuleID != "" {
		ruleIDPtr = &f.RuleID
	}

	metaMap := map[string]interface{}{
		"source":            engineKey + "_runner",
		"scanner_execution": true,
		"normalized_at":     now.Format(time.RFC3339),
	}
	metaBytes, _ := json.Marshal(metaMap)
	metaStr := string(metaBytes)

	var scanRunID *uint64
	if scanRun != nil {
		scanRunID = &scanRun.ID
	}

	finding := models.Finding{
		Code:                  findingCode,
		ProjectID:             scanJob.ProjectID,
		ScanJobID:             scanJob.ID,
		ScanRunID:             scanRunID,
		EngineKey:             engineKey,
		RuleID:                ruleIDPtr,
		Title:                 f.Title,
		Severity:              sev,
		Confidence:            &conf,
		AssetType:             assetType,
		AssetIdentifier:       assetIdentifier,
		FilePath:              filePathPtr,
		LineStart:             lineStartPtr,
		LineEnd:               lineEndPtr,
		HTTPMethod:            httpMethodPtr,
		Endpoint:              endpointPtr,
		CWE:                   cwePtr,
		OWASP:                 owaspPtr,
		CVE:                   cvePtr,
		CVSS:                  cvssPtr,
		Status:                "open",
		DedupKey:              dedupKey,
		NormalizationMetadata: &metaStr,
		CreatedAt:             now,
		UpdatedAt:             now,
	}

	if err := db.Create(&finding).Error; err != nil {
		return err
	}

	// Create FindingEvidence
	evSummaryMap := map[string]interface{}{
		"engine_key": engineKey,
		"raw_sample": f.Evidence,
	}
	evSummaryBytes, _ := json.Marshal(evSummaryMap)
	evSummaryStr := string(evSummaryBytes)

	evidence := models.FindingEvidence{
		FindingID:       finding.ID,
		EngineKey:       engineKey,
		Confidence:      conf,
		EvidenceSummary: &evSummaryStr,
		CreatedAt:       now,
		UpdatedAt:       now,
	}

	return db.Create(&evidence).Error
}

// ParseSemgrepOutput parses Semgrep JSON format.
func ParseSemgrepOutput(data []byte) []NormalizedFinding {
	var parsed struct {
		Results []struct {
			CheckID string `json:"check_id"`
			Path    string `json:"path"`
			Start   struct {
				Line int `json:"line"`
			} `json:"start"`
			End struct {
				Line int `json:"line"`
			} `json:"end"`
			Extra struct {
				Message  string `json:"message"`
				Severity string `json:"severity"`
				Lines    string `json:"lines"`
				Metadata struct {
					CWE   interface{} `json:"cwe"`
					OWASP interface{} `json:"owasp"`
				} `json:"metadata"`
			} `json:"extra"`
		} `json:"results"`
	}

	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, r := range parsed.Results {
		sev := "medium"
		switch strings.ToUpper(r.Extra.Severity) {
		case "ERROR":
			sev = "high"
		case "WARNING":
			sev = "medium"
		case "INFO":
			sev = "low"
		}

		cweStr := ""
		if cweSlice, ok := r.Extra.Metadata.CWE.([]interface{}); ok && len(cweSlice) > 0 {
			cweStr = fmt.Sprintf("%v", cweSlice[0])
		} else if s, ok := r.Extra.Metadata.CWE.(string); ok {
			cweStr = s
		}

		owaspStr := ""
		if owaspSlice, ok := r.Extra.Metadata.OWASP.([]interface{}); ok && len(owaspSlice) > 0 {
			owaspStr = fmt.Sprintf("%v", owaspSlice[0])
		} else if s, ok := r.Extra.Metadata.OWASP.(string); ok {
			owaspStr = s
		}

		title := r.Extra.Message
		if title == "" {
			title = r.CheckID
		}
		if len(title) > 250 {
			title = title[:247] + "..."
		}

		results = append(results, NormalizedFinding{
			RuleID:     r.CheckID,
			Title:      title,
			Severity:   sev,
			Confidence: 0.90,
			FilePath:   r.Path,
			LineStart:  uint32(r.Start.Line),
			LineEnd:    uint32(r.End.Line),
			CWE:        cweStr,
			OWASP:      owaspStr,
			Evidence:   r.Extra.Lines,
		})
	}

	return results
}

// ParseGitleaksOutput parses Gitleaks JSON format.
func ParseGitleaksOutput(data []byte) []NormalizedFinding {
	var leaks []struct {
		Description string `json:"Description"`
		RuleID      string `json:"RuleID"`
		File        string `json:"File"`
		StartLine   int    `json:"StartLine"`
		EndLine     int    `json:"EndLine"`
		Secret      string `json:"Secret"`
	}

	if err := json.Unmarshal(data, &leaks); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, l := range leaks {
		title := l.Description
		if title == "" {
			title = fmt.Sprintf("Hardcoded Secret Exposed (%s)", l.RuleID)
		}

		results = append(results, NormalizedFinding{
			RuleID:     l.RuleID,
			Title:      title,
			Severity:   "critical",
			Confidence: 0.95,
			FilePath:   l.File,
			LineStart:  uint32(l.StartLine),
			LineEnd:    uint32(l.EndLine),
			CWE:        "CWE-798: Use of Hard-coded Credentials",
			OWASP:      "A07:2021-Identification and Authentication Failures",
			Evidence:   "[REDACTED SECRET DETECTED]",
		})
	}

	return results
}

// ParseTrivyOutput parses Trivy JSON format.
func ParseTrivyOutput(data []byte) []NormalizedFinding {
	var parsed struct {
		Results []struct {
			Target          string `json:"Target"`
			Vulnerabilities []struct {
				VulnerabilityID  string      `json:"VulnerabilityID"`
				PkgName          string      `json:"PkgName"`
				InstalledVersion string      `json:"InstalledVersion"`
				FixedVersion     string      `json:"FixedVersion"`
				Title            string      `json:"Title"`
				Description      string      `json:"Description"`
				Severity         string      `json:"Severity"`
				CweIDs           []string    `json:"CweIDs"`
				CVSS             interface{} `json:"CVSS"`
			} `json:"Vulnerabilities"`
			Misconfigurations []struct {
				ID          string `json:"ID"`
				Title       string `json:"Title"`
				Description string `json:"Description"`
				Message     string `json:"Message"`
				Severity    string `json:"Severity"`
			} `json:"Misconfigurations"`
		} `json:"Results"`
	}

	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, res := range parsed.Results {
		for _, v := range res.Vulnerabilities {
			title := v.Title
			if title == "" {
				title = fmt.Sprintf("%s in %s %s", v.VulnerabilityID, v.PkgName, v.InstalledVersion)
			}
			cweStr := ""
			if len(v.CweIDs) > 0 {
				cweStr = v.CweIDs[0]
			}

			results = append(results, NormalizedFinding{
				RuleID:     v.VulnerabilityID,
				Title:      title,
				Severity:   strings.ToLower(v.Severity),
				Confidence: 0.90,
				FilePath:   res.Target,
				CVE:        v.VulnerabilityID,
				CWE:        cweStr,
				Evidence:   fmt.Sprintf("Package: %s, Installed: %s, Fixed: %s", v.PkgName, v.InstalledVersion, v.FixedVersion),
			})
		}

		for _, m := range res.Misconfigurations {
			results = append(results, NormalizedFinding{
				RuleID:     m.ID,
				Title:      m.Title,
				Severity:   strings.ToLower(m.Severity),
				Confidence: 0.85,
				FilePath:   res.Target,
				Evidence:   m.Message,
			})
		}
	}

	return results
}

// ParseNucleiOutput parses Nuclei JSONL format.
func ParseNucleiOutput(data []byte) []NormalizedFinding {
	var results []NormalizedFinding
	scanner := bufio.NewScanner(bytes.NewReader(data))

	for scanner.Scan() {
		line := scanner.Bytes()
		if len(bytes.TrimSpace(line)) == 0 {
			continue
		}

		var item struct {
			TemplateID string `json:"template-id"`
			Info       struct {
				Name           string `json:"name"`
				Severity       string `json:"severity"`
				Description    string `json:"description"`
				Classification struct {
					CveID     interface{} `json:"cve-id"`
					CweID     interface{} `json:"cwe-id"`
					CvssScore float64     `json:"cvss-score"`
				} `json:"classification"`
			} `json:"info"`
			MatchedAt string `json:"matched-at"`
			Type      string `json:"type"`
		}

		if err := json.Unmarshal(line, &item); err != nil {
			continue
		}

		cveStr := ""
		if cveSlice, ok := item.Info.Classification.CveID.([]interface{}); ok && len(cveSlice) > 0 {
			cveStr = fmt.Sprintf("%v", cveSlice[0])
		} else if s, ok := item.Info.Classification.CveID.(string); ok {
			cveStr = s
		}

		cweStr := ""
		if cweSlice, ok := item.Info.Classification.CweID.([]interface{}); ok && len(cweSlice) > 0 {
			cweStr = fmt.Sprintf("%v", cweSlice[0])
		} else if s, ok := item.Info.Classification.CweID.(string); ok {
			cweStr = s
		}

		title := item.Info.Name
		if title == "" {
			title = item.TemplateID
		}

		results = append(results, NormalizedFinding{
			RuleID:     item.TemplateID,
			Title:      title,
			Severity:   strings.ToLower(item.Info.Severity),
			Confidence: 0.90,
			Endpoint:   item.MatchedAt,
			CVE:        cveStr,
			CWE:        cweStr,
			CVSS:       item.Info.Classification.CvssScore,
			Evidence:   item.Info.Description,
		})
	}

	return results
}

// ParseOsvOutput parses Google OSV-Scanner JSON format.
func ParseOsvOutput(data []byte) []NormalizedFinding {
	var parsed struct {
		Results []struct {
			Source struct {
				Path string `json:"path"`
			} `json:"source"`
			PackageSource struct {
				Path string `json:"path"`
			} `json:"packageSource"`
			Packages []struct {
				Package struct {
					Name      string `json:"name"`
					Version   string `json:"version"`
					Ecosystem string `json:"ecosystem"`
				} `json:"package"`
				Vulnerabilities []struct {
					ID               string   `json:"id"`
					Summary          string   `json:"summary"`
					Details          string   `json:"details"`
					Aliases          []string `json:"aliases"`
					DatabaseSpecific struct {
						Severity string   `json:"severity"`
						CweIDs   []string `json:"cwe_ids"`
					} `json:"database_specific"`
				} `json:"vulnerabilities"`
			} `json:"packages"`
		} `json:"results"`
	}

	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, res := range parsed.Results {
		filePath := res.Source.Path
		if filePath == "" {
			filePath = res.PackageSource.Path
		}
		filePath = strings.TrimPrefix(filePath, "/src/")

		for _, pkg := range res.Packages {
			for _, v := range pkg.Vulnerabilities {
				sev := "medium"
				if v.DatabaseSpecific.Severity != "" {
					sev = strings.ToLower(v.DatabaseSpecific.Severity)
				}
				if sev == "moderate" {
					sev = "medium"
				}

				cveStr := v.ID
				for _, alias := range v.Aliases {
					if strings.HasPrefix(alias, "CVE-") {
						cveStr = alias
						break
					}
				}

				cweStr := ""
				if len(v.DatabaseSpecific.CweIDs) > 0 {
					cweStr = v.DatabaseSpecific.CweIDs[0]
				}

				title := v.Summary
				if title == "" {
					title = fmt.Sprintf("%s in %s (%s)", v.ID, pkg.Package.Name, pkg.Package.Version)
				}
				if len(title) > 250 {
					title = title[:247] + "..."
				}

				evidence := v.Details
				if evidence == "" {
					evidence = fmt.Sprintf("Package: %s, Ecosystem: %s, Installed Version: %s", pkg.Package.Name, pkg.Package.Ecosystem, pkg.Package.Version)
				}
				if len(evidence) > 1000 {
					evidence = evidence[:1000] + "..."
				}

				results = append(results, NormalizedFinding{
					RuleID:     v.ID,
					Title:      title,
					Severity:   sev,
					Confidence: 0.95,
					FilePath:   filePath,
					CVE:        cveStr,
					CWE:        cweStr,
					OWASP:      "A06:2021-Vulnerable and Outdated Components",
					Evidence:   evidence,
				})
			}
		}
	}
	return results
}

// ParseCheckovOutput parses Bridgecrew Checkov JSON format (handles both array and object root).
func ParseCheckovOutput(data []byte) []NormalizedFinding {
	type CheckItem struct {
		CheckID       string        `json:"check_id"`
		CheckName     string        `json:"check_name"`
		FilePath      string        `json:"file_path"`
		FileLineRange []int         `json:"file_line_range"`
		Severity      string        `json:"severity"`
		Guideline     string        `json:"guideline"`
		CodeBlock     []interface{} `json:"code_block"`
	}

	var checks []CheckItem

	// Try unmarshaling as array of framework results
	var arrayRoot []struct {
		Results struct {
			FailedChecks []CheckItem `json:"failed_checks"`
		} `json:"results"`
	}
	if err := json.Unmarshal(data, &arrayRoot); err == nil && len(arrayRoot) > 0 {
		for _, frame := range arrayRoot {
			checks = append(checks, frame.Results.FailedChecks...)
		}
	} else {
		// Try unmarshaling as single object root
		var objRoot struct {
			Results struct {
				FailedChecks []CheckItem `json:"failed_checks"`
			} `json:"results"`
		}
		if err := json.Unmarshal(data, &objRoot); err == nil {
			checks = append(checks, objRoot.Results.FailedChecks...)
		}
	}

	var results []NormalizedFinding
	for _, c := range checks {
		sev := strings.ToLower(c.Severity)
		if sev == "" {
			sev = "medium"
		}
		var lineStart, lineEnd uint32
		if len(c.FileLineRange) >= 2 {
			lineStart = uint32(c.FileLineRange[0])
			lineEnd = uint32(c.FileLineRange[1])
		} else if len(c.FileLineRange) == 1 {
			lineStart = uint32(c.FileLineRange[0])
			lineEnd = lineStart
		}

		filePath := strings.TrimPrefix(c.FilePath, "/src/")

		evidence := c.Guideline
		if evidence == "" {
			evidence = fmt.Sprintf("Failed check: %s (%s)", c.CheckName, c.CheckID)
		}

		results = append(results, NormalizedFinding{
			RuleID:     c.CheckID,
			Title:      c.CheckName,
			Severity:   sev,
			Confidence: 0.90,
			FilePath:   filePath,
			LineStart:  lineStart,
			LineEnd:    lineEnd,
			CWE:        "CWE-1188: Insecure Default Initialization of Resource",
			OWASP:      "A05:2021-Security Misconfiguration",
			Evidence:   evidence,
		})
	}
	return results
}

// ParseHadolintOutput parses Hadolint Dockerfile linter JSON.
func ParseHadolintOutput(data []byte) []NormalizedFinding {
	var items []struct {
		Code    string `json:"code"`
		Level   string `json:"level"`
		Message string `json:"message"`
		Line    int    `json:"line"`
		File    string `json:"file"`
	}
	if err := json.Unmarshal(data, &items); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, item := range items {
		sev := "medium"
		switch strings.ToLower(item.Level) {
		case "error":
			sev = "high"
		case "warning":
			sev = "medium"
		case "info", "style":
			sev = "low"
		}

		filePath := strings.TrimPrefix(item.File, "/src/")
		if filePath == "" {
			filePath = "Dockerfile"
		}

		results = append(results, NormalizedFinding{
			RuleID:     item.Code,
			Title:      item.Message,
			Severity:   sev,
			Confidence: 0.92,
			FilePath:   filePath,
			LineStart:  uint32(item.Line),
			LineEnd:    uint32(item.Line),
			CWE:        "CWE-250: Execution with Unnecessary Privileges",
			OWASP:      "A05:2021-Security Misconfiguration",
			Evidence:   fmt.Sprintf("Dockerfile Line %d: %s (%s)", item.Line, item.Message, item.Code),
		})
	}
	return results
}

// ParseGrypeOutput parses Anchore Grype JSON format.
func ParseGrypeOutput(data []byte) []NormalizedFinding {
	var parsed struct {
		Matches []struct {
			Vulnerability struct {
				ID          string `json:"id"`
				Severity    string `json:"severity"`
				Description string `json:"description"`
				Fix         struct {
					Versions []string `json:"versions"`
				} `json:"fix"`
			} `json:"vulnerability"`
			Artifact struct {
				Name      string `json:"name"`
				Version   string `json:"version"`
				Locations []struct {
					Path string `json:"path"`
				} `json:"locations"`
			} `json:"artifact"`
		} `json:"matches"`
	}

	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, m := range parsed.Matches {
		sev := strings.ToLower(m.Vulnerability.Severity)
		if sev == "" {
			sev = "medium"
		}

		filePath := ""
		if len(m.Artifact.Locations) > 0 {
			filePath = strings.TrimPrefix(m.Artifact.Locations[0].Path, "/src/")
		}

		title := fmt.Sprintf("%s in %s %s", m.Vulnerability.ID, m.Artifact.Name, m.Artifact.Version)
		if m.Vulnerability.Description != "" {
			title = m.Vulnerability.Description
			if len(title) > 250 {
				title = title[:247] + "..."
			}
		}

		evidence := fmt.Sprintf("Package: %s, Version: %s", m.Artifact.Name, m.Artifact.Version)
		if len(m.Vulnerability.Fix.Versions) > 0 {
			evidence += fmt.Sprintf(", Fix Available: %s", strings.Join(m.Vulnerability.Fix.Versions, ", "))
		}

		results = append(results, NormalizedFinding{
			RuleID:     m.Vulnerability.ID,
			Title:      title,
			Severity:   sev,
			Confidence: 0.90,
			FilePath:   filePath,
			CVE:        m.Vulnerability.ID,
			OWASP:      "A06:2021-Vulnerable and Outdated Components",
			Evidence:   evidence,
		})
	}
	return results
}

// ParseNiktoOutput parses Nikto JSON format.
func ParseNiktoOutput(data []byte) []NormalizedFinding {
	var parsed struct {
		Host            string `json:"host"`
		Port            string `json:"port"`
		Vulnerabilities []struct {
			ID     string `json:"id"`
			OSVDB  string `json:"OSVDB"`
			Msg    string `json:"msg"`
			Method string `json:"method"`
			URL    string `json:"url"`
		} `json:"vulnerabilities"`
	}

	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, v := range parsed.Vulnerabilities {
		sev := "medium"
		msgLower := strings.ToLower(v.Msg)
		if strings.Contains(msgLower, "critical") || strings.Contains(msgLower, "remote code") || strings.Contains(msgLower, "sql") {
			sev = "critical"
		} else if strings.Contains(msgLower, "vulnerability") || strings.Contains(msgLower, "traversal") {
			sev = "high"
		} else if strings.Contains(msgLower, "header") || strings.Contains(msgLower, "options") {
			sev = "low"
		}

		ruleID := v.ID
		if ruleID == "" {
			ruleID = v.OSVDB
		}
		if ruleID == "" {
			ruleID = "NIKTO-VULN"
		}

		results = append(results, NormalizedFinding{
			RuleID:     ruleID,
			Title:      v.Msg,
			Severity:   sev,
			Confidence: 0.85,
			Endpoint:   v.URL,
			HTTPMethod: v.Method,
			CWE:        "CWE-16: Configuration",
			OWASP:      "A05:2021-Security Misconfiguration",
			Evidence:   v.Msg,
		})
	}
	return results
}

// ParseTestsslOutput parses testssl.sh JSON format.
func ParseTestsslOutput(data []byte) []NormalizedFinding {
	var items []struct {
		ID       string `json:"id"`
		Severity string `json:"severity"`
		Finding  string `json:"finding"`
		CWE      string `json:"cwe"`
	}
	if err := json.Unmarshal(data, &items); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, item := range items {
		sev := strings.ToLower(item.Severity)
		if sev == "ok" || sev == "info" || sev == "" {
			continue
		}
		if sev == "warn" {
			sev = "medium"
		} else if sev == "fatal" {
			sev = "critical"
		}

		cweStr := item.CWE
		if cweStr == "" {
			cweStr = "CWE-326: Inadequate Encryption Strength"
		}

		results = append(results, NormalizedFinding{
			RuleID:     "TLS-" + item.ID,
			Title:      item.Finding,
			Severity:   sev,
			Confidence: 0.95,
			CWE:        cweStr,
			OWASP:      "A02:2021-Cryptographic Failures",
			Evidence:   item.Finding,
		})
	}
	return results
}

// ParseZapOutput parses OWASP ZAP JSON format.
func ParseZapOutput(data []byte) []NormalizedFinding {
	var parsed struct {
		Site []struct {
			Alerts []struct {
				PluginID string `json:"pluginId"`
				Alert    string `json:"alert"`
				RiskDesc string `json:"riskdesc"`
				Desc     string `json:"desc"`
				CWEID    string `json:"cweid"`
				Instances []struct {
					URI    string `json:"uri"`
					Method string `json:"method"`
				} `json:"instances"`
			} `json:"alerts"`
		} `json:"site"`
	}

	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil
	}

	var results []NormalizedFinding
	for _, site := range parsed.Site {
		for _, a := range site.Alerts {
			sev := "medium"
			riskLower := strings.ToLower(a.RiskDesc)
			if strings.HasPrefix(riskLower, "high") {
				sev = "high"
			} else if strings.HasPrefix(riskLower, "critical") {
				sev = "critical"
			} else if strings.HasPrefix(riskLower, "low") {
				sev = "low"
			} else if strings.HasPrefix(riskLower, "informational") {
				sev = "informational"
			}

			cweStr := ""
			if a.CWEID != "" && a.CWEID != "0" {
				cweStr = "CWE-" + a.CWEID
			}

			endpoint := ""
			method := ""
			if len(a.Instances) > 0 {
				endpoint = a.Instances[0].URI
				method = a.Instances[0].Method
			}

			results = append(results, NormalizedFinding{
				RuleID:     "ZAP-" + a.PluginID,
				Title:      a.Alert,
				Severity:   sev,
				Confidence: 0.90,
				Endpoint:   endpoint,
				HTTPMethod: method,
				CWE:        cweStr,
				OWASP:      "A05:2021-Security Misconfiguration",
				Evidence:   a.Desc,
			})
		}
	}
	return results
}

