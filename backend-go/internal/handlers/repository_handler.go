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

type RepositoryHandler struct {
	db *gorm.DB
}

func NewRepositoryHandler(db *gorm.DB) *RepositoryHandler {
	return &RepositoryHandler{db: db}
}

// List handles GET /api/repositories
func (h *RepositoryHandler) List(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.Repository{}).
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

	var repos []models.Repository
	if err := query.Find(&repos).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar repositori."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"repositories": repos,
	})
}

// Show handles GET /api/repositories/:id
func (h *RepositoryHandler) Show(c *gin.Context) {
	idStr := c.Param("id")
	repoID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var repo models.Repository
	if err := h.db.Preload("Project").Preload("Verifier").First(&repo, repoID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Repositori tidak ditemukan."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"repository": repo,
	})
}

// Store handles POST /api/repositories
func (h *RepositoryHandler) Store(c *gin.Context) {
	var req struct {
		ProjectID     uint64 `json:"project_id" binding:"required"`
		Provider      string `json:"provider"`
		Name          string `json:"name" binding:"required"`
		URL           string `json:"url" binding:"required"`
		DefaultBranch string `json:"default_branch"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid."})
		return
	}

	if req.Provider == "" {
		req.Provider = "git"
	}
	if req.DefaultBranch == "" {
		req.DefaultBranch = "main"
	}

	now := time.Now()
	repo := models.Repository{
		ProjectID:          req.ProjectID,
		Provider:           req.Provider,
		Name:               req.Name,
		URL:                req.URL,
		DefaultBranch:      req.DefaultBranch,
		VerificationStatus: "pending",
		CreatedAt:          now,
		UpdatedAt:          now,
	}

	if err := h.db.Create(&repo).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menyimpan repositori."})
		return
	}

	h.db.Preload("Project").First(&repo, repo.ID)

	c.JSON(http.StatusCreated, gin.H{
		"message":    "Repositori berhasil ditambahkan.",
		"repository": repo,
	})
}

// Update handles PUT /api/repositories/:id
func (h *RepositoryHandler) Update(c *gin.Context) {
	idStr := c.Param("id")
	repoID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var repo models.Repository
	if err := h.db.First(&repo, repoID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Repositori tidak ditemukan."})
		return
	}

	var req struct {
		Name          string `json:"name"`
		URL           string `json:"url"`
		DefaultBranch string `json:"default_branch"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid."})
		return
	}

	if req.Name != "" {
		repo.Name = req.Name
	}
	if req.URL != "" {
		repo.URL = req.URL
	}
	if req.DefaultBranch != "" {
		repo.DefaultBranch = req.DefaultBranch
	}
	repo.UpdatedAt = time.Now()

	h.db.Save(&repo)
	h.db.Preload("Project").First(&repo, repo.ID)

	c.JSON(http.StatusOK, gin.H{
		"message":    "Repositori berhasil diperbarui.",
		"repository": repo,
	})
}

// Verify handles POST /api/repositories/:id/verify
func (h *RepositoryHandler) Verify(c *gin.Context) {
	idStr := c.Param("id")
	repoID, err := strconv.ParseUint(idStr, 10, 64)
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

	now := time.Now()
	if err := h.db.Model(&models.Repository{}).Where("id = ?", repoID).Updates(map[string]interface{}{
		"verification_status": "verified",
		"verified_at":         &now,
		"verified_by":         user.ID,
		"updated_at":          now,
	}).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal memverifikasi repositori."})
		return
	}

	var repo models.Repository
	h.db.Preload("Project").Preload("Verifier").First(&repo, repoID)

	c.JSON(http.StatusOK, gin.H{
		"message":    "Repositori berhasil diverifikasi.",
		"repository": repo,
	})
}

// Destroy handles DELETE /api/repositories/:id
func (h *RepositoryHandler) Destroy(c *gin.Context) {
	idStr := c.Param("id")
	repoID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	h.db.Delete(&models.Repository{}, repoID)

	c.JSON(http.StatusOK, gin.H{
		"message": "Repositori berhasil dihapus.",
	})
}

