package utils

import (
	"time"

	"tameng-backend-go/internal/models"
)

type UserResponse struct {
	ID               uint64                  `json:"id"`
	Name             string                  `json:"name"`
	Username         *string                 `json:"username"`
	Email            string                  `json:"email"`
	Phone            *string                 `json:"phone"`
	Department       *string                 `json:"department"`
	AvatarPath       *string                 `json:"avatar_path"`
	AuthProvider     string                  `json:"auth_provider"`
	Status           string                  `json:"status"`
	LastLoginAt      *string                 `json:"last_login_at"`
	TwoFactorEnabled bool                    `json:"two_factor_enabled"`
	Role             *RoleResponse           `json:"role"`
	Projects         []ProjectResponse       `json:"projects,omitempty"`
	Preference       *UserPreferenceResponse `json:"preference,omitempty"`
}

type RoleResponse struct {
	ID          uint64 `json:"id"`
	Name        string `json:"name"`
	DisplayName string `json:"display_name"`
}

type ProjectResponse struct {
	ID          uint64 `json:"id"`
	Name        string `json:"name"`
	Code        string `json:"code"`
	AccessLevel string `json:"access_level"`
}

type UserPreferenceResponse struct {
	AlertEmail       bool    `json:"alert_email"`
	AlertTelegram    bool    `json:"alert_telegram"`
	TelegramChatID   *string `json:"telegram_chat_id"`
	AlertMinSeverity string  `json:"alert_min_severity"`
}

func FormatUserResponse(user *models.User) UserResponse {
	var roleResp *RoleResponse
	if user.Role.ID != 0 {
		roleResp = &RoleResponse{
			ID:          user.Role.ID,
			Name:        user.Role.Name,
			DisplayName: user.Role.DisplayName,
		}
	}

	var lastLogin *string
	if user.LastLoginAt != nil {
		iso := user.LastLoginAt.Format(time.RFC3339)
		lastLogin = &iso
	}

	var projects []ProjectResponse
	for _, p := range user.Projects {
		projects = append(projects, ProjectResponse{
			ID:          p.ID,
			Name:        p.Name,
			Code:        p.Code,
			AccessLevel: "analyst",
		})
	}

	var pref *UserPreferenceResponse
	if user.Preference != nil {
		pref = &UserPreferenceResponse{
			AlertEmail:       user.Preference.AlertEmail,
			AlertTelegram:    user.Preference.AlertTelegram,
			TelegramChatID:   user.Preference.TelegramChatID,
			AlertMinSeverity: user.Preference.AlertMinSeverity,
		}
	}

	return UserResponse{
		ID:               user.ID,
		Name:             user.Name,
		Username:         user.Username,
		Email:            user.Email,
		Phone:            user.Phone,
		Department:       user.Department,
		AvatarPath:       user.AvatarPath,
		AuthProvider:     user.AuthProvider,
		Status:           user.Status,
		LastLoginAt:      lastLogin,
		TwoFactorEnabled: user.TwoFactorEnabled,
		Role:             roleResp,
		Projects:         projects,
		Preference:       pref,
	}
}

type ValidationErrorResponse struct {
	Message string              `json:"message"`
	Errors  map[string][]string `json:"errors"`
}

func NewValidationError(field, message string) ValidationErrorResponse {
	return ValidationErrorResponse{
		Message: message,
		Errors: map[string][]string{
			field: {message},
		},
	}
}
