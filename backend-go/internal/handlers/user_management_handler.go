package handlers

import (
	"errors"
	"net/http"
	"strconv"
	"time"

	"tameng-backend-go/internal/models"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
	"golang.org/x/crypto/bcrypt"
	"gorm.io/gorm"
)

type UserManagementHandler struct {
	db    *gorm.DB
	audit *services.AuditService
}

func NewUserManagementHandler(db *gorm.DB, audit *services.AuditService) *UserManagementHandler {
	return &UserManagementHandler{db: db, audit: audit}
}

// List handles GET /api/users
func (h *UserManagementHandler) List(c *gin.Context) {
	var users []models.User
	if err := h.db.
		Preload("Role").
		Preload("Projects").
		Preload("Preference").
		Order("name ASC").
		Find(&users).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil daftar pengguna."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"users": users,
	})
}

// Store handles POST /api/users
func (h *UserManagementHandler) Store(c *gin.Context) {
	var req struct {
		RoleID          uint64   `json:"role_id" binding:"required"`
		Name            string   `json:"name" binding:"required"`
		Username        *string  `json:"username"`
		Email           string   `json:"email" binding:"required,email"`
		Phone           *string  `json:"phone"`
		Department      *string  `json:"department"`
		Password        string   `json:"password" binding:"required,min=8"`
		Status          string   `json:"status"`
		ProjectIDs      []uint64 `json:"project_ids"`
		Theme           string   `json:"theme"`
		AlertEmail      *bool    `json:"alert_email"`
		AlertTelegram   *bool    `json:"alert_telegram"`
		TelegramChatID  *string  `json:"telegram_chat_id"`
		AlertMinSeverity string  `json:"alert_min_severity"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data yang dikirim tidak valid.", "error": err.Error()})
		return
	}

	// Check email uniqueness
	var count int64
	h.db.Model(&models.User{}).Where("email = ?", req.Email).Count(&count)
	if count > 0 {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Email sudah terdaftar."})
		return
	}

	if req.Status == "" {
		req.Status = "active"
	}

	hashedPassword, err := bcrypt.GenerateFromPassword([]byte(req.Password), 12)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengenkripsi kata sandi."})
		return
	}

	now := time.Now()
	user := models.User{
		RoleID:            req.RoleID,
		Name:              req.Name,
		Username:          req.Username,
		Email:             req.Email,
		Phone:             req.Phone,
		Department:        req.Department,
		Password:          string(hashedPassword),
		PasswordChangedAt: &now,
		Status:            req.Status,
		CreatedAt:         now,
		UpdatedAt:         now,
	}

	if err := h.db.Create(&user).Error; err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menyimpan pengguna baru."})
		return
	}

	// Record password history
	h.db.Create(&models.PasswordHistory{
		UserID:    user.ID,
		Password:  string(hashedPassword),
		CreatedAt: now,
	})

	// User Preference
	alertEmail := true
	if req.AlertEmail != nil {
		alertEmail = *req.AlertEmail
	}
	alertTelegram := false
	if req.AlertTelegram != nil {
		alertTelegram = *req.AlertTelegram
	}
	minSev := "high"
	if req.AlertMinSeverity != "" {
		minSev = req.AlertMinSeverity
	}
	theme := "dark"
	if req.Theme != "" {
		theme = req.Theme
	}

	pref := models.UserPreference{
		UserID:           user.ID,
		AlertEmail:       alertEmail,
		AlertTelegram:    alertTelegram,
		TelegramChatID:   req.TelegramChatID,
		AlertMinSeverity: minSev,
		Theme:            theme,
		CreatedAt:        now,
		UpdatedAt:        now,
	}
	h.db.Create(&pref)

	// Assign projects
	for _, pid := range req.ProjectIDs {
		h.db.Create(&models.ProjectUser{
			ProjectID:   pid,
			UserID:      user.ID,
			AccessLevel: "analyst",
		})
	}

	h.db.Preload("Role").Preload("Projects").Preload("Preference").First(&user, user.ID)

	c.JSON(http.StatusCreated, gin.H{
		"message": "Pengguna berhasil dibuat.",
		"user":    user,
	})
}

// Update handles PUT /api/users/:id
func (h *UserManagementHandler) Update(c *gin.Context) {
	idStr := c.Param("id")
	userID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID pengguna tidak valid."})
		return
	}

	var user models.User
	if err := h.db.First(&user, userID).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"message": "Pengguna tidak ditemukan."})
		return
	}

	var req struct {
		RoleID          *uint64  `json:"role_id"`
		Name            string   `json:"name"`
		Username        *string  `json:"username"`
		Email           string   `json:"email"`
		Phone           *string  `json:"phone"`
		Department      *string  `json:"department"`
		Password        *string  `json:"password"`
		Status          *string  `json:"status"`
		ProjectIDs      []uint64 `json:"project_ids"`
		Theme           *string  `json:"theme"`
		AlertEmail      *bool    `json:"alert_email"`
		AlertTelegram   *bool    `json:"alert_telegram"`
		TelegramChatID  *string  `json:"telegram_chat_id"`
		AlertMinSeverity *string `json:"alert_min_severity"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Data tidak valid."})
		return
	}

	now := time.Now()

	if req.Name != "" {
		user.Name = req.Name
	}
	if req.Email != "" && req.Email != user.Email {
		var count int64
		h.db.Model(&models.User{}).Where("email = ? AND id != ?", req.Email, user.ID).Count(&count)
		if count > 0 {
			c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Email sudah digunakan."})
			return
		}
		user.Email = req.Email
	}
	if req.Username != nil {
		user.Username = req.Username
	}
	if req.Phone != nil {
		user.Phone = req.Phone
	}
	if req.Department != nil {
		user.Department = req.Department
	}
	if req.RoleID != nil {
		user.RoleID = *req.RoleID
	}
	if req.Status != nil {
		user.Status = *req.Status
	}

	// Password update
	if req.Password != nil && len(*req.Password) >= 8 {
		// Check last 3 passwords
		var histories []models.PasswordHistory
		h.db.Where("user_id = ?", user.ID).Order("id DESC").Limit(3).Find(&histories)
		for _, hist := range histories {
			if bcrypt.CompareHashAndPassword([]byte(hist.Password), []byte(*req.Password)) == nil {
				c.JSON(http.StatusUnprocessableEntity, gin.H{
					"message": "Kata sandi tidak boleh sama dengan 3 sandi terakhir.",
				})
				return
			}
		}

		hashed, _ := bcrypt.GenerateFromPassword([]byte(*req.Password), 12)
		user.Password = string(hashed)
		user.PasswordChangedAt = &now

		h.db.Create(&models.PasswordHistory{
			UserID:    user.ID,
			Password:  string(hashed),
			CreatedAt: now,
		})
	}

	user.UpdatedAt = now
	h.db.Save(&user)

	// Update Preferences
	var pref models.UserPreference
	errPref := h.db.Where("user_id = ?", user.ID).First(&pref).Error
	if errors.Is(errPref, gorm.ErrRecordNotFound) {
		pref = models.UserPreference{
			UserID:           user.ID,
			AlertEmail:       true,
			AlertTelegram:    false,
			AlertMinSeverity: "high",
			Theme:            "dark",
			CreatedAt:        now,
			UpdatedAt:        now,
		}
		h.db.Create(&pref)
	}

	if req.Theme != nil {
		pref.Theme = *req.Theme
	}
	if req.AlertEmail != nil {
		pref.AlertEmail = *req.AlertEmail
	}
	if req.AlertTelegram != nil {
		pref.AlertTelegram = *req.AlertTelegram
	}
	if req.TelegramChatID != nil {
		pref.TelegramChatID = req.TelegramChatID
	}
	if req.AlertMinSeverity != nil {
		pref.AlertMinSeverity = *req.AlertMinSeverity
	}
	pref.UpdatedAt = now
	h.db.Save(&pref)

	// Sync Projects if provided
	if req.ProjectIDs != nil {
		h.db.Where("user_id = ?", user.ID).Delete(&models.ProjectUser{})
		for _, pid := range req.ProjectIDs {
			h.db.Create(&models.ProjectUser{
				ProjectID:   pid,
				UserID:      user.ID,
				AccessLevel: "analyst",
			})
		}
	}

	h.db.Preload("Role").Preload("Projects").Preload("Preference").First(&user, user.ID)

	c.JSON(http.StatusOK, gin.H{
		"message": "Pengguna berhasil diperbarui.",
		"user":    user,
	})
}

