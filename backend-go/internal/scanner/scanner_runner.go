package scanner

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"tameng-backend-go/internal/models"

	"gorm.io/gorm"
)

type Runner struct {
	db *gorm.DB
}

func NewRunner(db *gorm.DB) *Runner {
	return &Runner{db: db}
}

// StartWorker initiates the background polling loop for queued scan jobs.
func (r *Runner) StartWorker(ctx context.Context) {
	log.Println("[SCANNER WORKER] Native Golang Docker Scanner Runner initialized and active.")
	ticker := time.NewTicker(3 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			log.Println("[SCANNER WORKER] Stopping scanner worker.")
			return
		case <-ticker.C:
			r.pollAndProcess()
		}
	}
}

func (r *Runner) pollAndProcess() {
	var queuedJobs []models.ScanJob
	if err := r.db.Where("status = ?", "queued").Order("id ASC").Limit(2).Find(&queuedJobs).Error; err != nil {
		return
	}

	for _, job := range queuedJobs {
		r.processJob(job.ID)
	}
}

func (r *Runner) processJob(jobID uint64) {
	var job models.ScanJob
	if err := r.db.Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		First(&job, jobID).Error; err != nil {
		return
	}

	// Claim job
	now := time.Now()
	job.Status = "running"
	job.StartedAt = &now
	job.Progress = 5
	r.db.Save(&job)

	log.Printf("[SCANNER] Processing ScanJob #%d (%s) for Project %s", job.ID, job.Code, job.Project.Name)

	// 1. Resolve Target Workspace
	workspacePath := ""
	if job.Repository != nil {
		// Check metadata local_path
		if job.Repository.Metadata != nil && *job.Repository.Metadata != "" {
			var meta map[string]interface{}
			_ = json.Unmarshal([]byte(*job.Repository.Metadata), &meta)
			if lp, ok := meta["local_path"].(string); ok && lp != "" {
				workspacePath = lp
			}
		}

		// Fallback to convention /data/repository-workspaces/<id>-<name>
		if workspacePath == "" || !dirExists(workspacePath) {
			safeName := sanitizeDir(job.Repository.Name)
			candidate := fmt.Sprintf("/data/repository-workspaces/%d-%s", job.Repository.ID, safeName)
			if dirExists(candidate) {
				workspacePath = candidate
			} else {
				// Search for any matching folder in /data/repository-workspaces
				matches, _ := filepath.Glob(fmt.Sprintf("/data/repository-workspaces/*%s*", safeName))
				if len(matches) > 0 {
					workspacePath = matches[0]
				} else {
					// Auto clone repository if URL is valid git
					workspacePath = candidate
					_ = os.MkdirAll(workspacePath, 0755)
					cloneCmd := exec.Command("git", "clone", "--depth", "1", job.Repository.URL, workspacePath)
					_ = cloneCmd.Run()
				}
			}
		}
	} else if job.Target != nil {
		if job.Target.Metadata != nil && *job.Target.Metadata != "" {
			var meta map[string]interface{}
			_ = json.Unmarshal([]byte(*job.Target.Metadata), &meta)
			if lp, ok := meta["local_path"].(string); ok && lp != "" {
				workspacePath = lp
			}
		}
	}

	// 2. Parse Engine Plan
	var planItems []struct {
		EngineKey    string `json:"engine_key"`
		Status       string `json:"status"`
		FindingCount int    `json:"finding_count"`
	}
	if job.EnginePlan != "" {
		_ = json.Unmarshal([]byte(job.EnginePlan), &planItems)
	}

	if len(planItems) == 0 {
		// Default to source_code_scan or web_safe_scan
		if job.RepositoryID != nil {
			planItems = append(planItems,
				struct {
					EngineKey    string `json:"engine_key"`
					Status       string `json:"status"`
					FindingCount int    `json:"finding_count"`
				}{EngineKey: "semgrep", Status: "queued"},
				struct {
					EngineKey    string `json:"engine_key"`
					Status       string `json:"status"`
					FindingCount int    `json:"finding_count"`
				}{EngineKey: "gitleaks", Status: "queued"},
				struct {
					EngineKey    string `json:"engine_key"`
					Status       string `json:"status"`
					FindingCount int    `json:"finding_count"`
				}{EngineKey: "trivy", Status: "queued"},
			)
		} else {
			planItems = append(planItems,
				struct {
					EngineKey    string `json:"engine_key"`
					Status       string `json:"status"`
					FindingCount int    `json:"finding_count"`
				}{EngineKey: "nuclei", Status: "queued"},
				struct {
					EngineKey    string `json:"engine_key"`
					Status       string `json:"status"`
					FindingCount int    `json:"finding_count"`
				}{EngineKey: "nikto", Status: "queued"},
			)
		}
	}

	completedEngines := 0
	failedEngines := 0
	totalEngines := len(planItems)

	// 3. Execute Each Engine in the Plan
	for i, item := range planItems {
		engineKey := strings.ToLower(item.EngineKey)
		log.Printf("[SCANNER] Job #%d: Executing engine [%s] (%d/%d)...", job.ID, engineKey, i+1, totalEngines)

		runStart := time.Now()
		defaultCmdSpec := "{}"
		defaultMetrics := "{}"
		scanRun := models.ScanRun{
			ScanJobID:      job.ID,
			EngineKey:      engineKey,
			Status:         "running",
			CommandSpec:    &defaultCmdSpec,
			RuntimeMetrics: &defaultMetrics,
			StartedAt:      &runStart,
			CreatedAt:      runStart,
			UpdatedAt:      runStart,
		}
		r.db.Create(&scanRun)

		outDir := fmt.Sprintf("/tmp/tameng-scan/%d/%s", job.ID, engineKey)
		_ = os.MkdirAll(outDir, 0777)

		exitCode, outputData, runErr := r.executeDockerEngine(engineKey, workspacePath, outDir, &job)

		runEnd := time.Now()
		scanRun.FinishedAt = &runEnd
		scanRun.ExitCode = &exitCode
		scanRun.UpdatedAt = runEnd

		findingCount := 0
		if runErr == nil || len(outputData) > 0 {
			scanRun.Status = "completed"
			completedEngines++

			// Ingest Findings
			findings := r.parseFindings(engineKey, outputData)
			findingCount = len(findings)
			for _, f := range findings {
				_ = IngestFinding(r.db, &job, &scanRun, engineKey, f)
			}
		} else {
			scanRun.Status = "failed"
			failReason := runErr.Error()
			scanRun.FailureReason = &failReason
			failedEngines++
		}
		r.db.Save(&scanRun)

		// Update plan item
		planItems[i].Status = scanRun.Status
		planItems[i].FindingCount = findingCount

		// Update Job Progress
		prog := uint8(((i + 1) * 100) / totalEngines)
		if prog > 95 && i < totalEngines-1 {
			prog = 95
		}
		job.Progress = prog
		updatedPlanBytes, _ := json.Marshal(planItems)
		job.EnginePlan = string(updatedPlanBytes)
		r.db.Save(&job)
	}

	// 4. Finalize ScanJob
	finishTime := time.Now()
	finalStatus := "completed"
	if failedEngines == totalEngines && totalEngines > 0 {
		finalStatus = "failed"
	}
	job.Status = finalStatus
	job.Progress = 100
	job.FinishedAt = &finishTime
	job.CompletedEngineCount = completedEngines
	job.FailedEngineCount = failedEngines
	job.CoveragePass = (completedEngines > 0)
	r.db.Save(&job)

	log.Printf("[SCANNER] ScanJob #%d (%s) COMPLETED! Engines: %d OK, %d Failed.", job.ID, job.Code, completedEngines, failedEngines)

	// 5. Automatically Create or Sync Report
	r.createOrSyncReport(&job)

	// 6. Dispatch Webhook Alert if configured
	r.sendWebhookAlert(&job)
}

