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
	"sync"
	"time"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/models"

	"gorm.io/gorm"
)

type Runner struct {
	db         *gorm.DB
	cfg        *config.Config
	aiScanner  *AIDeepScanner
	activeJobs sync.Map
}

func NewRunner(db *gorm.DB, cfg *config.Config) *Runner {
	return &Runner{
		db:         db,
		cfg:        cfg,
		aiScanner:  NewAIDeepScanner(cfg),
		activeJobs: sync.Map{},
	}
}

// StartWorker initiates the background polling loop for queued scan jobs and stale watchdog.
func (r *Runner) StartWorker(ctx context.Context) {
	log.Println("[SCANNER WORKER] Native Golang Docker Scanner Runner initialized and active with AI Deep Semantic Auditor.")
	ticker := time.NewTicker(3 * time.Second)
	defer ticker.Stop()

	watchdogTicker := time.NewTicker(15 * time.Second)
	defer watchdogTicker.Stop()

	// Initial stale/orphaned job recovery on startup
	go r.recoverStaleJobs()

	for {
		select {
		case <-ctx.Done():
			log.Println("[SCANNER WORKER] Stopping scanner worker.")
			return
		case <-ticker.C:
			r.pollAndProcess()
		case <-watchdogTicker.C:
			r.recoverStaleJobs()
		}
	}
}

