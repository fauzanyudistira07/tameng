package handlers

import (
	"crypto/rand"
	"encoding/hex"
	"net/http"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/models"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
)

type AuthHandler struct {
	authService *services.AuthService
	cfg         *config.Config
}

func NewAuthHandler(authService *services.AuthService, cfg *config.Config) *AuthHandler {
	return &AuthHandler{
		authService: authService,
		cfg:         cfg,
	}
}

type LoginRequest struct {
	Email    string `json:"email" binding:"required,email"`
	Password string `json:"password" binding:"required"`
	Remember bool   `json:"remember"`
}

type ForgotPasswordRequest struct {
	Email string `json:"email" binding:"required,email"`
}

type ResetPasswordRequest struct {
	Email                string `json:"email" binding:"required,email"`
	Token                string `json:"token" binding:"required"`
	Password             string `json:"password" binding:"required,min=8"`
	PasswordConfirmation string `json:"password_confirmation" binding:"required"`
}

// CsrfCookie provides /sanctum/csrf-cookie for frontend SPA compatibility.
func (h *AuthHandler) CsrfCookie(c *gin.Context) {
	bytes := make([]byte, 20)
	rand.Read(bytes)
	token := hex.EncodeToString(bytes)

	// Set XSRF-TOKEN cookie readable by client JS
	c.SetCookie("XSRF-TOKEN", token, 7200, "/", "", false, false)
	c.Status(http.StatusNoContent)
}

// Login handles POST /api/login
func (h *AuthHandler) Login(c *gin.Context) {
	var req LoginRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": "Data login tidak valid. Pastikan email dan password terisi dengan benar.",
			"errors": gin.H{
				"email": []string{"Email dan password wajib diisi."},
			},
		})
		return
	}

	user, sessionToken, ttl, err := h.authService.Login(
		c.Request.Context(),
		req.Email,
		req.Password,
		req.Remember,
		c.ClientIP(),
		c.Request.UserAgent(),
	)

	if err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": err.Error(),
			"errors": gin.H{
				"email": []string{err.Error()},
			},
		})
		return
	}

	// Set Session Cookie
	maxAge := int(ttl.Seconds())
	c.SetCookie(
		h.cfg.SessionCookieName,
		sessionToken,
		maxAge,
		"/",
		"",
		false, // Set to true if HTTPS in production
		true,  // HttpOnly
	)

	// Set XSRF-TOKEN
	xsrfBytes := make([]byte, 20)
	rand.Read(xsrfBytes)
	c.SetCookie("XSRF-TOKEN", hex.EncodeToString(xsrfBytes), maxAge, "/", "", false, false)

	c.JSON(http.StatusOK, gin.H{
		"user": formatUserResponse(user),
	})
}

// Me handles GET /api/user
func (h *AuthHandler) Me(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}

	user := val.(*models.User)
	c.JSON(http.StatusOK, gin.H{
		"user": formatUserResponse(user),
	})
}

// UpdateProfile handles PUT /api/user
func (h *AuthHandler) UpdateProfile(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req services.UpdateProfileInput
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": "Data yang dikirim tidak valid.",
			"errors":  gin.H{"general": []string{err.Error()}},
		})
		return
	}

	updatedUser, err := h.authService.UpdateProfile(
		c.Request.Context(),
		user.ID,
		req,
		c.ClientIP(),
		c.Request.UserAgent(),
	)
	if err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": err.Error(),
			"errors": gin.H{
				"general": []string{err.Error()},
			},
		})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"message": "Profil berhasil diperbarui.",
		"user":    formatUserResponse(updatedUser),
	})
}

// Logout handles POST /api/logout
func (h *AuthHandler) Logout(c *gin.Context) {
	sessionToken, _ := c.Get("session_token")
	var user *models.User
	if val, exists := c.Get("user"); exists {
		user = val.(*models.User)
	}

	tokenStr := ""
	if sessionToken != nil {
		tokenStr = sessionToken.(string)
	}

	h.authService.Logout(c.Request.Context(), tokenStr, user, c.ClientIP(), c.Request.UserAgent())

	// Clear session cookie
	c.SetCookie(h.cfg.SessionCookieName, "", -1, "/", "", false, true)
	c.SetCookie("XSRF-TOKEN", "", -1, "/", "", false, false)

	c.JSON(http.StatusOK, gin.H{
		"message": "Logged out.",
	})
}

// ForgotPassword handles POST /api/forgot-password
func (h *AuthHandler) ForgotPassword(c *gin.Context) {
	var req ForgotPasswordRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": "Alamat email tidak valid.",
			"errors": gin.H{
				"email": []string{"Format email tidak valid."},
			},
		})
		return
	}

	if err := h.authService.ForgotPassword(c.Request.Context(), req.Email, c.ClientIP(), c.Request.UserAgent()); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": err.Error(),
			"errors": gin.H{
				"email": []string{err.Error()},
			},
		})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"message": "Kode verifikasi telah dikirim ke email " + req.Email + ". Silakan buka kotak masuk atau folder spam email Anda.",
		"email":   req.Email,
	})
}

// ResetPassword handles POST /api/reset-password
func (h *AuthHandler) ResetPassword(c *gin.Context) {
	var req ResetPasswordRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": "Data reset password tidak valid.",
			"errors": gin.H{
				"password": []string{"Password minimal 8 karakter."},
			},
		})
		return
	}

	if req.Password != req.PasswordConfirmation {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": "Konfirmasi password tidak cocok.",
			"errors": gin.H{
				"password": []string{"Konfirmasi password tidak cocok dengan password baru."},
			},
		})
		return
	}

	if err := h.authService.ResetPassword(c.Request.Context(), req.Email, req.Token, req.Password, c.ClientIP(), c.Request.UserAgent()); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{
			"message": err.Error(),
			"errors": gin.H{
				"token": []string{err.Error()},
			},
		})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"message": "Password berhasil diperbarui. Silakan login dengan password baru Anda.",
	})
}

func formatUserResponse(user *models.User) gin.H {
	var roleData gin.H
	if user.Role.ID != 0 {
		roleData = gin.H{
			"id":           user.Role.ID,
			"name":         user.Role.Name,
			"display_name": user.Role.DisplayName,
		}
	}

	var projectsData []gin.H
	for _, p := range user.Projects {
		projectsData = append(projectsData, gin.H{
			"id":   p.ID,
			"name": p.Name,
			"code": p.Code,
		})
	}

	var prefData gin.H
	if user.Preference != nil {
		prefTheme := user.Preference.Theme
		if prefTheme == "" {
			prefTheme = "dark"
		}
		prefData = gin.H{
			"alert_email":        user.Preference.AlertEmail,
			"alert_telegram":     user.Preference.AlertTelegram,
			"telegram_chat_id":   user.Preference.TelegramChatID,
			"alert_min_severity": user.Preference.AlertMinSeverity,
			"theme":              prefTheme,
		}
	}

	var lastLoginStr *string
	if user.LastLoginAt != nil {
		s := user.LastLoginAt.Format("2006-01-02T15:04:05Z07:00")
		lastLoginStr = &s
	}

	return gin.H{
		"id":                 user.ID,
		"name":               user.Name,
		"username":           user.Username,
		"email":              user.Email,
		"phone":              user.Phone,
		"department":         user.Department,
		"avatar_path":        user.AvatarPath,
		"auth_provider":      user.AuthProvider,
		"status":             user.Status,
		"last_login_at":      lastLoginStr,
		"two_factor_enabled": user.TwoFactorEnabled,
		"role":               roleData,
		"projects":           projectsData,
		"preference":         prefData,
	}
}
