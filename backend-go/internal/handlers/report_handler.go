package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"tameng-backend-go/internal/models"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type ReportHandler struct {
	db         *gorm.DB
	pdfService *services.PDFService
}

func NewReportHandler(db *gorm.DB, pdfService *services.PDFService) *ReportHandler {
	return &ReportHandler{db: db, pdfService: pdfService}
}

// List handles GET /api/reports
func (h *ReportHandler) List(c *gin.Context) {
	val, _ := c.Get("user")
	var user *models.User
	if val != nil {
		user = val.(*models.User)
	}

	query := h.db.Model(&models.Report{}).
		Preload("ScanJob").
		Preload("ScanJob.Project").
		Preload("Generator").
		Order("id DESC")

	if user != nil {
		roleName := strings.ToLower(user.Role.Name)
		if roleName != "super_admin" && roleName != "security_admin" {
			var assignedIDs []uint64
			h.db.Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &assignedIDs)
			query = query.Joins("JOIN scan_jobs ON scan_jobs.id = reports.scan_job_id").
				Where("scan_jobs.project_id IN (?) OR scan_jobs.created_by = ?", assignedIDs, user.ID)
		}
	}

	var reports []models.Report
	if err := query.Find(&reports).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar laporan."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"reports": reports,
	})
}

// Show handles GET /api/reports/:id
func (h *ReportHandler) Show(c *gin.Context) {
	idStr := c.Param("id")
	reportID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var report models.Report
	if err := h.db.
		Preload("ScanJob").
		Preload("ScanJob.Project").
		Preload("Generator").
		First(&report, reportID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Laporan tidak ditemukan."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"report": report,
	})
}

// Store handles POST /api/reports
func (h *ReportHandler) Store(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req struct {
		ScanJobID uint64 `json:"scan_job_id" binding:"required"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Scan job ID diperlukan."})
		return
	}

	var scanJob models.ScanJob
	if err := h.db.Preload("Project").First(&scanJob, req.ScanJobID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Scan job tidak ditemukan."})
		return
	}

	// Calculate findings breakdown
	var totalFindings, critCount, highCount, medCount, lowCount, infoCount int64
	h.db.Model(&models.Finding{}).Where("scan_job_id = ?", scanJob.ID).Count(&totalFindings)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", scanJob.ID, "critical").Count(&critCount)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", scanJob.ID, "high").Count(&highCount)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", scanJob.ID, "medium").Count(&medCount)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", scanJob.ID, "low").Count(&lowCount)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) IN (?)", scanJob.ID, []string{"informational", "info"}).Count(&infoCount)

	meta := gin.H{
		"finding_count": totalFindings,
		"severity_breakdown": gin.H{
			"critical":      critCount,
			"high":          highCount,
			"medium":        medCount,
			"low":           lowCount,
			"informational": infoCount,
		},
		"generated_by_engine": "tameng-backend-go",
	}
	metaBytes, _ := json.Marshal(meta)
	metaStr := string(metaBytes)

	now := time.Now()
	report := models.Report{
		ScanJobID:   scanJob.ID,
		Type:        "standard",
		Status:      "completed",
		Format:      "pdf",
		GeneratedBy: &user.ID,
		GeneratedAt: &now,
		Metadata:    &metaStr,
		CreatedAt:   now,
		UpdatedAt:   now,
	}

	if err := h.db.Create(&report).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal membuat laporan."})
		return
	}

	h.db.Preload("ScanJob").Preload("Generator").First(&report, report.ID)

	c.JSON(http.StatusCreated, gin.H{
		"report": report,
	})
}

// Sync handles POST /api/reports/:id/sync
func (h *ReportHandler) Sync(c *gin.Context) {
	idStr := c.Param("id")
	reportID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var report models.Report
	if err := h.db.First(&report, reportID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Laporan tidak ditemukan."})
		return
	}

	// Update finding counts
	var totalFindings, critCount, highCount, medCount, lowCount, infoCount int64
	h.db.Model(&models.Finding{}).Where("scan_job_id = ?", report.ScanJobID).Count(&totalFindings)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", report.ScanJobID, "critical").Count(&critCount)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", report.ScanJobID, "high").Count(&highCount)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", report.ScanJobID, "medium").Count(&medCount)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) = ?", report.ScanJobID, "low").Count(&lowCount)
	h.db.Model(&models.Finding{}).Where("scan_job_id = ? AND LOWER(severity) IN (?)", report.ScanJobID, []string{"informational", "info"}).Count(&infoCount)

	meta := gin.H{
		"finding_count": totalFindings,
		"severity_breakdown": gin.H{
			"critical":      critCount,
			"high":          highCount,
			"medium":        medCount,
			"low":           lowCount,
			"informational": infoCount,
		},
		"synced_at": time.Now().Format(time.RFC3339),
	}
	metaBytes, _ := json.Marshal(meta)
	metaStr := string(metaBytes)

	report.Metadata = &metaStr
	report.UpdatedAt = time.Now()
	h.db.Save(&report)

	h.db.Preload("ScanJob").Preload("Generator").First(&report, report.ID)

	c.JSON(http.StatusOK, gin.H{
		"report":  report,
		"message": "Laporan berhasil diperbarui dengan data scan terbaru.",
	})
}

// DownloadPDF handles GET /api/reports/:id/download-pdf
func (h *ReportHandler) DownloadPDF(c *gin.Context) {
	idStr := c.Param("id")
	reportID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	pdfBytes, filename, err := h.pdfService.GeneratePDF(reportID)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal merender PDF: " + err.Error()})
		return
	}

	c.Header("Content-Type", "application/pdf")
	c.Header("Content-Disposition", fmt.Sprintf("attachment; filename=\"%s\"", filename))
	c.Header("Content-Length", strconv.Itoa(len(pdfBytes)))
	c.Header("Cache-Control", "no-store, no-cache, must-revalidate")
	c.Data(http.StatusOK, "application/pdf", pdfBytes)
}

