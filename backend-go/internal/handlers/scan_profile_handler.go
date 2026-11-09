package handlers

import (
	"encoding/json"
	"net/http"

	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type ScanProfileHandler struct {
	db *gorm.DB
}

func NewScanProfileHandler(db *gorm.DB) *ScanProfileHandler {
	return &ScanProfileHandler{db: db}
}

// List handles GET /api/scan-profiles
func (h *ScanProfileHandler) List(c *gin.Context) {
	var rawProfiles []models.ScanProfile
	if err := h.db.Where("is_active = ?", true).Order("id ASC").Find(&rawProfiles).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar scan profile."})
		return
	}

	type ProfileOutput struct {
		ID                 uint64          `json:"id"`
		Key                string          `json:"key"`
		Name               string          `json:"name"`
		Description        *string         `json:"description"`
		AllowedTargetTypes json.RawMessage `json:"allowed_target_types"`
		EngineKeys         json.RawMessage `json:"engine_keys"`
		Policy             json.RawMessage `json:"policy"`
		ActiveTesting      bool            `json:"active_testing"`
		IsActive           bool            `json:"is_active"`
	}

	var profiles []ProfileOutput
	for _, p := range rawProfiles {
		profiles = append(profiles, ProfileOutput{
			ID:                 p.ID,
			Key:                p.Key,
			Name:               p.Name,
			Description:        p.Description,
			AllowedTargetTypes: json.RawMessage(p.AllowedTargetTypes),
			EngineKeys:         json.RawMessage(p.EngineKeys),
			Policy:             json.RawMessage(p.Policy),
			ActiveTesting:      p.ActiveTesting,
			IsActive:           p.IsActive,
		})
	}

	c.JSON(http.StatusOK, gin.H{
		"scan_profiles": profiles,
	})
}