func (r *Runner) executeDockerEngine(engineKey, workspacePath, outDir string, job *models.ScanJob) (int, []byte, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()

	var cmd *exec.Cmd
	reportFile := filepath.Join(outDir, "report.json")

	switch engineKey {
	case "semgrep":
		if workspacePath == "" || !dirExists(workspacePath) {
			return 1, nil, fmt.Errorf("workspace directory tidak ditemukan untuk SAST semgrep")
		}
		cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
			"--net=none", "--cpus=1.5", "-m=2048m",
			"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
			"-v", fmt.Sprintf("%s:/out:rw", outDir),
			"semgrep/semgrep:1.74.0",
			"semgrep", "scan", "--config=auto", "--json", "-o", "/out/report.json", "/src",
		)

	case "gitleaks":
		if workspacePath == "" || !dirExists(workspacePath) {
			return 1, nil, fmt.Errorf("workspace directory tidak ditemukan untuk gitleaks")
		}
		cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
			"--net=none", "--cpus=1.5", "-m=2048m",
			"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
			"-v", fmt.Sprintf("%s:/out:rw", outDir),
			"zricethezav/gitleaks:v8.28.0",
			"detect", "--source=/src", "--report-format=json", "--report-path=/out/report.json", "--no-git",
		)

	case "trivy":
		if workspacePath != "" && dirExists(workspacePath) {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"--net=none", "--cpus=1.5", "-m=2048m",
				"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
				"-v", fmt.Sprintf("%s:/out:rw", outDir),
				"aquasec/trivy:latest",
				"fs", "--format", "json", "-o", "/out/report.json", "/src",
			)
		} else if job.Target != nil && job.Target.Type == "container" {
			imageTag := "alpine:latest"
			if job.Target.BaseURL != nil && *job.Target.BaseURL != "" {
				imageTag = *job.Target.BaseURL
			}
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"-v", "/var/run/docker.sock:/var/run/docker.sock",
				"-v", fmt.Sprintf("%s:/out:rw", outDir),
				"aquasec/trivy:latest",
				"image", "--format", "json", "-o", "/out/report.json", imageTag,
			)
		} else {
			return 1, nil, fmt.Errorf("target tidak valid untuk trivy scan")
		}

	case "nuclei":
		targetURL := "http://localhost"
		if job.Target != nil && job.Target.BaseURL != nil && *job.Target.BaseURL != "" {
			targetURL = *job.Target.BaseURL
		}
		reportFile = filepath.Join(outDir, "report.jsonl")
		cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
			"--cpus=1.5", "-m=2048m",
			"-v", fmt.Sprintf("%s:/out:rw", outDir),
			"projectdiscovery/nuclei:v3.1.8",
			"-u", targetURL, "-jsonl", "-o", "/out/report.jsonl", "-silent",
		)

	case "nikto":
		targetURL := "localhost"
		if job.Target != nil && job.Target.BaseURL != nil && *job.Target.BaseURL != "" {
			targetURL = *job.Target.BaseURL
		}
		cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
			"--cpus=1.5", "-m=2048m",
			"-v", fmt.Sprintf("%s:/out:rw", outDir),
			"frapsoft/nikto:latest",
			"-h", targetURL, "-Format", "json", "-o", "/out/report.json",
		)

	default:
		// Generic or mock execution for other engines
		cmd = exec.CommandContext(ctx, "docker", "run", "--rm", "alpine:latest", "echo", fmt.Sprintf("TAMENG scan complete for %s", engineKey))
	}

	output, err := cmd.CombinedOutput()
	exitCode := 0
	if cmd.ProcessState != nil {
		exitCode = cmd.ProcessState.ExitCode()
	}

	// Read generated report file if present
	if data, readErr := os.ReadFile(reportFile); readErr == nil && len(data) > 0 {
		return exitCode, data, nil
	}

	return exitCode, output, err
}

