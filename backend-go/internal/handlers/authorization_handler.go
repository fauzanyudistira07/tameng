package handlers

import (
	"encoding/json"
	"fmt"
	"math/rand"
	"net/http"
	"strings"
	"time"

	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type AuthorizationHandler struct {
	db *gorm.DB
}

func NewAuthorizationHandler(db *gorm.DB) *AuthorizationHandler {
	return &AuthorizationHandler{db: db}
}

// List handles GET /api/authorizations
func (h *AuthorizationHandler) List(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.Authorization{}).
		Preload("Project").
		Preload("Repository").
		Preload("Target").
		Preload("ScanProfile").
		Preload("Requester").
		Order("id DESC")

	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		if roleName != "super_admin" && roleName != "security_admin" {
			var assignedIDs []uint64
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &assignedIDs)
			query = query.Where("project_id IN (?) OR requested_by = ?", assignedIDs, user.ID)
		}
	}

	var authorizations []models.Authorization
	if err := query.Find(&authorizations).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar otorisasi."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"authorizations": authorizations,
	})
}

// Store handles POST /api/authorizations
func (h *AuthorizationHandler) Store(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req struct {
		ProjectID          uint64     `json:"project_id" binding:"required"`
		RepositoryID       *uint64    `json:"repository_id"`
		TargetID           *uint64    `json:"target_id"`
		ScanProfileID      uint64     `json:"scan_profile_id" binding:"required"`
		ValidFrom          *time.Time `json:"valid_from"`
		ValidUntil         *time.Time `json:"valid_until"`
		MaxConcurrency     uint32     `json:"max_concurrency"`
		RateLimitPerMinute uint32     `json:"rate_limit_per_minute"`
		Status             string     `json:"status" binding:"required"`
		Notes              *string    `json:"notes"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid: " + err.Error()})
		return
	}

	// 1. Fetch project and profile
	var project models.Project
	if err := h.db.First(&project, req.ProjectID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Proyek tidak ditemukan."})
		return
	}

	var profile models.ScanProfile
	if err := h.db.First(&profile, req.ScanProfileID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Scan Profile tidak ditemukan."})
		return
	}

	// 2. Fetch scopes
	var allowedScopes []models.Scope
	scopeQuery := h.db.Where("project_id = ? AND status = 'active'", req.ProjectID)
	if req.TargetID != nil {
		scopeQuery = scopeQuery.Where("target_id = ? OR target_id IS NULL", *req.TargetID)
	}
	scopeQuery.Where("effect = 'allow'").Find(&allowedScopes)

	if len(allowedScopes) == 0 {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": "Setidaknya satu allow scope yang aktif diperlukan sebelum membuat otorisasi.",
		})
		return
	}

	now := time.Now()
	validFrom := now
	if req.ValidFrom != nil {
		validFrom = *req.ValidFrom
	}

	concurrency := req.MaxConcurrency
	if concurrency == 0 {
		concurrency = 1
	}

	rateLimit := req.RateLimitPerMinute
	if rateLimit == 0 {
		rateLimit = 60
	}

	authCode := fmt.Sprintf("AUTH-%s-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))

	scopeSnapshotBytes, _ := json.Marshal(allowedScopes)
	policySnapshot, _ := json.Marshal(gin.H{
		"scan_profile": gin.H{"id": profile.ID, "key": profile.Key, "name": profile.Name, "active_testing": profile.ActiveTesting},
		"project":      gin.H{"id": project.ID, "code": project.Code, "criticality": project.Criticality, "status": project.Status},
	})

	auth := models.Authorization{
		Code:                 authCode,
		ProjectID:            req.ProjectID,
		RepositoryID:         req.RepositoryID,
		TargetID:             req.TargetID,
		ScanProfileID:        req.ScanProfileID,
		RequestedBy:          user.ID,
		ApprovedBy:           &user.ID,
		Status:               req.Status,
		ValidFrom:            validFrom,
		ValidUntil:           req.ValidUntil,
		MaxConcurrency:       concurrency,
		RateLimitPerMinute:   rateLimit,
		AllowedEngines:       profile.EngineKeys,
		AllowedScopeSnapshot: string(scopeSnapshotBytes),
		DeniedScopeSnapshot:  nil,
		PolicySnapshot:       string(policySnapshot),
		Notes:                req.Notes,
		CreatedAt:            now,
		UpdatedAt:            now,
	}

	if err := h.db.Create(&auth).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menyimpan otorisasi."})
		return
	}

	h.db.Preload("Project").Preload("Repository").Preload("Target").Preload("ScanProfile").First(&auth, auth.ID)

	c.JSON(http.StatusCreated, gin.H{
		"authorization": auth,
	})
}

