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

	finding := models.Finding{
		Code:                  findingCode,
		ProjectID:             scanJob.ProjectID,
		ScanJobID:             scanJob.ID,
		ScanRunID:             &scanRun.ID,
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