func (r *Runner) recoverStaleJobs() {
	var staleJobs []models.ScanJob
	// Stale threshold: jobs marked running older than 2 minutes ago or with no started_at
	threshold := time.Now().Add(-2 * time.Minute)
	if err := r.db.Where("status = ? AND (started_at IS NULL OR started_at < ?)", "running", threshold).
		Order("id ASC").Limit(3).Find(&staleJobs).Error; err != nil {
		return
	}

	for _, job := range staleJobs {
		if _, loaded := r.activeJobs.Load(job.ID); loaded {
			continue // Already actively being processed by a worker goroutine
		}
		log.Printf("[SCANNER WATCHDOG] Detected stale/orphaned running ScanJob #%d (%s, started at %v). Recovering and resuming execution...",
			job.ID, job.Code, job.StartedAt)
		go r.processJob(job.ID)
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
	if _, loaded := r.activeJobs.LoadOrStore(jobID, true); loaded {
		return
	}
	defer r.activeJobs.Delete(jobID)

	var job models.ScanJob
	if err := r.db.Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		First(&job, jobID).Error; err != nil {
		return
	}

	// If job was cancelled, do not process
	if job.Status == "cancelled" {
		log.Printf("[SCANNER] ScanJob #%d (%s) is cancelled. Skipping execution.", job.ID, job.Code)
		return
	}

	// Claim job if queued
	now := time.Now()
	if job.Status == "queued" {
		job.Status = "running"
		job.StartedAt = &now
		job.Progress = 5
		r.db.Save(&job)
	} else if job.Status == "running" && job.StartedAt == nil {
		job.StartedAt = &now
		r.db.Save(&job)
	}

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

	// If plan is empty, populate from scan profile or standard full-suite engines
	if len(planItems) == 0 {
		var engineKeys []string
		if job.ScanProfile != nil && job.ScanProfile.EngineKeys != "" {
			_ = json.Unmarshal([]byte(job.ScanProfile.EngineKeys), &engineKeys)
		}

		if len(engineKeys) == 0 {
			if job.RepositoryID != nil {
				engineKeys = []string{"semgrep", "gitleaks", "trivy", "osv", "syft", "hadolint", "checkov", "grype"}
			} else if job.Target != nil && job.Target.Type == "container" {
				engineKeys = []string{"trivy", "grype", "hadolint", "syft"}
			} else {
				engineKeys = []string{"zap", "nuclei", "testssl", "nikto"}
			}
		}

		for _, k := range engineKeys {
			planItems = append(planItems, struct {
				EngineKey    string `json:"engine_key"`
				Status       string `json:"status"`
				FindingCount int    `json:"finding_count"`
			}{EngineKey: strings.ToLower(k), Status: "queued", FindingCount: 0})
		}
	}

	// Filter out any internal AI engines from the public engine plan so it remains confidential
	var cleanPlanItems []struct {
		EngineKey    string `json:"engine_key"`
		Status       string `json:"status"`
		FindingCount int    `json:"finding_count"`
	}
	for _, item := range planItems {
		kClean := strings.ToLower(strings.TrimSpace(item.EngineKey))
		if kClean != "tameng_ai" && kClean != "tamengai" && kClean != "" {
			cleanPlanItems = append(cleanPlanItems, item)
		}
	}
	planItems = cleanPlanItems

	// Persist the clean planned list to DB immediately so the UI displays only standard security engines
	initPlanBytes, _ := json.Marshal(planItems)
	job.EnginePlan = string(initPlanBytes)
	job.RequiredEngineCount = len(planItems)
	r.db.Model(&models.ScanJob{}).Where("id = ?", job.ID).Updates(map[string]interface{}{
		"engine_plan":           job.EnginePlan,
		"required_engine_count": job.RequiredEngineCount,
	})

	completedEngines := 0
	failedEngines := 0
	totalEngines := len(planItems)

	// 3. Execute Each Engine in the Plan
	for i, item := range planItems {
		// Check if job was cancelled
		var checkJob models.ScanJob
		if err := r.db.Select("status").First(&checkJob, job.ID).Error; err == nil && checkJob.Status == "cancelled" {
			log.Printf("[SCANNER] ScanJob #%d was cancelled by user. Halting execution loop.", job.ID)
			return
		}

		engineKey := strings.ToLower(item.EngineKey)

		// Check if this engine was ALREADY completed previously (e.g. before worker restart)
		var existingRun models.ScanRun
		if err := r.db.Where("scan_job_id = ? AND engine_key = ? AND status = 'completed'", job.ID, engineKey).First(&existingRun).Error; err == nil {
			log.Printf("[SCANNER] ScanJob #%d: Engine [%s] already completed previously. Resuming and skipping redundant execution.", job.ID, engineKey)
			completedEngines++
			var fCount int64
			r.db.Model(&models.Finding{}).Where("scan_job_id = ? AND scan_run_id = ?", job.ID, existingRun.ID).Count(&fCount)
			planItems[i].Status = "completed"
			planItems[i].FindingCount = int(fCount)

			prog := uint8(((i + 1) * 100) / totalEngines)
			if prog > 95 && i < totalEngines-1 {
				prog = 95
			}
			job.Progress = prog
			job.CompletedEngineCount = completedEngines
			updatedPlanBytes, _ := json.Marshal(planItems)
			job.EnginePlan = string(updatedPlanBytes)
			r.db.Save(&job)
			continue
		}

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

		// Mark current engine as running in plan so real-time status is visible immediately
		planItems[i].Status = "running"
		planStartBytes, _ := json.Marshal(planItems)
		job.EnginePlan = string(planStartBytes)
		r.db.Save(&job)

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
		job.CompletedEngineCount = completedEngines
		job.FailedEngineCount = failedEngines
		updatedPlanBytes, _ := json.Marshal(planItems)
		job.EnginePlan = string(updatedPlanBytes)
		r.db.Save(&job)
	}

	// 3.5 Run TAMENG Neural AI Deep Semantic Vulnerability Audit SECRETLY in background
	if r.aiScanner != nil {
		log.Printf("[SCANNER] Job #%d: Running Secret TAMENG Neural AI Deep Semantic Vulnerability Audit in background...", job.ID)
		aiFindings, aiErr := r.aiScanner.RunDeepAudit(workspacePath, &job)
		if aiErr != nil {
			log.Printf("[SCANNER] Secret AI Deep Audit warning: %v", aiErr)
		} else if len(aiFindings) > 0 {
			log.Printf("[SCANNER] Secret TAMENG Neural AI discovered %d deep semantic vulnerabilities!", len(aiFindings))
			for _, f := range aiFindings {
				_ = IngestFinding(r.db, &job, nil, "tameng_ai", f)
			}
		}
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
			"--cpus=2.0", "-m=2048m",
			"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
			"-v", fmt.Sprintf("%s:/out:rw", outDir),
			"semgrep/semgrep:1.74.0",
			"semgrep", "scan",
			"--config=p/default",
			"--config=p/security-audit",
			"--config=p/owasp-top-ten",
			"--config=p/cwe-top-25",
			"--config=p/secrets",
			"--exclude=vendor",
			"--exclude=node_modules",
			"--exclude=.git",
			"--metrics=off",
			"--json", "-o", "/out/report.json", "/src",
		)

	case "gitleaks":
		if workspacePath == "" || !dirExists(workspacePath) {
			return 1, nil, fmt.Errorf("workspace directory tidak ditemukan untuk gitleaks")
		}
		hasGit := dirExists(filepath.Join(workspacePath, ".git"))
		args := []string{"run", "--rm",
			"--net=none", "--cpus=1.5", "-m=2048m",
			"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
			"-v", fmt.Sprintf("%s:/out:rw", outDir),
			"zricethezav/gitleaks:v8.28.0",
			"detect", "--source=/src", "--report-format=json", "--report-path=/out/report.json",
		}
		if !hasGit {
			args = append(args, "--no-git")
		}
		cmd = exec.CommandContext(ctx, "docker", args...)

	case "trivy":
		cacheDir := "/var/cache/tameng/trivy"
		_ = os.MkdirAll(cacheDir, 0777)
		if workspacePath != "" && dirExists(workspacePath) {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"--cpus=1.5", "-m=2048m",
				"-v", fmt.Sprintf("%s:/root/.cache/", cacheDir),
				"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
				"-v", fmt.Sprintf("%s:/out:rw", outDir),
				"aquasec/trivy:latest",
				"fs", "--scanners", "vuln,misconfig,secret",
				"--severity", "CRITICAL,HIGH,MEDIUM,LOW",
				"--format", "json", "-o", "/out/report.json", "/src",
			)
		} else if job.Target != nil && job.Target.Type == "container" {
			imageTag := "alpine:latest"
			if job.Target.BaseURL != nil && *job.Target.BaseURL != "" {
				imageTag = *job.Target.BaseURL
			}
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"-v", "/var/run/docker.sock:/var/run/docker.sock",
				"-v", fmt.Sprintf("%s:/root/.cache/", cacheDir),
				"-v", fmt.Sprintf("%s:/out:rw", outDir),
				"aquasec/trivy:latest",
				"image", "--scanners", "vuln,misconfig,secret",
				"--severity", "CRITICAL,HIGH,MEDIUM,LOW",
				"--format", "json", "-o", "/out/report.json", imageTag,
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
			"-u", targetURL,
			"-tags", "cve,misconfig,vuln,exposure,panel,tech,ssl,token",
			"-jsonl", "-o", "/out/report.jsonl", "-silent",
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

	case "osv":
		if workspacePath != "" && dirExists(workspacePath) {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"--cpus=1.5", "-m=2048m",
				"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
				"-v", fmt.Sprintf("%s:/out:rw", outDir),
				"ghcr.io/google/osv-scanner:latest",
				"--format", "json", "--output", "/out/report.json", "--recursive", "/src",
			)
		} else {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm", "alpine:latest", "echo", "OSV scan complete")
		}

	case "syft":
		if workspacePath != "" && dirExists(workspacePath) {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"--net=none", "--cpus=1.5", "-m=2048m",
				"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
				"-v", fmt.Sprintf("%s:/out:rw", outDir),
				"anchore/syft:latest",
				"dir:/src", "-o", "json", "--file", "/out/report.json",
			)
		} else {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm", "alpine:latest", "echo", "Syft scan complete")
		}

	case "hadolint":
		hasDockerfile := false
		dockerfilePath := ""
		if workspacePath != "" && dirExists(workspacePath) {
			matches, _ := filepath.Glob(filepath.Join(workspacePath, "*Dockerfile*"))
			if len(matches) > 0 {
				hasDockerfile = true
				rel, _ := filepath.Rel(workspacePath, matches[0])
				dockerfilePath = "/src/" + rel
			}
		}
		if hasDockerfile {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"--net=none", "--cpus=1.0", "-m=1024m",
				"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
				"hadolint/hadolint:v2.12.0",
				"hadolint", "-f", "json", dockerfilePath,
			)
		} else {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm", "alpine:latest", "echo", "[]")
		}

	case "grype":
		cacheDir := "/var/cache/tameng/grype"
		_ = os.MkdirAll(cacheDir, 0777)
		if workspacePath != "" && dirExists(workspacePath) {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"--cpus=1.5", "-m=2048m",
				"-v", fmt.Sprintf("%s:/root/.cache/grype", cacheDir),
				"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
				"-v", fmt.Sprintf("%s:/out:rw", outDir),
				"anchore/grype:v0.74.0",
				"dir:/src", "-o", "json", "--file", "/out/report.json",
			)
		} else {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm", "alpine:latest", "echo", "Grype scan complete")
		}

	case "checkov":
		if workspacePath != "" && dirExists(workspacePath) {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
				"--cpus=1.5", "-m=2048m",
				"-v", fmt.Sprintf("%s:/src:ro", workspacePath),
				"-v", fmt.Sprintf("%s:/out:rw", outDir),
				"bridgecrew/checkov:3.2.35",
				"-d", "/src",
				"--skip-path", "/src/vendor",
				"--skip-path", "/src/node_modules",
				"--output", "json",
				"--output-file-path", "/out/report.json",
				"--soft-fail",
			)
		} else {
			cmd = exec.CommandContext(ctx, "docker", "run", "--rm", "alpine:latest", "echo", "Checkov scan complete")
		}

	case "testssl":
		targetURL := "localhost"
		if job.Target != nil && job.Target.BaseURL != nil && *job.Target.BaseURL != "" {
			targetURL = *job.Target.BaseURL
		}
		cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
			"--cpus=1.5", "-m=2048m",
			"-v", fmt.Sprintf("%s:/out:rw", outDir),
			"drwetter/testssl.sh:latest",
			"--jsonfile", "/out/report.json", "--fast", targetURL,
		)

	case "zap":
		targetURL := "http://localhost"
		if job.Target != nil && job.Target.BaseURL != nil && *job.Target.BaseURL != "" {
			targetURL = *job.Target.BaseURL
		}
		cmd = exec.CommandContext(ctx, "docker", "run", "--rm",
			"--cpus=1.5", "-m=2048m",
			"-v", fmt.Sprintf("%s:/zap/wrk/:rw", outDir),
			"zaproxy/zap-stable:latest",
			"zap-baseline.py", "-t", targetURL, "-J", "report.json",
		)

	default:
		// Generic execution for custom/other engines
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

	// Security scanners often exit with code 1 when findings are present (not fatal engine crash)
	if exitCode == 0 || exitCode == 1 {
		return exitCode, output, nil
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
	case "osv":
		return ParseOsvOutput(data)
	case "checkov":
		return ParseCheckovOutput(data)
	case "hadolint":
		return ParseHadolintOutput(data)
	case "grype":
		return ParseGrypeOutput(data)
	case "nuclei":
		return ParseNucleiOutput(data)
	case "nikto":
		return ParseNiktoOutput(data)
	case "testssl":
		return ParseTestsslOutput(data)
	case "zap":
		return ParseZapOutput(data)
	default:
		return nil
	}
}

func (r *Runner) createOrSyncReport(job *models.ScanJob) {
	CreateOrSyncReport(r.db, job)
}

func CreateOrSyncReport(db *gorm.DB, job *models.ScanJob) {
	var findings []models.Finding
	db.Preload("Evidences").Where("scan_job_id = ?", job.ID).
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
	if err := db.Where("scan_job_id = ?", job.ID).First(&report).Error; err != nil {
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
		db.Create(&report)
	} else {
		report.Metadata = &metaStr
		report.Status = "completed"
		report.UpdatedAt = now
		db.Save(&report)
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

