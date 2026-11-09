package handlers

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"os"
	"strings"
	"time"

	"tameng-backend-go/internal/models"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
	"gorm.io/gorm"
)

type GitWebhookHandler struct {
	db *gorm.DB
}

func NewGitWebhookHandler(db *gorm.DB) *GitWebhookHandler {
	return &GitWebhookHandler{db: db}
}

// Handle handles POST /api/webhooks/git
func (h *GitWebhookHandler) Handle(c *gin.Context) {
	webhookEnabled := os.Getenv("GIT_WEBHOOK_AUTO_SCAN_ENABLED")
	if webhookEnabled == "false" {
		c.JSON(http.StatusOK, gin.H{
			"status":  "disabled",
			"message": "Otomasi pemindaian otomatis pada setiap git push saat ini sedang dinonaktifkan.",
		})
		return
	}

	bodyBytes, err := io.ReadAll(c.Request.Body)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "Gagal membaca payload webhook."})
		return
	}

	secret := os.Getenv("GIT_WEBHOOK_SECRET")
	if secret != "" {
		ghSig := c.GetHeader("X-Hub-Signature-256")
		glToken := c.GetHeader("X-Gitlab-Token")
		customSecret := c.GetHeader("X-Webhook-Secret")

		valid := false
		if strings.HasPrefix(ghSig, "sha256=") {
			sigHash := strings.TrimPrefix(ghSig, "sha256=")
			mac := hmac.New(sha256.New, []byte(secret))
			mac.Write(bodyBytes)
			expected := hex.EncodeToString(mac.Sum(nil))
			valid = hmac.Equal([]byte(sigHash), []byte(expected))
		} else if glToken != "" {
			valid = (glToken == secret)
		} else if customSecret != "" {
			valid = (customSecret == secret)
		}

		if !valid {
			c.JSON(http.StatusUnauthorized, gin.H{
				"status":  "unauthorized",
				"message": "Autentikasi webhook gagal: signature atau secret token tidak valid.",
			})
			return
		}
	}

	var payload map[string]interface{}
	if err := json.Unmarshal(bodyBytes, &payload); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "JSON payload tidak valid."})
		return
	}

	// 1. Extract repo URL
	var repoURL string
	if repoObj, ok := payload["repository"].(map[string]interface{}); ok {
		if u, exists := repoObj["html_url"].(string); exists {
			repoURL = u
		} else if u, exists := repoObj["url"].(string); exists {
			repoURL = u
		} else if u, exists := repoObj["clone_url"].(string); exists {
			repoURL = u
		}
	} else if projObj, ok := payload["project"].(map[string]interface{}); ok {
		if u, exists := projObj["web_url"].(string); exists {
			repoURL = u
		}
	}

	if repoURL == "" {
		c.JSON(http.StatusBadRequest, gin.H{
			"status":  "ignored",
			"message": "Tidak dapat mendeteksi URL repositori dari payload webhook.",
		})
		return
	}

	cleanURL := strings.TrimRight(repoURL, "/")
	cleanNoGit := strings.TrimSuffix(cleanURL, ".git")

	// 2. Find matching repository
	var repo models.Repository
	if err := h.db.Where("url IN (?)", []string{
		cleanURL,
		cleanURL + ".git",
		cleanNoGit,
		cleanNoGit + ".git",
	}).Preload("Project").First(&repo).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{
			"status":  "unmatched",
			"message": fmt.Sprintf("Repositori '%s' belum terdaftar di SecSys.", cleanURL),
		})
		return
	}

	// 3. Scan Profile
	var profile models.ScanProfile
	if err := h.db.Where("`key` = ? AND is_active = ?", "source_code_scan", true).First(&profile).Error; err != nil {
		h.db.Where("is_active = ?", true).First(&profile)
	}

	// 4. Authorization
	now := time.Now()
	authCode := fmt.Sprintf("AUTH-%s-HOOK-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))

	var firstUser models.User
	h.db.First(&firstUser)

	auth := models.Authorization{
		Code:                 authCode,
		ProjectID:            repo.ProjectID,
		RepositoryID:         &repo.ID,
		ScanProfileID:        profile.ID,
		RequestedBy:          firstUser.ID,
		ApprovedBy:           &firstUser.ID,
		Status:               "active",
		ValidFrom:            now,
		MaxConcurrency:       1,
		RateLimitPerMinute:   60,
		AllowedEngines:       profile.EngineKeys,
		AllowedScopeSnapshot: "[]",
		PolicySnapshot:       "{}",
		CreatedAt:            now,
		UpdatedAt:            now,
	}
	h.db.Create(&auth)

	// 5. Scan Job
	var engineKeys []string
	_ = json.Unmarshal([]byte(profile.EngineKeys), &engineKeys)
	var enginePlan []gin.H
	for _, k := range engineKeys {
		enginePlan = append(enginePlan, gin.H{"engine_key": k, "status": "queued"})
	}
	enginePlanBytes, _ := json.Marshal(enginePlan)

	jobCode := fmt.Sprintf("SCAN-%s-CI-%04X", now.Format("20060102150405"), rand.Intn(0xFFFF))
	scanJob := models.ScanJob{
		Code:                    jobCode,
		ProjectID:               repo.ProjectID,
		RepositoryID:            &repo.ID,
		ScanProfileID:           profile.ID,
		AuthorizationID:         auth.ID,
		CreatedBy:               firstUser.ID,
		Status:                  "queued",
		Progress:                0,
		Attempt:                 0,
		QueuedAt:                &now,
		EnginePlan:              string(enginePlanBytes),
		ExecutionPolicySnapshot: &auth.PolicySnapshot,
		CreatedAt:               now,
		UpdatedAt:               now,
	}
	h.db.Create(&scanJob)

	_ = services.DispatchRunScanJob(h.db, scanJob.ID, firstUser.ID)

	c.JSON(http.StatusOK, gin.H{
		"status":      "triggered",
		"message":     fmt.Sprintf("Audit keamanan CI/CD otomatis berhasil dipicu untuk commit pada repositori '%s'.", repo.Name),
		"scan_job_id": scanJob.ID,
		"code":        scanJob.Code,
	})
}

