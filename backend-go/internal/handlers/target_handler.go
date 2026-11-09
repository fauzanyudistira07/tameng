package handlers

import (
	"encoding/json"
	"fmt"
	"math/rand"
	"net/http"
	"strconv"
	"strings"
	"time"

	"tameng-backend-go/internal/models"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type TargetHandler struct {
	db *gorm.DB
}

func NewTargetHandler(db *gorm.DB) *TargetHandler {
	return &TargetHandler{db: db}
}

// List handles GET /api/targets
func (h *TargetHandler) List(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.Target{}).
		Preload("Project").
		Preload("Verifier").
		Order("id DESC")

	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		if roleName != "super_admin" && roleName != "security_admin" {
			var assignedIDs []uint64
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &assignedIDs)
			query = query.Where("project_id IN (?)", assignedIDs)
		}
	}

	var targets []models.Target
	if err := query.Find(&targets).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar target."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"targets": targets,
	})
}

// Show handles GET /api/targets/:id
func (h *TargetHandler) Show(c *gin.Context) {
	idStr := c.Param("id")
	targetID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var target models.Target
	if err := h.db.Preload("Project").Preload("Verifier").First(&target, targetID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Target tidak ditemukan."})
		return
	}

	// Scopes for this target
	var scopes []models.Scope
	h.db.Where("target_id = ?", target.ID).Order("id DESC").Find(&scopes)

	// Scan jobs for this target
	var scanJobs []models.ScanJob
	h.db.Where("target_id = ?", target.ID).
		Preload("ScanProfile").
		Preload("Creator").
		Order("id DESC").
		Limit(20).
		Find(&scanJobs)

	// Finding stats
	var totalFindings, critCount, highCount, medCount, lowCount int64
	var scanJobIDs []uint64
	h.db.Model(&models.ScanJob{}).Where("target_id = ?", target.ID).Pluck("id", &scanJobIDs)

	if len(scanJobIDs) > 0 {
		h.db.Model(&models.Finding{}).Where("scan_job_id IN (?)", scanJobIDs).Count(&totalFindings)
		h.db.Model(&models.Finding{}).Where("scan_job_id IN (?) AND LOWER(severity) = ?", scanJobIDs, "critical").Count(&critCount)
		h.db.Model(&models.Finding{}).Where("scan_job_id IN (?) AND LOWER(severity) = ?", scanJobIDs, "high").Count(&highCount)
		h.db.Model(&models.Finding{}).Where("scan_job_id IN (?) AND LOWER(severity) = ?", scanJobIDs, "medium").Count(&medCount)
		h.db.Model(&models.Finding{}).Where("scan_job_id IN (?) AND LOWER(severity) = ?", scanJobIDs, "low").Count(&lowCount)
	}

	c.JSON(http.StatusOK, gin.H{
		"target":    target,
		"scan_jobs": scanJobs,
		"scopes":    scopes,
		"findings_summary": gin.H{
			"total":    totalFindings,
			"critical": critCount,
			"high":     highCount,
			"medium":   medCount,
			"low":      lowCount,
		},
	})
}

