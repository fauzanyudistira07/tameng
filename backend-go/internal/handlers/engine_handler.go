package handlers

import (
	"context"
	"fmt"
	"net/http"
	"os/exec"
	"strconv"
	"strings"
	"time"

	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type EngineHandler struct {
	db *gorm.DB
}

func NewEngineHandler(db *gorm.DB) *EngineHandler {
	return &EngineHandler{db: db}
}

// List handles GET /api/security/engines and GET /api/engines
func (h *EngineHandler) List(c *gin.Context) {
	query := h.db.Model(&models.SecurityEngine{}).Order("domain ASC, code ASC")

	if domain := c.Query("domain"); domain != "" {
		query = query.Where("domain = ?", strings.ToUpper(domain))
	}
	if resClass := c.Query("resource_class"); resClass != "" {
		query = query.Where("resource_class = ?", strings.ToUpper(resClass))
	}
	if enabled := c.Query("enabled"); enabled != "" {
		if b, err := strconv.ParseBool(enabled); err == nil {
			query = query.Where("enabled = ?", b)
		}
	}

	var engines []models.SecurityEngine
	if err := query.Find(&engines).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil data mesin keamanan."})
		return
	}

	// Breakdown statistics
	domainCounts := make(map[string]int)
	resourceCounts := make(map[string]int)
	activeCount := 0

	for _, e := range engines {
		if e.Enabled {
			activeCount++
		}
		domainCounts[e.Domain]++
		resourceCounts[e.ResourceClass]++
	}

	c.JSON(http.StatusOK, gin.H{
		"data": engines,
		"summary": gin.H{
			"total_engines":      len(engines),
			"active_engines":     activeCount,
			"domain_breakdown":   domainCounts,
			"resource_breakdown": resourceCounts,
		},
	})
}

// Show handles GET /api/security/engines/:id and GET /api/engines/:engineKey
func (h *EngineHandler) Show(c *gin.Context) {
	param := c.Param("id")
	if param == "" {
		param = c.Param("engineKey")
	}

	var engine models.SecurityEngine
	var err error
	if id, parseErr := strconv.ParseUint(param, 10, 64); parseErr == nil {
		err = h.db.First(&engine, id).Error
	} else {
		err = h.db.Where("code = ?", param).First(&engine).Error
	}

	if err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Mesin keamanan tidak ditemukan."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"data": engine,
	})
}

// Toggle handles POST /api/security/engines/:id/toggle
func (h *EngineHandler) Toggle(c *gin.Context) {
	param := c.Param("id")

	var engine models.SecurityEngine
	var err error
	if id, parseErr := strconv.ParseUint(param, 10, 64); parseErr == nil {
		err = h.db.First(&engine, id).Error
	} else {
		err = h.db.Where("code = ?", param).First(&engine).Error
	}

	if err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Mesin keamanan tidak ditemukan."})
		return
	}

	engine.Enabled = !engine.Enabled
	if engine.Enabled {
		engine.Status = "AVAILABLE"
	} else {
		engine.Status = "DISABLED"
	}
	engine.UpdatedAt = time.Now()

	h.db.Save(&engine)

	statusStr := "dinonaktifkan"
	if engine.Enabled {
		statusStr = "diaktifkan"
	}

	c.JSON(http.StatusOK, gin.H{
		"message": fmt.Sprintf("Security engine %s berhasil %s.", engine.Name, statusStr),
		"data":    engine,
	})
}

// HealthCheck handles POST /api/security/engines/:id/health-check
func (h *EngineHandler) HealthCheck(c *gin.Context) {
	param := c.Param("id")

	var engine models.SecurityEngine
	var err error
	if id, parseErr := strconv.ParseUint(param, 10, 64); parseErr == nil {
		err = h.db.First(&engine, id).Error
	} else {
		err = h.db.Where("code = ?", param).First(&engine).Error
	}

	if err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Mesin keamanan tidak ditemukan."})
		return
	}

	now := time.Now()
	engine.LastHealthCheck = &now

	if engine.ContainerImage == "" {
		engine.Status = "AVAILABLE"
		h.db.Save(&engine)
		c.JSON(http.StatusOK, gin.H{
			"message": fmt.Sprintf("Mesin %s menggunakan binary host dan siap digunakan.", engine.Name),
			"data": gin.H{
				"engine":            engine.Code,
				"status":            engine.Status,
				"image_present":     true,
				"last_health_check": engine.LastHealthCheck,
			},
		})
		return
	}

	// Check image via docker inspect
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, "docker", "image", "inspect", engine.ContainerImage)
	inspectErr := cmd.Run()

	if inspectErr == nil {
		engine.Status = "AVAILABLE"
		h.db.Save(&engine)
		c.JSON(http.StatusOK, gin.H{
			"message": fmt.Sprintf("Engine image %s terverifikasi siap dan aktif.", engine.ContainerImage),
			"data": gin.H{
				"engine":            engine.Code,
				"status":            engine.Status,
				"image_present":     true,
				"last_health_check": engine.LastHealthCheck,
			},
		})
		return
	}

	engine.Status = "STANDBY"
	h.db.Save(&engine)
	c.JSON(http.StatusOK, gin.H{
		"message": fmt.Sprintf("Image %s belum di-cache secara lokal. Gunakan tombol 'Tarik Image' untuk mengunduh.", engine.ContainerImage),
		"data": gin.H{
			"engine":            engine.Code,
			"status":            engine.Status,
			"image_present":     false,
			"last_health_check": engine.LastHealthCheck,
		},
	})
}

