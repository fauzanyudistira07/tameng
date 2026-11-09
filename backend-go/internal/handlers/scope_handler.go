package handlers

import (
	"net/http"
	"strconv"
	"strings"
	"time"

	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type ScopeHandler struct {
	db *gorm.DB
}

func NewScopeHandler(db *gorm.DB) *ScopeHandler {
	return &ScopeHandler{db: db}
}

// List handles GET /api/scopes
func (h *ScopeHandler) List(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.Scope{}).
		Preload("Project").
		Preload("Target").
		Preload("Creator").
		Order("id DESC")

	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		if roleName != "super_admin" && roleName != "security_admin" {
			var assignedIDs []uint64
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &assignedIDs)
			query = query.Where("project_id IN (?) OR created_by = ?", assignedIDs, user.ID)
		}
	}

	var scopes []models.Scope
	if err := query.Find(&scopes).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar scope."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"scopes": scopes,
	})
}

// Store handles POST /api/scopes
func (h *ScopeHandler) Store(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req struct {
		ProjectID uint64  `json:"project_id" binding:"required"`
		TargetID  *uint64 `json:"target_id"`
		Type      string  `json:"type" binding:"required"`
		Pattern   string  `json:"pattern" binding:"required"`
		Effect    string  `json:"effect" binding:"required"`
		Status    string  `json:"status" binding:"required"`
		Reason    *string `json:"reason"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid: " + err.Error()})
		return
	}

	now := time.Now()
	scope := models.Scope{
		ProjectID: req.ProjectID,
		TargetID:  req.TargetID,
		Type:      req.Type,
		Pattern:   req.Pattern,
		Effect:    req.Effect,
		Status:    req.Status,
		Reason:    req.Reason,
		CreatedBy: user.ID,
		CreatedAt: now,
		UpdatedAt: now,
	}

	if err := h.db.Create(&scope).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menyimpan scope."})
		return
	}

	h.db.Preload("Project").Preload("Target").Preload("Creator").First(&scope, scope.ID)

	c.JSON(http.StatusCreated, gin.H{
		"scope": scope,
	})
}

// Update handles PUT /api/scopes/:id
func (h *ScopeHandler) Update(c *gin.Context) {
	idStr := c.Param("id")
	scopeID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var scope models.Scope
	if err := h.db.First(&scope, scopeID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Scope tidak ditemukan."})
		return
	}

	var req struct {
		ProjectID uint64  `json:"project_id"`
		TargetID  *uint64 `json:"target_id"`
		Type      string  `json:"type"`
		Pattern   string  `json:"pattern"`
		Effect    string  `json:"effect"`
		Status    string  `json:"status"`
		Reason    *string `json:"reason"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid: " + err.Error()})
		return
	}

	if req.ProjectID != 0 {
		scope.ProjectID = req.ProjectID
	}
	if req.TargetID != nil {
		scope.TargetID = req.TargetID
	}
	if req.Type != "" {
		scope.Type = req.Type
	}
	if req.Pattern != "" {
		scope.Pattern = req.Pattern
	}
	if req.Effect != "" {
		scope.Effect = req.Effect
	}
	if req.Status != "" {
		scope.Status = req.Status
	}
	if req.Reason != nil {
		scope.Reason = req.Reason
	}
	scope.UpdatedAt = time.Now()

	h.db.Save(&scope)
	h.db.Preload("Project").Preload("Target").Preload("Creator").First(&scope, scope.ID)

	c.JSON(http.StatusOK, gin.H{
		"scope": scope,
	})
}