// Store handles POST /api/targets
func (h *TargetHandler) Store(c *gin.Context) {
	var req struct {
		ProjectID uint64  `json:"project_id" binding:"required"`
		Type      string  `json:"type" binding:"required"`
		Name      string  `json:"name" binding:"required"`
		BaseURL   *string `json:"base_url"`
		Hostname  *string `json:"hostname"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid: " + err.Error()})
		return
	}

	now := time.Now()
	target := models.Target{
		ProjectID:          req.ProjectID,
		Type:               req.Type,
		Name:               req.Name,
		BaseURL:            req.BaseURL,
		Hostname:           req.Hostname,
		VerificationStatus: "pending",
		CreatedAt:          now,
		UpdatedAt:          now,
	}

	if err := h.db.Create(&target).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menyimpan target."})
		return
	}

	h.db.Preload("Project").First(&target, target.ID)

	c.JSON(http.StatusCreated, gin.H{
		"target": target,
	})
}

// Update handles PUT /api/targets/:id
func (h *TargetHandler) Update(c *gin.Context) {
	idStr := c.Param("id")
	targetID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var target models.Target
	if err := h.db.First(&target, targetID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Target tidak ditemukan."})
		return
	}

	var req struct {
		Name     string  `json:"name"`
		BaseURL  *string `json:"base_url"`
		Hostname *string `json:"hostname"`
		Type     string  `json:"type"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid."})
		return
	}

	if req.Name != "" {
		target.Name = req.Name
	}
	if req.BaseURL != nil {
		target.BaseURL = req.BaseURL
	}
	if req.Hostname != nil {
		target.Hostname = req.Hostname
	}
	if req.Type != "" {
		target.Type = req.Type
	}
	target.UpdatedAt = time.Now()

	h.db.Save(&target)
	h.db.Preload("Project").Preload("Verifier").First(&target, target.ID)

	c.JSON(http.StatusOK, gin.H{
		"target": target,
	})
}

// Verify handles POST /api/targets/:id/verify
func (h *TargetHandler) Verify(c *gin.Context) {
	idStr := c.Param("id")
	targetID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req struct {
		VerificationStatus string `json:"verification_status" binding:"required"`
	}
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Status verifikasi diperlukan."})
		return
	}

	now := time.Now()
	var verifiedBy *uint64
	var verifiedAt *time.Time
	if req.VerificationStatus == "verified" {
		verifiedBy = &user.ID
		verifiedAt = &now
	}

	if err := h.db.Model(&models.Target{}).Where("id = ?", targetID).Updates(map[string]interface{}{
		"verification_status": req.VerificationStatus,
		"verified_at":         verifiedAt,
		"verified_by":         verifiedBy,
		"updated_at":          now,
	}).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal memperbarui status verifikasi target."})
		return
	}

	var target models.Target
	h.db.Preload("Project").Preload("Verifier").First(&target, targetID)

	c.JSON(http.StatusOK, gin.H{
		"target": target,
	})
}

// Destroy handles DELETE /api/targets/:id
func (h *TargetHandler) Destroy(c *gin.Context) {
	idStr := c.Param("id")
	targetID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var target models.Target
	if err := h.db.First(&target, targetID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Target tidak ditemukan."})
		return
	}

	h.db.Delete(&models.Target{}, targetID)

	c.JSON(http.StatusOK, gin.H{
		"message": fmt.Sprintf("Target '%s' berhasil dihapus.", target.Name),
	})
}