// Scan handles POST /api/repositories/:id/scan
func (h *RepositoryHandler) Scan(c *gin.Context) {
	idStr := c.Param("id")
	repoID, err := strconv.ParseUint(idStr, 10, 64)
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

	var repo models.Repository
	if err := h.db.Preload("Project").First(&repo, repoID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Repositori tidak ditemukan."})
		return
	}

	if repo.VerificationStatus != "verified" {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": "Repositori belum diverifikasi oleh Security Administrator. Pemindaian ditolak.",
		})
		return
	}

	// 1. Get or create scope
	var scope models.Scope
	if err := h.db.Where("project_id = ? AND pattern = ?", repo.ProjectID, repo.URL).First(&scope).Error; err != nil {
		scope = models.Scope{
			ProjectID: repo.ProjectID,
			Type:      "repository",
			Pattern:   repo.URL,
			Effect:    "allow",
			Status:    "active",
			CreatedBy: user.ID,
			CreatedAt: time.Now(),
			UpdatedAt: time.Now(),
		}
		reason := "Scope dibuat otomatis untuk pemindaian instan repositori."
		scope.Reason = &reason
		h.db.Create(&scope)
	}

	// 2. Scan Profile
	var profile models.ScanProfile
	if err := h.db.Where("`key` = ? AND is_active = ?", "source_code_scan", true).First(&profile).Error; err != nil {
		if err := h.db.Where("is_active = ?", true).First(&profile).Error; err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"message": "Tidak ada scan profile yang aktif."})
			return
		}
	}

	// 3. Authorization
	now := time.Now()
	authCode := fmt.Sprintf("AUTH-%s-REP-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))

	scopeSnapshot, _ := json.Marshal([]gin.H{
		{"id": scope.ID, "type": scope.Type, "pattern": scope.Pattern, "effect": scope.Effect},
	})
	policySnapshot, _ := json.Marshal(gin.H{
		"scan_profile": gin.H{"id": profile.ID, "key": profile.Key, "name": profile.Name, "active_testing": profile.ActiveTesting},
		"project":      gin.H{"id": repo.ProjectID, "code": repo.Project.Code, "criticality": repo.Project.Criticality, "status": repo.Project.Status},
		"source":       "instant_repository_scan",
	})
	notes := "Pemindaian instan dari Manajemen Repositori."

	auth := models.Authorization{
		Code:                 authCode,
		ProjectID:            repo.ProjectID,
		RepositoryID:         &repo.ID,
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
	jobCode := fmt.Sprintf("SCAN-%s-REP-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))
	var engineKeys []string
	_ = json.Unmarshal([]byte(profile.EngineKeys), &engineKeys)
	var enginePlan []gin.H
	for _, k := range engineKeys {
		enginePlan = append(enginePlan, gin.H{"engine_key": k, "status": "queued"})
	}
	enginePlanBytes, _ := json.Marshal(enginePlan)

	scanJob := models.ScanJob{
		Code:                jobCode,
		ProjectID:           repo.ProjectID,
		RepositoryID:        &repo.ID,
		ScanProfileID:       profile.ID,
		AuthorizationID:     auth.ID,
		CreatedBy:           user.ID,
		Status:              "queued",
		Progress:            0,
		Attempt:             0,
		QueuedAt:            &now,
		EnginePlan:          string(enginePlanBytes),
		CreatedAt:           now,
		UpdatedAt:           now,
	}
	if err := h.db.Create(&scanJob).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal membuat scan job."})
		return
	}

	// 5. Dispatch to background worker
	_ = services.DispatchRunScanJob(h.db, scanJob.ID, user.ID)

	h.db.Preload("Project").Preload("Repository").Preload("ScanProfile").First(&scanJob, scanJob.ID)

	c.JSON(http.StatusCreated, gin.H{
		"message":  fmt.Sprintf("Pemindaian untuk repositori %s berhasil dimulai di latar belakang!", repo.Name),
		"scan_job": scanJob,
	})
}
