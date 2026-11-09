package services

import (
	"context"
	"crypto/md5"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"math/big"
	"strconv"
	"time"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/models"

	"github.com/google/uuid"
	"github.com/redis/go-redis/v9"
	"golang.org/x/crypto/bcrypt"
	"gorm.io/gorm"
)

var (
	ErrInvalidCredentials = errors.New("Email atau kata sandi tidak valid.")
	ErrAccountLocked      = errors.New("Akun terkunci sementara karena terlalu banyak percobaan login yang gagal.")
	ErrAccountInactive    = errors.New("Akun ini berstatus tidak aktif. Silakan hubungi Administrator SOC.")
	ErrUserNotFound       = errors.New("Pengguna tidak ditemukan dalam sistem.")
	ErrTokenExpired       = errors.New("Kode verifikasi telah kedaluwarsa (melebihi 15 menit). Silakan minta kode baru.")
	ErrTokenInvalid       = errors.New("Kode verifikasi tidak valid.")
	ErrTooManyAttempts    = errors.New("Terlalu banyak percobaan kode verifikasi yang salah. Kode telah dibatalkan.")
)

type AuthService struct {
	db     *gorm.DB
	redis  *redis.Client
	cfg    *config.Config
	mail   *MailService
	audit  *AuditService
}

func NewAuthService(db *gorm.DB, rdb *redis.Client, cfg *config.Config, mail *MailService, audit *AuditService) *AuthService {
	return &AuthService{
		db:    db,
		redis: rdb,
		cfg:   cfg,
		mail:  mail,
		audit: audit,
	}
}

// Login authenticates credentials, enforces lockout, records audit logs, and creates a session token.
func (s *AuthService) Login(ctx context.Context, email, password string, remember bool, ip, userAgent string) (*models.User, string, time.Duration, error) {
	var user models.User
	err := s.db.WithContext(ctx).
		Preload("Role").
		Preload("Projects").
		Preload("Preference").
		Where("email = ?", email).
		First(&user).Error

	if err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			s.audit.LogAuth(nil, email, ip, userAgent, "failed", strPtr("user_not_found"))
			return nil, "", 0, ErrInvalidCredentials
		}
		return nil, "", 0, fmt.Errorf("database error: %w", err)
	}

	// 1. Check account lockout
	if user.LockedUntil != nil && user.LockedUntil.After(time.Now()) {
		diffMin := int(time.Until(*user.LockedUntil).Minutes()) + 1
		s.audit.LogAuth(&user.ID, email, ip, userAgent, "blocked", strPtr("account_locked"))
		return nil, "", 0, fmt.Errorf("Akun terkunci sementara karena terlalu banyak percobaan gagal. Silakan coba lagi dalam %d menit.", diffMin)
	}

	// 2. Verify Bcrypt password
	if err := bcrypt.CompareHashAndPassword([]byte(user.Password), []byte(password)); err != nil {
		attempts := user.FailedLoginAttempts + 1
		updateData := map[string]interface{}{
			"failed_login_attempts": attempts,
		}

		status := "failed"
		reason := "invalid_credentials"

		if attempts >= 5 {
			lockUntil := time.Now().Add(15 * time.Minute)
			updateData["locked_until"] = lockUntil
			status = "locked"
			reason = "max_failed_attempts_exceeded"
		}

		s.db.WithContext(ctx).Model(&user).Updates(updateData)
		s.audit.LogAuth(&user.ID, email, ip, userAgent, status, &reason)

		if attempts >= 5 {
			return nil, "", 0, fmt.Errorf("Terlalu banyak percobaan login gagal. Akun dikunci selama 15 menit.")
		}

		return nil, "", 0, ErrInvalidCredentials
	}

	// 3. Check account status
	if user.Status != "active" {
		s.audit.LogAuth(&user.ID, email, ip, userAgent, "blocked", strPtr("account_inactive"))
		return nil, "", 0, ErrAccountInactive
	}

	// 4. Login Success: Reset lockout & update login info
	now := time.Now()
	user.LastLoginAt = &now
	user.LastLoginIP = &ip
	user.FailedLoginAttempts = 0
	user.LockedUntil = nil

	updateFields := map[string]interface{}{
		"last_login_at":         now,
		"last_login_ip":         ip,
		"failed_login_attempts": 0,
		"locked_until":          nil,
	}

	// Remember me duration: 30 days if remember=true, 24 hours if false
	ttl := 24 * time.Hour
	if remember {
		ttl = 30 * 24 * time.Hour
		remToken := uuid.New().String()
		user.RememberToken = &remToken
		updateFields["remember_token"] = remToken
	}

	s.db.WithContext(ctx).Model(&user).Updates(updateFields)

	// 5. Generate secure session token and store in Redis
	sessionToken := uuid.New().String()
	redisKey := fmt.Sprintf("session:%s", sessionToken)
	if err := s.redis.Set(ctx, redisKey, strconv.FormatUint(uint64(user.ID), 10), ttl).Err(); err != nil {
		return nil, "", 0, fmt.Errorf("failed to save session in redis: %w", err)
	}

	s.audit.LogAuth(&user.ID, email, ip, userAgent, "success", nil)
	targetType := "user"
	s.audit.LogAction(&user.ID, "auth.login", "success", &targetType, &user.ID, map[string]interface{}{
		"email": user.Email,
		"role":  user.Role.Name,
		"ip":    ip,
	}, ip, userAgent)

	return &user, sessionToken, ttl, nil
}

