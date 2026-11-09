package handlers

import (
	"encoding/json"
	"net/http"
	"strings"
	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type OverviewHandler struct {
	db *gorm.DB
}

func NewOverviewHandler(db *gorm.DB) *OverviewHandler {
	return &OverviewHandler{db: db}
}

func (h *OverviewHandler) GetOverview(c *gin.Context) {
	var user *models.User
	if val, exists := c.Get("user"); exists {
		user = val.(*models.User)
	}

	var isAdmin bool
	var allowedProjectIDs []uint64
	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		isAdmin = (roleName == "super_admin" || roleName == "security_admin")
		if !isAdmin {
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &allowedProjectIDs)
		}
	}

	// 1. Scan Profiles
	var rawProfiles []models.ScanProfile
	h.db.Where("is_active = ?", true).Order("id ASC").Find(&rawProfiles)

	var scanProfiles []gin.H
	for _, p := range rawProfiles {
		var engineKeys []string
		if p.EngineKeys != "" {
			_ = json.Unmarshal([]byte(p.EngineKeys), &engineKeys)
		}
		if engineKeys == nil {
			engineKeys = []string{}
		}
		scanProfiles = append(scanProfiles, gin.H{
			"key":            p.Key,
			"name":           p.Name,
			"description":    p.Description,
			"engine_keys":    engineKeys,
			"active_testing": p.ActiveTesting,
		})
	}

	// 2. Count Queries
	var projectCount, repoCount, targetCount, scanJobCount, activeScans int64

	pQuery := h.db.Model(&models.Project{})
	rQuery := h.db.Model(&models.Repository{})
	tQuery := h.db.Model(&models.Target{})
	sQuery := h.db.Model(&models.ScanJob{})
	fQuery := h.db.Model(&models.Finding{})

	if !isAdmin && user != nil {
		if len(allowedProjectIDs) > 0 {
			pQuery = pQuery.Where("id IN (?)", allowedProjectIDs)
			rQuery = rQuery.Where("project_id IN (?)", allowedProjectIDs)
			tQuery = tQuery.Where("project_id IN (?)", allowedProjectIDs)
			sQuery = sQuery.Where("project_id IN (?)", allowedProjectIDs)
			fQuery = fQuery.Where("project_id IN (?)", allowedProjectIDs)
		} else {
			pQuery = pQuery.Where("1 = 0")
			rQuery = rQuery.Where("1 = 0")
			tQuery = tQuery.Where("1 = 0")
			sQuery = sQuery.Where("1 = 0")
			fQuery = fQuery.Where("1 = 0")
		}
	}

	pQuery.Count(&projectCount)
	rQuery.Count(&repoCount)
	tQuery.Count(&targetCount)
	sQuery.Count(&scanJobCount)

	activeQuery := h.db.Model(&models.ScanJob{}).Where("status IN (?)", []string{"queued", "running"})
	if !isAdmin && user != nil && len(allowedProjectIDs) > 0 {
		activeQuery = activeQuery.Where("project_id IN (?)", allowedProjectIDs)
	} else if !isAdmin && user != nil {
		activeQuery = activeQuery.Where("1 = 0")
	}
	activeQuery.Count(&activeScans)

	// Findings Breakdown
	var totalFindings, critCount, highCount, medCount, lowCount int64
	fQuery.Count(&totalFindings)

	fcCrit := h.db.Model(&models.Finding{}).Where("LOWER(severity) = ?", "critical")
	fcHigh := h.db.Model(&models.Finding{}).Where("LOWER(severity) = ?", "high")
	fcMed := h.db.Model(&models.Finding{}).Where("LOWER(severity) = ?", "medium")
	fcLow := h.db.Model(&models.Finding{}).Where("LOWER(severity) = ?", "low")

	if !isAdmin && user != nil && len(allowedProjectIDs) > 0 {
		fcCrit = fcCrit.Where("project_id IN (?)", allowedProjectIDs)
		fcHigh = fcHigh.Where("project_id IN (?)", allowedProjectIDs)
		fcMed = fcMed.Where("project_id IN (?)", allowedProjectIDs)
		fcLow = fcLow.Where("project_id IN (?)", allowedProjectIDs)
	} else if !isAdmin && user != nil {
		fcCrit = fcCrit.Where("1 = 0")
		fcHigh = fcHigh.Where("1 = 0")
		fcMed = fcMed.Where("1 = 0")
		fcLow = fcLow.Where("1 = 0")
	}

	fcCrit.Count(&critCount)
	fcHigh.Count(&highCount)
	fcMed.Count(&medCount)
	fcLow.Count(&lowCount)

	// Jobs table count
	var pendingJobs, failedJobs int64
	h.db.Table("jobs").Count(&pendingJobs)
	h.db.Table("failed_jobs").Count(&failedJobs)

	queueStatus := "ready"
	queueLabel := "Queue Worker Siap"
	if failedJobs > 0 {
		queueStatus = "warning"
		queueLabel = "Beberapa Job Gagal"
	} else if pendingJobs > 0 || activeScans > 0 {
		queueStatus = "busy"
		queueLabel = "Memproses Pemindaian Aktif"
	}

	var workerCount int64 = 1

	c.JSON(http.StatusOK, gin.H{
		"product": "SecSys Security Scan System",
		"mode":    "mvp_internal",
		"principles": []string{
			"NO VERIFIED TARGET = NO EXECUTION",
			"OUTSIDE SCOPE = DENY",
			"AI IS ADVISORY ONLY",
		},
		"counts": gin.H{
			"projects":          projectCount,
			"repositories":      repoCount,
			"targets":           targetCount,
			"scan_jobs":         scanJobCount,
			"active_scans":      activeScans,
			"findings":          totalFindings,
			"critical_findings": critCount,
			"high_findings":     highCount,
			"medium_findings":   medCount,
			"low_findings":      lowCount,
			"workers":           workerCount,
		},
		"queue_telemetry": gin.H{
			"pending_jobs": pendingJobs,
			"failed_jobs":  failedJobs,
			"active_scans": activeScans,
			"status":       queueStatus,
			"label":        queueLabel,
		},
		"scan_profiles": scanProfiles,
	})
}

