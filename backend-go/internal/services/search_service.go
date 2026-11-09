package services

import (
	"context"
	"fmt"
	"strings"
	"tameng-backend-go/internal/models"

	"gorm.io/gorm"
)

type SearchItem struct {
	ID         string `json:"id"`
	Title      string `json:"title"`
	Subtitle   string `json:"subtitle"`
	Category   string `json:"category"` // 'Halaman' | 'Celah Keamanan' | 'Aset & Repositori' | 'Pekerjaan Scan'
	URL        string `json:"url"`
	Badge      string `json:"badge,omitempty"`
	BadgeClass string `json:"badgeClass,omitempty"`
	IconType   string `json:"iconType"` // 'dashboard' | 'scan' | 'folder' | 'target' | 'shield' | 'bug' | 'report' | 'user' | 'cpu' | 'audit'
}

type SearchService struct {
	db *gorm.DB
}

func NewSearchService(db *gorm.DB) *SearchService {
	return &SearchService{db: db}
}

// Search performs a unified lightning-fast query across modules, findings, scans, repos, and targets.
func (s *SearchService) Search(ctx context.Context, rawQuery string, user *models.User) ([]SearchItem, error) {
	q := strings.TrimSpace(rawQuery)
	if q == "" {
		return []SearchItem{}, nil
	}

	lowerQ := strings.ToLower(q)
	likePattern := "%" + lowerQ + "%"

	var results []SearchItem

	// 1. Static Module Pages matching query
	staticPages := []SearchItem{
		{ID: "page-dashboard", Title: "Dasbor Keamanan", Subtitle: "Overview metrik insiden & status keamanan real-time", Category: "Halaman", URL: "/", Badge: "Modul", BadgeClass: "bg-azure-lt", IconType: "dashboard"},
		{ID: "page-scan-jobs", Title: "Pekerjaan Scan", Subtitle: "Monitoring antrean dan riwayat pekerjaan pemindaian", Category: "Halaman", URL: "/scan-jobs", Badge: "Modul", BadgeClass: "bg-azure-lt", IconType: "scan"},
		{ID: "page-scan-saya", Title: "Scan Mandiri", Subtitle: "Pengajuan pemindaian kode dan target mandiri", Category: "Halaman", URL: "/scan-saya", Badge: "Dev", BadgeClass: "bg-blue-lt", IconType: "scan"},
		{ID: "page-projects", Title: "Proyek", Subtitle: "Daftar grup proyek aplikasi dan unit terlindungi", Category: "Halaman", URL: "/projects", Badge: "Aset", BadgeClass: "bg-purple-lt", IconType: "folder"},
		{ID: "page-repositories", Title: "Repositori Kode", Subtitle: "Manajemen repositori Git sumber kode untuk SAST", Category: "Halaman", URL: "/repositories", Badge: "Aset", BadgeClass: "bg-purple-lt", IconType: "folder"},
		{ID: "page-targets", Title: "Target Web & API", Subtitle: "Endpoint URL dan IP host untuk pengujian DAST", Category: "Halaman", URL: "/targets", Badge: "Aset", BadgeClass: "bg-purple-lt", IconType: "target"},
		{ID: "page-scopes", Title: "Ruang Lingkup (Scope)", Subtitle: "Aturan batasan domain dan subnet yang diizinkan dipindai", Category: "Halaman", URL: "/scopes", Badge: "Tata Kelola", BadgeClass: "bg-cyan-lt", IconType: "shield"},
		{ID: "page-authorizations", Title: "Otorisasi Scan", Subtitle: "Surat persetujuan formal dan izin audit keamanan", Category: "Halaman", URL: "/authorizations", Badge: "Tata Kelola", BadgeClass: "bg-cyan-lt", IconType: "shield"},
		{ID: "page-engines", Title: "Engine Registry", Subtitle: "20 Mesin scan: ZAP, Semgrep, Trivy, MobSF, Gitleaks, dsb.", Category: "Halaman", URL: "/engines", Badge: "Mesin", BadgeClass: "bg-teal-lt", IconType: "cpu"},
		{ID: "page-findings", Title: "Temuan Kerentanan", Subtitle: "Database seluruh celah keamanan (CVE, CWE, OWASP)", Category: "Halaman", URL: "/findings", Badge: "Analisis", BadgeClass: "bg-red-lt", IconType: "bug"},
		{ID: "page-reports", Title: "Laporan Keamanan", Subtitle: "Dokumen eksekutif & teknis hasil audit siap unduh PDF/Excel", Category: "Halaman", URL: "/reports", Badge: "Laporan", BadgeClass: "bg-indigo-lt", IconType: "report"},
		{ID: "page-audit-logs", Title: "Log Audit Forensik", Subtitle: "Jejak rekam aktivitas sistem dan pengguna yang immutable", Category: "Halaman", URL: "/audit-logs", Badge: "Audit", BadgeClass: "bg-secondary-lt", IconType: "audit"},
		{ID: "page-users", Title: "Manajemen Pengguna", Subtitle: "Pengaturan akun staf SOC dan hak akses RBAC", Category: "Halaman", URL: "/users", Badge: "Admin", BadgeClass: "bg-yellow-lt", IconType: "user"},
	}

	for _, page := range staticPages {
		if strings.Contains(strings.ToLower(page.Title), lowerQ) ||
			strings.Contains(strings.ToLower(page.Subtitle), lowerQ) ||
			strings.Contains(strings.ToLower(page.URL), lowerQ) {
			results = append(results, page)
		}
	}

	// 2. Check Project Scoping for Database Entities
	var isSuperOrSecAdmin bool
	var allowedProjectIDs []uint64
	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		isSuperOrSecAdmin = (roleName == "super_admin" || roleName == "security_admin")
		if !isSuperOrSecAdmin {
			s.db.WithContext(ctx).Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &allowedProjectIDs)
		}
	}

	// 3. Search Findings (Celah Keamanan)
	var findings []models.Finding
	findingQuery := s.db.WithContext(ctx).
		Preload("Project").
		Where("LOWER(title) LIKE ? OR LOWER(rule_id) LIKE ? OR LOWER(code) LIKE ? OR LOWER(cve) LIKE ? OR LOWER(cwe) LIKE ? OR LOWER(severity) LIKE ?",
			likePattern, likePattern, likePattern, likePattern, likePattern, likePattern)
	if !isSuperOrSecAdmin && len(allowedProjectIDs) > 0 {
		findingQuery = findingQuery.Where("project_id IN (?)", allowedProjectIDs)
	} else if !isSuperOrSecAdmin && user != nil {
		findingQuery = findingQuery.Where("1 = 0") // No access to any projects
	}
	findingQuery.Order("id DESC").Limit(8).Find(&findings)

	for _, f := range findings {
		sev := strings.ToUpper(f.Severity)
		badgeCls := "bg-secondary-lt text-secondary"
		switch strings.ToLower(f.Severity) {
		case "critical":
			badgeCls = "bg-red text-red-fg"
		case "high":
			badgeCls = "bg-orange text-orange-fg"
		case "medium":
			badgeCls = "bg-yellow text-yellow-fg"
		case "low":
			badgeCls = "bg-blue-lt text-blue"
		}

		projectName := "Aset"
		if f.Project.Name != "" {
			projectName = f.Project.Name
		}

		title := f.Title
		if title == "" && f.RuleID != nil {
			title = *f.RuleID
		}
		if title == "" {
			title = "Kerentanan Terdeteksi"
		}

		results = append(results, SearchItem{
			ID:         fmt.Sprintf("finding-%d", f.ID),
			Title:      title,
			Subtitle:   fmt.Sprintf("%s • Engine: %s • Proyek: %s", f.Code, f.EngineKey, projectName),
			Category:   "Celah Keamanan",
			URL:        "/findings",
			Badge:      sev,
			BadgeClass: badgeCls,
			IconType:   "bug",
		})
	}

	// 4. Search Scan Jobs (Pekerjaan Scan)
	var jobs []models.ScanJob
	jobQuery := s.db.WithContext(ctx).
		Preload("Project").
		Where("LOWER(code) LIKE ? OR LOWER(status) LIKE ?", likePattern, likePattern)
	if !isSuperOrSecAdmin && len(allowedProjectIDs) > 0 {
		jobQuery = jobQuery.Where("project_id IN (?)", allowedProjectIDs)
	} else if !isSuperOrSecAdmin && user != nil {
		jobQuery = jobQuery.Where("1 = 0")
	}
	jobQuery.Order("id DESC").Limit(5).Find(&jobs)

	for _, j := range jobs {
		statusBadgeCls := "bg-secondary-lt"
		switch strings.ToLower(j.Status) {
		case "completed":
			statusBadgeCls = "bg-green-lt text-green"
		case "failed":
			statusBadgeCls = "bg-red-lt text-red"
		case "running":
			statusBadgeCls = "bg-azure-lt text-azure"
		default:
			statusBadgeCls = "bg-yellow-lt text-yellow"
		}

		projectName := "Proyek"
		if j.Project.Name != "" {
			projectName = j.Project.Name
		}

		results = append(results, SearchItem{
			ID:         fmt.Sprintf("job-%d", j.ID),
			Title:      fmt.Sprintf("Scan %s", j.Code),
			Subtitle:   fmt.Sprintf("Status: %s • Progres: %d%% • Proyek: %s", j.Status, j.Progress, projectName),
			Category:   "Pekerjaan Scan",
			URL:        "/scan-jobs",
			Badge:      strings.ToUpper(j.Status),
			BadgeClass: statusBadgeCls,
			IconType:   "scan",
		})
	}

	// 5. Search Repositories
	var repos []models.Repository
	repoQuery := s.db.WithContext(ctx).
		Preload("Project").
		Where("LOWER(name) LIKE ? OR LOWER(clone_url) LIKE ?", likePattern, likePattern)
	if !isSuperOrSecAdmin && len(allowedProjectIDs) > 0 {
		repoQuery = repoQuery.Where("project_id IN (?)", allowedProjectIDs)
	} else if !isSuperOrSecAdmin && user != nil {
		repoQuery = repoQuery.Where("1 = 0")
	}
	repoQuery.Order("id DESC").Limit(4).Find(&repos)

	for _, r := range repos {
		results = append(results, SearchItem{
			ID:         fmt.Sprintf("repo-%d", r.ID),
			Title:      r.Name,
			Subtitle:   fmt.Sprintf("Git: %s (Branch: %s)", r.DefaultBranch, r.Project.Name),
			Category:   "Aset & Repositori",
			URL:        "/repositories",
			Badge:      "Git SAST",
			BadgeClass: "bg-purple-lt text-purple",
			IconType:   "folder",
		})
	}

	// 6. Search Targets
	var targets []models.Target
	targetQuery := s.db.WithContext(ctx).
		Preload("Project").
		Where("LOWER(name) LIKE ? OR LOWER(base_url) LIKE ? OR LOWER(hostname) LIKE ?", likePattern, likePattern, likePattern)
	if !isSuperOrSecAdmin && len(allowedProjectIDs) > 0 {
		targetQuery = targetQuery.Where("project_id IN (?)", allowedProjectIDs)
	} else if !isSuperOrSecAdmin && user != nil {
		targetQuery = targetQuery.Where("1 = 0")
	}
	targetQuery.Order("id DESC").Limit(4).Find(&targets)

	for _, t := range targets {
		urlStr := ""
		if t.BaseURL != nil {
			urlStr = *t.BaseURL
		} else if t.Hostname != nil {
			urlStr = *t.Hostname
		}
		results = append(results, SearchItem{
			ID:         fmt.Sprintf("target-%d", t.ID),
			Title:      t.Name,
			Subtitle:   fmt.Sprintf("Target: %s (%s)", urlStr, t.Type),
			Category:   "Aset & Repositori",
			URL:        "/targets",
			Badge:      strings.ToUpper(t.Type),
			BadgeClass: "bg-teal-lt text-teal",
			IconType:   "target",
		})
	}

	// 7. Search Projects
	var projects []models.Project
	projectQuery := s.db.WithContext(ctx).
		Where("LOWER(name) LIKE ? OR LOWER(code) LIKE ?", likePattern, likePattern)
	if !isSuperOrSecAdmin && len(allowedProjectIDs) > 0 {
		projectQuery = projectQuery.Where("id IN (?)", allowedProjectIDs)
	} else if !isSuperOrSecAdmin && user != nil {
		projectQuery = projectQuery.Where("1 = 0")
	}
	projectQuery.Order("id DESC").Limit(4).Find(&projects)

	for _, p := range projects {
		results = append(results, SearchItem{
			ID:         fmt.Sprintf("project-%d", p.ID),
			Title:      p.Name,
			Subtitle:   fmt.Sprintf("Kode Proyek: %s", p.Code),
			Category:   "Aset & Repositori",
			URL:        "/projects",
			Badge:      "Proyek",
			BadgeClass: "bg-cyan-lt text-cyan",
			IconType:   "folder",
		})
	}

	return results, nil
}

