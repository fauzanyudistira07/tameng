package handlers

import (
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type ProjectHandler struct {
	db *gorm.DB
}

func NewProjectHandler(db *gorm.DB) *ProjectHandler {
	return &ProjectHandler{db: db}
}

// List handles GET /api/projects
func (h *ProjectHandler) List(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.Project{}).
		Preload("Owner").
		Preload("Owner.Role").
		Preload("Authorizations", func(db *gorm.DB) *gorm.DB {
			return db.Order("id DESC")
		}).
		Order("id DESC")

	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		if roleName != "super_admin" && roleName != "security_admin" {
			var assignedIDs []uint64
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &assignedIDs)
			query = query.Where("id IN (?) OR owner_id = ?", assignedIDs, user.ID)
		}
	}

	var projects []models.Project
	if err := query.Find(&projects).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar proyek."})
		return
	}

	type ProjectOutput struct {
		models.Project
		ActiveAuthorization *models.Authorization `json:"active_authorization,omitempty"`
	}

	var output []ProjectOutput
	for _, p := range projects {
		var activeAuth *models.Authorization
		if len(p.Authorizations) > 0 {
			activeAuth = &p.Authorizations[0]
		}
		output = append(output, ProjectOutput{
			Project:             p,
			ActiveAuthorization: activeAuth,
		})
	}

	c.JSON(http.StatusOK, gin.H{
		"projects": output,
	})
}

// Show handles GET /api/projects/:id
func (h *ProjectHandler) Show(c *gin.Context) {
	idStr := c.Param("id")
	projectID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var project models.Project
	if err := h.db.Preload("Owner").First(&project, projectID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Proyek tidak ditemukan."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"project": project,
	})
}

// Store handles POST /api/projects
func (h *ProjectHandler) Store(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req struct {
		Name        string  `json:"name" binding:"required"`
		Code        string  `json:"code"`
		Description *string `json:"description"`
		Criticality string  `json:"criticality"`
		Status      string  `json:"status"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid."})
		return
	}

	if req.Code == "" {
		req.Code = fmt.Sprintf("PRJ-%s-%d", strings.ToUpper(strings.ReplaceAll(req.Name, " ", "")), time.Now().Unix())
	}
	if req.Criticality == "" {
		req.Criticality = "medium"
	}
	if req.Status == "" {
		req.Status = "active"
	}

	now := time.Now()
	project := models.Project{
		Name:        req.Name,
		Code:        req.Code,
		Description: req.Description,
		Criticality: req.Criticality,
		Status:      req.Status,
		OwnerID:     user.ID,
		CreatedAt:   now,
		UpdatedAt:   now,
	}

	if err := h.db.Create(&project).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menyimpan proyek baru.", "error": err.Error()})
		return
	}

	// Auto assign creator as lead in project_user
	h.db.Create(&models.ProjectUser{
		ProjectID:   project.ID,
		UserID:      user.ID,
		AccessLevel: "lead",
	})

	h.db.Preload("Owner").First(&project, project.ID)

	c.JSON(http.StatusCreated, gin.H{
		"message": "Proyek berhasil dibuat.",
		"project": project,
	})
}

// Update handles PUT /api/projects/:id
func (h *ProjectHandler) Update(c *gin.Context) {
	idStr := c.Param("id")
	projectID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var project models.Project
	if err := h.db.First(&project, projectID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Proyek tidak ditemukan."})
		return
	}

	var req struct {
		Name        string  `json:"name"`
		Code        string  `json:"code"`
		Description *string `json:"description"`
		Criticality string  `json:"criticality"`
		Status      string  `json:"status"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid."})
		return
	}

	if req.Name != "" {
		project.Name = req.Name
	}
	if req.Code != "" {
		project.Code = req.Code
	}
	if req.Description != nil {
		project.Description = req.Description
	}
	if req.Criticality != "" {
		project.Criticality = req.Criticality
	}
	if req.Status != "" {
		project.Status = req.Status
	}
	project.UpdatedAt = time.Now()

	h.db.Save(&project)
	h.db.Preload("Owner").First(&project, project.ID)

	c.JSON(http.StatusOK, gin.H{
		"message": "Proyek berhasil diperbarui.",
		"project": project,
	})
}