// Scan handles POST /api/targets/:id/scan
func (h *TargetHandler) Scan(c *gin.Context) {
	idStr := c.Param("id")
	targetID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var target models.Target
	if err := h.db.Preload("Project").First(&target, targetID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Target tidak ditemukan."})
		return
	}

	if target.VerificationStatus != "verified" {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": "Target belum diverifikasi oleh Security Administrator. Pemindaian ditolak.",
		})
		return
	}

	// 1. Choose ScanProfile based on target type
	profileKey := "web_safe_scan"
	switch strings.ToLower(target.Type) {
	case "container":
		profileKey = "container_security_scan"
	case "mobile", "app":
		profileKey = "mobile_app_scan"
	case "api":
		profileKey = "api_safe_scan"
	}

	var profile models.ScanProfile
	if err := h.db.Where("`key` = ? AND is_active = ?", profileKey, true).First(&profile).Error; err != nil {
		if err := h.db.Where("is_active = ?", true).First(&profile).Error; err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"message": "Tidak ada scan profile yang aktif."})
			return
		}
	}

	// 2. Scope
	scopePattern := "*"
	if target.BaseURL != nil && *target.BaseURL != "" {
		scopePattern = *target.BaseURL
	} else if target.Hostname != nil && *target.Hostname != "" {
		scopePattern = *target.Hostname
	}

	scopeType := "url"
	switch strings.ToLower(target.Type) {
	case "container":
		scopeType = "container_image"
	case "mobile", "app":
		scopeType = "mobile_app"
	case "api":
		scopeType = "api_route"
	}

	var scope models.Scope
	if err := h.db.Where("project_id = ? AND target_id = ?", target.ProjectID, target.ID).First(&scope).Error; err != nil {
		scope = models.Scope{
			ProjectID: target.ProjectID,
			TargetID:  &target.ID,
			Type:      scopeType,
			Pattern:   scopePattern,
			Effect:    "allow",
			Status:    "active",
			CreatedBy: user.ID,
			CreatedAt: time.Now(),
			UpdatedAt: time.Now(),
		}
		reason := "Scope dibuat otomatis untuk pemindaian instan target."
		scope.Reason = &reason
		h.db.Create(&scope)
	}

	// 3. Authorization
	now := time.Now()
	authCode := fmt.Sprintf("AUTH-%s-TGT-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))

	scopeSnapshot, _ := json.Marshal([]gin.H{
		{"id": scope.ID, "type": scope.Type, "pattern": scope.Pattern, "effect": scope.Effect, "target_id": target.ID},
	})
	policySnapshot, _ := json.Marshal(gin.H{
		"scan_profile":    gin.H{"id": profile.ID, "key": profile.Key, "name": profile.Name, "active_testing": profile.ActiveTesting},
		"project":         gin.H{"id": target.ProjectID, "code": target.Project.Code, "criticality": target.Project.Criticality, "status": target.Project.Status},
		"target_verified": true,
		"source":          "instant_target_scan",
	})
	notes := "Pemindaian instan dari Manajemen Target."

	auth := models.Authorization{
		Code:                 authCode,
		ProjectID:            target.ProjectID,
		TargetID:             &target.ID,
		ScanProfileID:        profile.ID,
		RequestedBy:          user.ID,
		ApprovedBy:           &user.ID,
		Status:               "active",
		ValidFrom:            now.Add(-1 * time.Minute),
		MaxConcurrency:       1,
		RateLimitPerMinute:   60,
		AllowedEngines:       profile.EngineKeys,
		AllowedScopeSnapshot: string(scopeSnapshot),
		DeniedScopeSnapshot:  nil,
		PolicySnapshot:       string(policySnapshot),
		Notes:                &notes,
		CreatedAt:            now,
		UpdatedAt:            now,
	}
	h.db.Create(&auth)

	// 4. Create ScanJob
	jobCode := fmt.Sprintf("SCAN-%s-TGT-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))
	var engineKeys []string
	_ = json.Unmarshal([]byte(profile.EngineKeys), &engineKeys)
	var enginePlan []gin.H
	for _, k := range engineKeys {
		enginePlan = append(enginePlan, gin.H{"engine_key": k, "status": "queued"})
	}
	enginePlanBytes, _ := json.Marshal(enginePlan)

	scanJob := models.ScanJob{
		Code:                    jobCode,
		ProjectID:               target.ProjectID,
		TargetID:                &target.ID,
		ScanProfileID:           profile.ID,
		AuthorizationID:         auth.ID,
		CreatedBy:               user.ID,
		Status:                  "queued",
		Progress:                0,
		Attempt:                 0,
		QueuedAt:                &now,
		EnginePlan:              string(enginePlanBytes),
		ExecutionPolicySnapshot: &auth.PolicySnapshot,
		CreatedAt:               now,
		UpdatedAt:               now,
	}
	if err := h.db.Create(&scanJob).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal membuat scan job."})
		return
	}

	// 5. Dispatch to background queue worker
	_ = services.DispatchRunScanJob(h.db, scanJob.ID, user.ID)

	h.db.Preload("Project").Preload("Target").Preload("ScanProfile").First(&scanJob, scanJob.ID)

	c.JSON(http.StatusCreated, gin.H{
		"message":  fmt.Sprintf("Pemindaian untuk target %s berhasil dimulai di latar belakang!", target.Name),
		"scan_job": scanJob,
	})
}

