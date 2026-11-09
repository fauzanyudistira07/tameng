package handlers

import (
	"net/http"
	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type RoleHandler struct {
	db *gorm.DB
}

func NewRoleHandler(db *gorm.DB) *RoleHandler {
	return &RoleHandler{db: db}
}

// List handles GET /api/roles
func (h *RoleHandler) List(c *gin.Context) {
	var roles []models.Role
	if err := h.db.Select("id", "name", "display_name", "description").Order("id ASC").Find(&roles).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar peran."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"roles": roles,
	})
}

