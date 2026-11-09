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

type ScanJobHandler struct {
	db *gorm.DB
}

func NewScanJobHandler(db *gorm.DB) *ScanJobHandler {
	return &ScanJobHandler{db: db}
}

// List handles GET /api/scan-jobs
func (h *ScanJobHandler) List(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.ScanJob{}).
		Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		Preload("Authorization").
		Preload("ScanRuns").
		Order("id DESC")

	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		if roleName != "super_admin" && roleName != "security_admin" {
			var assignedIDs []uint64
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &assignedIDs)
			query = query.Where("project_id IN (?) OR created_by = ?", assignedIDs, user.ID)
		}
	}

	var scanJobs []models.ScanJob
	if err := query.Find(&scanJobs).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar scan jobs."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"scan_jobs": scanJobs,
	})
}

// Store handles POST /api/scan-jobs
func (h *ScanJobHandler) Store(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req struct {
		ProjectID       uint64  `json:"project_id" binding:"required"`
		RepositoryID    *uint64 `json:"repository_id"`
		TargetID        *uint64 `json:"target_id"`
		ScanProfileID   uint64  `json:"scan_profile_id" binding:"required"`
		AuthorizationID uint64  `json:"authorization_id" binding:"required"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid: " + err.Error()})
		return
	}

	var profile models.ScanProfile
	if err := h.db.First(&profile, req.ScanProfileID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Scan Profile tidak ditemukan."})
		return
	}

	now := time.Now()
	jobCode := fmt.Sprintf("SCAN-%s-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))

	var engineKeys []string
	_ = json.Unmarshal([]byte(profile.EngineKeys), &engineKeys)
	var enginePlan []gin.H
	for _, k := range engineKeys {
		enginePlan = append(enginePlan, gin.H{"engine_key": k, "status": "queued"})
	}
	enginePlanBytes, _ := json.Marshal(enginePlan)

	var auth models.Authorization
	h.db.First(&auth, req.AuthorizationID)
	policySnap := auth.PolicySnapshot
	if policySnap == "" {
		policySnap = "{}"
	}

	scanJob := models.ScanJob{
		Code:                    jobCode,
		ProjectID:               req.ProjectID,
		RepositoryID:            req.RepositoryID,
		TargetID:                req.TargetID,
		ScanProfileID:           req.ScanProfileID,
		AuthorizationID:         req.AuthorizationID,
		CreatedBy:               user.ID,
		Status:                  "queued",
		Progress:                0,
		Attempt:                 0,
		QueuedAt:                &now,
		EnginePlan:              string(enginePlanBytes),
		ExecutionPolicySnapshot: &policySnap,
		CreatedAt:               now,
		UpdatedAt:               now,
	}

	if err := h.db.Create(&scanJob).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal membuat scan job."})
		return
	}

	// Dispatch to worker
	_ = services.DispatchRunScanJob(h.db, scanJob.ID, user.ID)

	h.db.Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		Preload("Authorization").
		First(&scanJob, scanJob.ID)

	c.JSON(http.StatusCreated, gin.H{
		"scan_job": scanJob,
	})
}

// Rerun handles POST /api/scan-jobs/:id/rerun
func (h *ScanJobHandler) Rerun(c *gin.Context) {
	idStr := c.Param("id")
	jobID, err := strconv.ParseUint(idStr, 10, 64)
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

	var orig models.ScanJob
	if err := h.db.First(&orig, jobID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Scan Job tidak ditemukan."})
		return
	}

	now := time.Now()
	jobCode := fmt.Sprintf("SCAN-%s-RERUN-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))

	policySnap := "{}"
	if orig.ExecutionPolicySnapshot != nil && *orig.ExecutionPolicySnapshot != "" {
		policySnap = *orig.ExecutionPolicySnapshot
	}

	newJob := models.ScanJob{
		Code:                    jobCode,
		ProjectID:               orig.ProjectID,
		RepositoryID:            orig.RepositoryID,
		TargetID:                orig.TargetID,
		ScanProfileID:           orig.ScanProfileID,
		AuthorizationID:         orig.AuthorizationID,
		CreatedBy:               user.ID,
		Status:                  "queued",
		Progress:                0,
		Attempt:                 0,
		QueuedAt:                &now,
		EnginePlan:              orig.EnginePlan,
		ExecutionPolicySnapshot: &policySnap,
		CreatedAt:               now,
		UpdatedAt:               now,
	}

	if err := h.db.Create(&newJob).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menjalankan ulang scan job."})
		return
	}

	_ = services.DispatchRunScanJob(h.db, newJob.ID, user.ID)

	h.db.Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		Preload("Authorization").
		First(&newJob, newJob.ID)

	c.JSON(http.StatusCreated, gin.H{
		"scan_job": newJob,
	})
}

// ProcessSimulated handles POST /api/scan-jobs/:id/process-simulated
func (h *ScanJobHandler) ProcessSimulated(c *gin.Context) {
	idStr := c.Param("id")
	jobID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	val, exists := c.Get("user")
	var userID uint64 = 1
	if exists && val != nil {
		userID = val.(*models.User).ID
	}

	_ = services.DispatchRunScanJob(h.db, jobID, userID)

	var scanJob models.ScanJob
	h.db.Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		Preload("Authorization").
		First(&scanJob, jobID)

	c.JSON(http.StatusOK, gin.H{
		"scan_job": scanJob,
	})
}

// MyScanRequests handles GET /api/my/scan-requests
func (h *ScanJobHandler) MyScanRequests(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.ScanJob{}).
		Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		Preload("Authorization").
		Preload("ScanRuns").
		Preload("Creator").
		Preload("Creator.Role").
		Order("id DESC")

	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		if roleName != "super_admin" && roleName != "security_admin" {
			var assignedIDs []uint64
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &assignedIDs)
			query = query.Where("created_by = ? OR project_id IN (?)", user.ID, assignedIDs)
		}
	}

	var scanJobs []models.ScanJob
	if err := query.Find(&scanJobs).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar permohonan scan."})
		return
	}

	type RequestOutput struct {
		models.ScanJob
		CreatorName    string `json:"creator_name"`
		CreatorEmail   string `json:"creator_email"`
		CreatorRole    string `json:"creator_role"`
		CreatorIsAdmin bool   `json:"creator_is_admin"`
	}

	var output []RequestOutput
	for _, j := range scanJobs {
		var name, email, role string
		var isAdmin bool
		if j.Creator != nil {
			name = j.Creator.Name
			email = j.Creator.Email
			if j.Creator.Role.Name != "" {
				role = j.Creator.Role.Name
				isAdmin = (role == "super_admin" || role == "security_admin")
			}
		}
		output = append(output, RequestOutput{
			ScanJob:        j,
			CreatorName:    name,
			CreatorEmail:   email,
			CreatorRole:    role,
			CreatorIsAdmin: isAdmin,
		})
	}

	c.JSON(http.StatusOK, gin.H{
		"scan_requests": output,
	})
}

// StoreMyScanRequest handles POST /api/my/scan-requests
func (h *ScanJobHandler) StoreMyScanRequest(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)
	roleName := strings.ToLower(user.Role.Name)
	isAdmin := (roleName == "super_admin" || roleName == "security_admin")

	var req struct {
		ScanType      string  `json:"scan_type" binding:"required"`
		ProjectName   string  `json:"project_name" binding:"required"`
		AssetURL      string  `json:"asset_url"`
		DefaultBranch string  `json:"default_branch"`
		Notes         *string `json:"notes"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid: " + err.Error()})
		return
	}

	now := time.Now()

	// 1. Find or create Project
	var project models.Project
	if err := h.db.Where("name = ?", req.ProjectName).First(&project).Error; err != nil {
		code := strings.ToUpper(strings.ReplaceAll(req.ProjectName, " ", "-"))
		if len(code) > 20 {
			code = code[:20]
		}
		code = fmt.Sprintf("%s-%04d", code, rand.Intn(9999))
		desc := fmt.Sprintf("Proyek dibuat otomatis dari permohonan scan: %s", req.ProjectName)

		project = models.Project{
			Name:        req.ProjectName,
			Code:        code,
			Description: &desc,
			Criticality: "medium",
			Status:      "active",
			OwnerID:     user.ID,
			CreatedAt:   now,
			UpdatedAt:   now,
		}
		h.db.Create(&project)
	}

	// 2. Identify Scan Profile
	profileKey := "source_code_scan"
	switch req.ScanType {
	case "web":
		profileKey = "web_safe_scan"
	case "api":
		profileKey = "api_safe_scan"
	case "container":
		profileKey = "container_security_scan"
	case "mobile":
		profileKey = "mobile_app_scan"
	}

	var profile models.ScanProfile
	if err := h.db.Where("`key` = ? AND is_active = ?", profileKey, true).First(&profile).Error; err != nil {
		h.db.Where("is_active = ?", true).First(&profile)
	}

	// 3. Create or find Asset (Repository or Target)
	var repoID *uint64
	var targetID *uint64

	if req.ScanType == "repository" {
		var repo models.Repository
		if err := h.db.Where("project_id = ? AND url = ?", project.ID, req.AssetURL).First(&repo).Error; err != nil {
			branch := req.DefaultBranch
			if branch == "" {
				branch = "main"
			}
			verification := "pending"
			if isAdmin {
				verification = "verified"
			}
			repo = models.Repository{
				ProjectID:          project.ID,
				Provider:           "git",
				Name:               req.ProjectName,
				URL:                req.AssetURL,
				DefaultBranch:      branch,
				VerificationStatus: verification,
				CreatedAt:          now,
				UpdatedAt:          now,
			}
			if isAdmin {
				repo.VerifiedBy = &user.ID
				repo.VerifiedAt = &now
			}
			h.db.Create(&repo)
		}
		repoID = &repo.ID
	} else {
		var target models.Target
		if err := h.db.Where("project_id = ? AND (base_url = ? OR hostname = ?)", project.ID, req.AssetURL, req.AssetURL).First(&target).Error; err != nil {
			verification := "pending"
			if isAdmin {
				verification = "verified"
			}
			target = models.Target{
				ProjectID:          project.ID,
				Type:               req.ScanType,
				Name:               req.ProjectName,
				BaseURL:            &req.AssetURL,
				Hostname:           &req.AssetURL,
				VerificationStatus: verification,
				CreatedAt:          now,
				UpdatedAt:          now,
			}
			if isAdmin {
				target.VerifiedBy = &user.ID
				target.VerifiedAt = &now
			}
			h.db.Create(&target)
		}
		targetID = &target.ID
	}

	// 4. Create Scope
	scopeType := req.ScanType
	if scopeType == "web" {
		scopeType = "url"
	}
	var scope models.Scope
	scope = models.Scope{
		ProjectID: project.ID,
		TargetID:  targetID,
		Type:      scopeType,
		Pattern:   req.AssetURL,
		Effect:    "allow",
		Status:    "active",
		CreatedBy: user.ID,
		CreatedAt: now,
		UpdatedAt: now,
	}
	h.db.Create(&scope)

	// 5. Authorization
	authStatus := "pending"
	if isAdmin {
		authStatus = "active"
	}
	authCode := fmt.Sprintf("AUTH-%s-REQ-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))
	scopeSnapshot, _ := json.Marshal([]gin.H{
		{"id": scope.ID, "type": scope.Type, "pattern": scope.Pattern, "effect": scope.Effect},
	})
	policySnapshot, _ := json.Marshal(gin.H{
		"scan_profile": gin.H{"id": profile.ID, "key": profile.Key, "name": profile.Name, "active_testing": profile.ActiveTesting},
		"project":      gin.H{"id": project.ID, "code": project.Code},
	})

	var approvedBy *uint64
	if isAdmin {
		approvedBy = &user.ID
	}

	auth := models.Authorization{
		Code:                 authCode,
		ProjectID:            project.ID,
		RepositoryID:         repoID,
		TargetID:             targetID,
		ScanProfileID:        profile.ID,
		RequestedBy:          user.ID,
		ApprovedBy:           approvedBy,
		Status:               authStatus,
		ValidFrom:            now,
		MaxConcurrency:       1,
		RateLimitPerMinute:   60,
		AllowedEngines:       profile.EngineKeys,
		AllowedScopeSnapshot: string(scopeSnapshot),
		PolicySnapshot:       string(policySnapshot),
		Notes:                req.Notes,
		CreatedAt:            now,
		UpdatedAt:            now,
	}
	h.db.Create(&auth)

	// 6. ScanJob
	jobStatus := "pending_approval"
	if isAdmin {
		jobStatus = "queued"
	}

	var engineKeys []string
	_ = json.Unmarshal([]byte(profile.EngineKeys), &engineKeys)
	var enginePlan []gin.H
	for _, k := range engineKeys {
		enginePlan = append(enginePlan, gin.H{"engine_key": k, "status": "queued"})
	}
	enginePlanBytes, _ := json.Marshal(enginePlan)

	jobCode := fmt.Sprintf("SCAN-%s-REQ-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))
	scanJob := models.ScanJob{
		Code:                    jobCode,
		ProjectID:               project.ID,
		RepositoryID:            repoID,
		TargetID:                targetID,
		ScanProfileID:           profile.ID,
		AuthorizationID:         auth.ID,
		CreatedBy:               user.ID,
		Status:                  jobStatus,
		Progress:                0,
		Attempt:                 0,
		EnginePlan:              string(enginePlanBytes),
		ExecutionPolicySnapshot: &auth.PolicySnapshot,
		CreatedAt:               now,
		UpdatedAt:               now,
	}
	if isAdmin {
		scanJob.QueuedAt = &now
	}
	h.db.Create(&scanJob)

	if isAdmin {
		_ = services.DispatchRunScanJob(h.db, scanJob.ID, user.ID)
	}

	h.db.Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		Preload("Authorization").
		First(&scanJob, scanJob.ID)

	c.JSON(http.StatusCreated, gin.H{
		"message":      "Permohonan scan berhasil diajukan.",
		"scan_request": scanJob,
	})
}

// ApproveMyScanRequest handles POST /api/my/scan-requests/:id/approve
func (h *ScanJobHandler) ApproveMyScanRequest(c *gin.Context) {
	idStr := c.Param("id")
	jobID, err := strconv.ParseUint(idStr, 10, 64)
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

	var scanJob models.ScanJob
	if err := h.db.First(&scanJob, jobID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Permohonan scan tidak ditemukan."})
		return
	}

	now := time.Now()
	// Update Authorization to active
	if scanJob.AuthorizationID != 0 {
		h.db.Model(&models.Authorization{}).Where("id = ?", scanJob.AuthorizationID).Updates(map[string]interface{}{
			"status":      "active",
			"approved_by": user.ID,
			"valid_from":  now,
			"updated_at":  now,
		})
	}

	// Update Asset verification
	if scanJob.RepositoryID != nil {
		h.db.Model(&models.Repository{}).Where("id = ?", *scanJob.RepositoryID).Updates(map[string]interface{}{
			"verification_status": "verified",
			"verified_at":         now,
			"verified_by":         user.ID,
		})
	}
	if scanJob.TargetID != nil {
		h.db.Model(&models.Target{}).Where("id = ?", *scanJob.TargetID).Updates(map[string]interface{}{
			"verification_status": "verified",
			"verified_at":         now,
			"verified_by":         user.ID,
		})
	}

	// Update ScanJob status to queued
	scanJob.Status = "queued"
	scanJob.QueuedAt = &now
	scanJob.UpdatedAt = now
	h.db.Save(&scanJob)

	_ = services.DispatchRunScanJob(h.db, scanJob.ID, user.ID)

	h.db.Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		Preload("Authorization").
		First(&scanJob, scanJob.ID)

	c.JSON(http.StatusOK, gin.H{
		"message":      "Permohonan scan berhasil disetujui dan telah dimasukkan ke dalam antrean eksekusi.",
		"scan_request": scanJob,
	})
}

// RejectMyScanRequest handles POST /api/my/scan-requests/:id/reject
func (h *ScanJobHandler) RejectMyScanRequest(c *gin.Context) {
	idStr := c.Param("id")
	jobID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var req struct {
		Reason string `json:"reason"`
	}
	_ = c.ShouldBindJSON(&req)

	reason := req.Reason
	if reason == "" {
		reason = "Permohonan ditolak oleh Security Administrator."
	}

	now := time.Now()
	h.db.Model(&models.ScanJob{}).Where("id = ?", jobID).Updates(map[string]interface{}{
		"status":         "rejected",
		"failure_reason": reason,
		"cancelled_at":   now,
		"updated_at":     now,
	})

	c.JSON(http.StatusOK, gin.H{
		"message": "Permohonan scan berhasil ditolak.",
	})
}