// Logout destroys the session token in Redis and logs the event.
func (s *AuthService) Logout(ctx context.Context, sessionToken string, user *models.User, ip, userAgent string) error {
	if sessionToken != "" {
		s.redis.Del(ctx, fmt.Sprintf("session:%s", sessionToken))
	}

	if user != nil {
		s.audit.LogAuth(&user.ID, user.Email, ip, userAgent, "logout", nil)
		targetType := "user"
		s.audit.LogAction(&user.ID, "auth.logout", "success", &targetType, &user.ID, nil, ip, userAgent)
	}

	return nil
}

// ForgotPassword generates a 6-digit verification code, saves it, and emails the user.
func (s *AuthService) ForgotPassword(ctx context.Context, email, ip, userAgent string) error {
	var user models.User
	if err := s.db.WithContext(ctx).Where("email = ?", email).First(&user).Error; err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return ErrUserNotFound
		}
		return err
	}

	if user.Status != "active" {
		return ErrAccountInactive
	}

	// Generate 6-digit verification code (100000 - 999999)
	nBig, err := rand.Int(rand.Reader, big.NewInt(900000))
	if err != nil {
		return fmt.Errorf("failed to generate secure random code: %w", err)
	}
	code := strconv.Itoa(int(nBig.Int64() + 100000))

	hashedCode, err := bcrypt.GenerateFromPassword([]byte(code), bcrypt.DefaultCost)
	if err != nil {
		return fmt.Errorf("failed to hash code: %w", err)
	}

	// Save or update in password_reset_tokens
	now := time.Now()
	tokenRecord := models.PasswordResetToken{
		Email:     user.Email,
		Token:     string(hashedCode),
		CreatedAt: now,
	}

	if err := s.db.WithContext(ctx).Save(&tokenRecord).Error; err != nil {
		return fmt.Errorf("failed to save reset token: %w", err)
	}

	// Send email
	if err := s.mail.SendPasswordResetCode(&user, code); err != nil {
		if s.cfg.AppDebug {
			log.Printf("[MAIL-DEBUG] Mail delivery failed (%v), but in debug mode code is: %s", err, code)
		} else {
			return fmt.Errorf("Gagal mengirim email verifikasi (%v). Pastikan konfigurasi SMTP sistem sudah valid.", err)
		}
	}

	targetType := "user"
	s.audit.LogAction(&user.ID, "auth.password_reset_requested", "success", &targetType, &user.ID, map[string]interface{}{
		"email": user.Email,
		"ip":    ip,
	}, ip, userAgent)

	return nil
}