func (r *Runner) parseFindings(engineKey string, data []byte) []NormalizedFinding {
	if len(data) == 0 {
		return nil
	}

	switch engineKey {
	case "semgrep":
		return ParseSemgrepOutput(data)
	case "gitleaks":
		return ParseGitleaksOutput(data)
	case "trivy":
		return ParseTrivyOutput(data)
	case "nuclei":
		return ParseNucleiOutput(data)
	default:
		return nil
	}
}

func (r *Runner) createOrSyncReport(job *models.ScanJob) {
	var findings []models.Finding
	r.db.Preload("Evidences").Where("scan_job_id = ?", job.ID).
		Order("FIELD(severity, 'critical', 'high', 'medium', 'low', 'informational') ASC").
		Order("id DESC").
		Find(&findings)

	var critCount, highCount, medCount, lowCount, infoCount int64
	for _, f := range findings {
		switch strings.ToLower(f.Severity) {
		case "critical":
			critCount++
		case "high":
			highCount++
		case "medium":
			medCount++
		case "low":
			lowCount++
		default:
			infoCount++
		}
	}

	riskSummary := map[string]int64{
		"total":         int64(len(findings)),
		"critical":      critCount,
		"high":          highCount,
		"medium":        medCount,
		"low":           lowCount,
		"informational": infoCount,
	}

	content := map[string]interface{}{
		"title":        "Laporan Standar Pemindaian Keamanan TAMENG",
		"description":  "Laporan ini dibuat dari hasil scan yang sudah selesai diproses oleh pipeline TAMENG Go Native.",
		"scan_job_id": job.ID,
		"findings":     findings,
		"risk_summary": riskSummary,
	}

	meta := map[string]interface{}{
		"finding_count": len(findings),
		"risk_summary":  riskSummary,
		"severity_breakdown": map[string]int64{
			"critical":      critCount,
			"high":          highCount,
			"medium":        medCount,
			"low":           lowCount,
			"informational": infoCount,
		},
		"content":             content,
		"generated_by_engine": "tameng-go-native-runner",
	}
	metaBytes, _ := json.Marshal(meta)
	metaStr := string(metaBytes)

	now := time.Now()
	var report models.Report
	if err := r.db.Where("scan_job_id = ?", job.ID).First(&report).Error; err != nil {
		report = models.Report{
			ScanJobID:   job.ID,
			Type:        "standard",
			Status:      "completed",
			Format:      "pdf",
			GeneratedBy: &job.CreatedBy,
			GeneratedAt: &now,
			Metadata:    &metaStr,
			CreatedAt:   now,
			UpdatedAt:   now,
		}
		r.db.Create(&report)
	} else {
		report.Metadata = &metaStr
		report.Status = "completed"
		report.UpdatedAt = now
		r.db.Save(&report)
	}
}

