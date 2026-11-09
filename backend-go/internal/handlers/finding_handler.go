package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"tameng-backend-go/internal/models"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type FindingHandler struct {
	db *gorm.DB
}

func NewFindingHandler(db *gorm.DB) *FindingHandler {
	return &FindingHandler{db: db}
}

// List handles GET /api/findings
func (h *FindingHandler) List(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.Finding{}).
		Preload("Project").
		Preload("ScanJob").
		Preload("ScanRun").
		Preload("Evidences").
		Order("FIELD(severity, 'critical', 'high', 'medium', 'low', 'informational') ASC").
		Order("id DESC")

	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		if roleName != "super_admin" && roleName != "security_admin" {
			var assignedIDs []uint64
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &assignedIDs)
			query = query.Where("project_id IN (?)", assignedIDs)
		}
	}

	var findings []models.Finding
	if err := query.Find(&findings).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar temuan."})
		return
	}

	var critCount, highCount, medCount, lowCount, infoCount int
	for _, f := range findings {
		switch strings.ToLower(f.Severity) {
		case "critical":
			critCount++
		case "high":
			highCount++
		case "medium":
			medCount++
		case "low":
			lowCount++
		case "informational", "info":
			infoCount++
		}
	}

	c.JSON(http.StatusOK, gin.H{
		"summary": gin.H{
			"total":         len(findings),
			"critical":      critCount,
			"high":          highCount,
			"medium":        medCount,
			"low":           lowCount,
			"informational": infoCount,
		},
		"findings": findings,
	})
}

// Show handles GET /api/findings/:id
func (h *FindingHandler) Show(c *gin.Context) {
	idStr := c.Param("id")
	findingID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var finding models.Finding
	if err := h.db.
		Preload("Project").
		Preload("ScanJob").
		Preload("ScanRun").
		Preload("Evidences").
		First(&finding, findingID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Temuan tidak ditemukan."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"finding": finding,
	})
}

// Update handles PUT /api/findings/:id
func (h *FindingHandler) Update(c *gin.Context) {
	idStr := c.Param("id")
	findingID, err := strconv.ParseUint(idStr, 10, 64)
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

	var finding models.Finding
	if err := h.db.First(&finding, findingID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Temuan tidak ditemukan."})
		return
	}

	var req struct {
		Status          string `json:"status" binding:"required"`
		ResolutionNotes string `json:"resolution_notes"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Status diperlukan."})
		return
	}

	finding.Status = req.Status
	now := time.Now()

	var metaMap map[string]interface{}
	if finding.NormalizationMetadata != nil && *finding.NormalizationMetadata != "" {
		_ = json.Unmarshal([]byte(*finding.NormalizationMetadata), &metaMap)
	}
	if metaMap == nil {
		metaMap = make(map[string]interface{})
	}

	if req.ResolutionNotes != "" {
		metaMap["triage_notes"] = req.ResolutionNotes
		metaMap["triaged_by"] = user.Name
		metaMap["triaged_at"] = now.Format(time.RFC3339)
		metaBytes, _ := json.Marshal(metaMap)
		metaStr := string(metaBytes)
		finding.NormalizationMetadata = &metaStr
	}
	finding.UpdatedAt = now

	h.db.Save(&finding)

	h.db.Preload("Project").
		Preload("ScanJob").
		Preload("ScanRun").
		Preload("Evidences").
		First(&finding, finding.ID)

	c.JSON(http.StatusOK, gin.H{
		"message": "Status temuan berhasil diperbarui.",
		"finding": finding,
	})
}

// AIRemediation handles GET /api/findings/:id/ai-remediation
func (h *FindingHandler) AIRemediation(c *gin.Context) {
	idStr := c.Param("id")
	findingID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var finding models.Finding
	if err := h.db.First(&finding, findingID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Temuan tidak ditemukan."})
		return
	}

	cwe := "CWE-General"
	if finding.CWE != nil && *finding.CWE != "" {
		cwe = *finding.CWE
	}
	owasp := "A00:2021"
	if finding.OWASP != nil && *finding.OWASP != "" {
		owasp = *finding.OWASP
	}

	explanation := fmt.Sprintf("Temuan ini mengindikasikan adanya kerentanan keamanan berkategori %s (%s) pada asset %s (%s). Kerentanan ini berisiko dieksploitasi oleh aktor jahat untuk membobol integritas atau kerahasiaan sistem.",
		finding.Title, cwe, finding.AssetIdentifier, finding.AssetType)

	steps := []string{
		"Validasi seluruh input pengguna menggunakan parameterized query atau validator berbasis allowlist.",
		"Terapkan prinsip least-privilege pada hak akses kredensial dan database.",
		"Pastikan sanitasi output dilakukan sebelum data di-render atau dikirimkan ke klien.",
		"Lakukan regression test dan jalankan ulang scan untuk memastikan perbaikan efektif.",
	}

	guidance := gin.H{
		"finding_id":  finding.ID,
		"title":       finding.Title,
		"cwe":         cwe,
		"owasp":       owasp,
		"severity":    finding.Severity,
		"explanation": explanation,
		"steps":       steps,
		"advisory":    "Rekomendasi AI bersifat asistif. Analis keamanan tetap bertanggung jawab memverifikasi kode sebelum deployment produksi.",
	}

	c.JSON(http.StatusOK, gin.H{
		"remediation": guidance,
	})
}

