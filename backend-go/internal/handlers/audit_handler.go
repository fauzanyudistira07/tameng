package handlers

import (
	"net/http"
	"strings"
	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type AuditHandler struct {
	db *gorm.DB
}

func NewAuditHandler(db *gorm.DB) *AuditHandler {
	return &AuditHandler{db: db}
}

// List handles GET /api/audit-logs
func (h *AuditHandler) List(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	query := h.db.Model(&models.AuditLog{}).
		Preload("User").
		Order("id DESC").
		Limit(250)

	roleName := strings.ToLower(user.Role.Name)
	if roleName != "super_admin" && roleName != "security_admin" {
		var allowedProjectIDs []uint64
		h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &allowedProjectIDs)
		query = query.Where("user_id = ?", user.ID)
	}

	var logs []models.AuditLog
	if err := query.Find(&logs).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil log audit."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"audit_logs": logs,
	})
}