func (r *Runner) sendWebhookAlert(job *models.ScanJob) {
	webhookURL := os.Getenv("TAMENG_WEBHOOK_URL")
	if webhookURL == "" {
		return
	}

	var critCount, highCount int64
	r.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", job.ID, "critical").Count(&critCount)
	r.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", job.ID, "high").Count(&highCount)

	title := fmt.Sprintf("🛡️ [TAMENG Alert] Pemindaian Selesai: %s", job.Code)
	color := 3066993 // Green
	if critCount > 0 {
		color = 15158332 // Red
	} else if highCount > 0 {
		color = 15105570 // Orange
	}

	payload := map[string]interface{}{
		"content": fmt.Sprintf("✅ Pemindaian TAMENG `%s` untuk Proyek **%s** telah selesai!", job.Code, job.Project.Name),
		"embeds": []map[string]interface{}{
			{
				"title":       title,
				"color":       color,
				"description": fmt.Sprintf("Hasil: %d Critical, %d High", critCount, highCount),
				"fields": []map[string]interface{}{
					{"name": "Scan Code", "value": job.Code, "inline": true},
					{"name": "Status", "value": strings.ToUpper(job.Status), "inline": true},
				},
				"footer": map[string]string{"text": "TAMENG Cyber Security Defense"},
			},
		},
	}

	payloadBytes, _ := json.Marshal(payload)
	_, _ = http.Post(webhookURL, "application/json", strings.NewReader(string(payloadBytes)))
}

func dirExists(path string) bool {
	info, err := os.Stat(path)
	if err != nil {
		return false
	}
	return info.IsDir()
}

func sanitizeDir(name string) string {
	s := strings.ToLower(name)
	s = strings.ReplaceAll(s, " ", "-")
	return strings.ReplaceAll(s, "/", "-")
}