// Unlock handles POST /api/users/:id/unlock
func (h *UserManagementHandler) Unlock(c *gin.Context) {
	idStr := c.Param("id")
	userID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	h.db.Model(&models.User{}).Where("id = ?", userID).Updates(map[string]interface{}{
		"failed_login_attempts": 0,
		"locked_until":           nil,
	})

	c.JSON(http.StatusOK, gin.H{
		"message": "Akun pengguna berhasil dibuka kuncinya.",
	})
}

// AssignProjects handles POST /api/users/:id/projects
func (h *UserManagementHandler) AssignProjects(c *gin.Context) {
	idStr := c.Param("id")
	userID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	var req struct {
		ProjectIDs  []uint64 `json:"project_ids"`
		AccessLevel string   `json:"access_level"`
	}
	_ = c.ShouldBindJSON(&req)

	if req.AccessLevel == "" {
		req.AccessLevel = "analyst"
	}

	h.db.Where("user_id = ?", userID).Delete(&models.ProjectUser{})
	for _, pid := range req.ProjectIDs {
		h.db.Create(&models.ProjectUser{
			ProjectID:   pid,
			UserID:      userID,
			AccessLevel: req.AccessLevel,
		})
	}

	c.JSON(http.StatusOK, gin.H{
		"message": "Penugasan proyek pengguna berhasil disimpan.",
	})
}

// Destroy handles DELETE /api/users/:id
func (h *UserManagementHandler) Destroy(c *gin.Context) {
	idStr := c.Param("id")
	userID, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": "ID tidak valid."})
		return
	}

	h.db.Where("user_id = ?", userID).Delete(&models.ProjectUser{})
	h.db.Where("user_id = ?", userID).Delete(&models.UserPreference{})
	h.db.Delete(&models.User{}, userID)

	c.JSON(http.StatusOK, gin.H{
		"message": "Pengguna berhasil dihapus.",
	})
}