// PullImage handles POST /api/security/engines/:id/pull-image
func (h *EngineHandler) PullImage(c *gin.Context) {
	param := c.Param("id")

	var engine models.SecurityEngine
	var err error
	if id, parseErr := strconv.ParseUint(param, 10, 64); parseErr == nil {
		err = h.db.First(&engine, id).Error
	} else {
		err = h.db.Where("code = ?", param).First(&engine).Error
	}

	if err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Mesin keamanan tidak ditemukan."})
		return
	}

	if engine.ContainerImage == "" {
		c.JSON(http.StatusOK, gin.H{
			"message": fmt.Sprintf("Mesin %s tidak memerlukan docker image.", engine.Name),
			"data":    engine,
		})
		return
	}

	now := time.Now()
	engine.Status = "UPDATING"
	h.db.Save(&engine)

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()

	cmd := exec.CommandContext(ctx, "docker", "pull", engine.ContainerImage)
	pullErr := cmd.Run()

	engine.LastHealthCheck = &now
	if pullErr == nil {
		engine.Status = "AVAILABLE"
		h.db.Save(&engine)
		c.JSON(http.StatusOK, gin.H{
			"message": fmt.Sprintf("Image %s berhasil diunduh. Mesin %s siap digunakan.", engine.ContainerImage, engine.Name),
			"data": gin.H{
				"engine":            engine.Code,
				"status":            engine.Status,
				"image_present":     true,
				"last_health_check": engine.LastHealthCheck,
			},
		})
		return
	}

	engine.Status = "STANDBY"
	h.db.Save(&engine)
	c.JSON(http.StatusUnprocessableEntity, gin.H{
		"message": fmt.Sprintf("Gagal mengunduh image %s: %v", engine.ContainerImage, pullErr),
		"data": gin.H{
			"engine":            engine.Code,
			"status":            engine.Status,
			"image_present":     false,
			"last_health_check": engine.LastHealthCheck,
		},
	})
}

// SyncAll handles POST /api/security/engines/sync
func (h *EngineHandler) SyncAll(c *gin.Context) {
	var engines []models.SecurityEngine
	h.db.Find(&engines)

	now := time.Now()
	availableCount := 0
	syncedCount := 0

	for i := range engines {
		e := &engines[i]
		e.LastHealthCheck = &now

		if e.ContainerImage == "" {
			e.Status = "AVAILABLE"
			availableCount++
			syncedCount++
			h.db.Save(e)
			continue
		}

		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		cmd := exec.CommandContext(ctx, "docker", "image", "inspect", e.ContainerImage)
		err := cmd.Run()
		cancel()

		if err == nil {
			e.Status = "AVAILABLE"
			availableCount++
		} else {
			e.Status = "STANDBY"
		}
		syncedCount++
		h.db.Save(e)
	}

	c.JSON(http.StatusOK, gin.H{
		"message": fmt.Sprintf("Sinkronisasi selesai: %d dari %d mesin terverifikasi aktif.", availableCount, syncedCount),
		"data": gin.H{
			"total_synced":    syncedCount,
			"available_count": availableCount,
		},
	})
}

// ScanProfiles handles GET /api/security/scan-profiles
func (h *EngineHandler) ScanProfiles(c *gin.Context) {
	var mappings []models.ScanProfileEngine
	h.db.Preload("SecurityEngine").Order("execution_order ASC").Find(&mappings)

	grouped := make(map[string][]models.ScanProfileEngine)
	for _, m := range mappings {
		grouped[m.ProfileCode] = append(grouped[m.ProfileCode], m)
	}

	type ProfileEngineItem struct {
		ID            *uint64 `json:"id"`
		Code          string  `json:"code"`
		Name          string  `json:"name"`
		Domain        string  `json:"domain"`
		ResourceClass string  `json:"resource_class"`
		IsRequired    bool    `json:"is_required"`
	}

	type ProfileEngineOutput struct {
		Code        string              `json:"code"`
		Name        string              `json:"name"`
		EngineCount int                 `json:"engine_count"`
		Engines     []ProfileEngineItem `json:"engines"`
	}

	var profiles []ProfileEngineOutput
	for code, items := range grouped {
		var engineItems []ProfileEngineItem
		for _, item := range items {
			var id *uint64
			var eCode, eName, domain, resClass string
			if item.SecurityEngine != nil {
				id = &item.SecurityEngine.ID
				eCode = item.SecurityEngine.Code
				eName = item.SecurityEngine.Name
				domain = item.SecurityEngine.Domain
				resClass = item.SecurityEngine.ResourceClass
			}
			engineItems = append(engineItems, ProfileEngineItem{
				ID:            id,
				Code:          eCode,
				Name:          eName,
				Domain:        domain,
				ResourceClass: resClass,
				IsRequired:    item.IsRequired,
			})
		}

		name := strings.ReplaceAll(code, "_", " ")
		profiles = append(profiles, ProfileEngineOutput{
			Code:        code,
			Name:        name,
			EngineCount: len(items),
			Engines:     engineItems,
		})
	}

	c.JSON(http.StatusOK, gin.H{
		"data": profiles,
	})
}