// ResetPassword validates the code and updates the user's password.
func (s *AuthService) ResetPassword(ctx context.Context, email, code, newPassword, ip, userAgent string) error {
	var record models.PasswordResetToken
	if err := s.db.WithContext(ctx).Where("email = ?", email).First(&record).Error; err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return ErrTokenInvalid
		}
		return err
	}

	// 15-minute expiration
	if time.Since(record.CreatedAt) > 15*time.Minute {
		s.db.WithContext(ctx).Where("email = ?", email).Delete(&models.PasswordResetToken{})
		return ErrTokenExpired
	}

	// Rate limit check: max 5 attempts per email
	attemptKey := fmt.Sprintf("pwd_reset_attempts_%s", md5Hash(email))
	attempts, _ := s.redis.Get(ctx, attemptKey).Int()
	if attempts >= 5 {
		s.db.WithContext(ctx).Where("email = ?", email).Delete(&models.PasswordResetToken{})
		s.redis.Del(ctx, attemptKey)
		return ErrTooManyAttempts
	}

	// Verify code hash
	if err := bcrypt.CompareHashAndPassword([]byte(record.Token), []byte(code)); err != nil {
		s.redis.Incr(ctx, attemptKey)
		s.redis.Expire(ctx, attemptKey, 15*time.Minute)
		remaining := 4 - attempts
		if remaining <= 0 {
			s.db.WithContext(ctx).Where("email = ?", email).Delete(&models.PasswordResetToken{})
			s.redis.Del(ctx, attemptKey)
			return ErrTooManyAttempts
		}
		return fmt.Errorf("Kode verifikasi tidak valid. Sisa percobaan: %d kali.", remaining)
	}

	s.redis.Del(ctx, attemptKey)

	// Hash new password
	hashedPassword, err := bcrypt.GenerateFromPassword([]byte(newPassword), bcrypt.DefaultCost)
	if err != nil {
		return fmt.Errorf("failed to hash password: %w", err)
	}

	var user models.User
	if err := s.db.WithContext(ctx).Where("email = ?", email).First(&user).Error; err != nil {
		return ErrUserNotFound
	}

	now := time.Now()
	user.Password = string(hashedPassword)
	user.PasswordChangedAt = &now
	user.FailedLoginAttempts = 0
	user.LockedUntil = nil

	if err := s.db.WithContext(ctx).Model(&user).Updates(map[string]interface{}{
		"password":              user.Password,
		"password_changed_at":   now,
		"failed_login_attempts": 0,
		"locked_until":          nil,
	}).Error; err != nil {
		return fmt.Errorf("failed to update user password: %w", err)
	}

	// Delete used token
	s.db.WithContext(ctx).Where("email = ?", email).Delete(&models.PasswordResetToken{})

	targetType := "user"
	s.audit.LogAction(&user.ID, "auth.password_reset_completed", "success", &targetType, &user.ID, map[string]interface{}{
		"email": user.Email,
		"ip":    ip,
	}, ip, userAgent)

	return nil
}

// GetUserBySession retrieves the user for a given session token.
func (s *AuthService) GetUserBySession(ctx context.Context, sessionToken string) (*models.User, error) {
	if sessionToken == "" {
		return nil, errors.New("empty session token")
	}

	redisKey := fmt.Sprintf("session:%s", sessionToken)
	userIDStr, err := s.redis.Get(ctx, redisKey).Result()
	if err != nil {
		return nil, errors.New("session not found or expired")
	}

	userID, err := strconv.ParseUint(userIDStr, 10, 64)
	if err != nil {
		return nil, errors.New("invalid user id in session")
	}

	var user models.User
	if err := s.db.WithContext(ctx).
		Preload("Role").
		Preload("Projects").
		Preload("Preference").
		First(&user, userID).Error; err != nil {
		return nil, err
	}

	return &user, nil
}

func strPtr(s string) *string {
	return &s
}

func md5Hash(text string) string {
	hasher := md5.New()
	hasher.Write([]byte(text))
	return hex.EncodeToString(hasher.Sum(nil))
}
