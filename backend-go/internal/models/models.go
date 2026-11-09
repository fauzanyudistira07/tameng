package models

import (
	"time"
)

type User struct {
	ID                  uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	Name                string     `gorm:"size:255;not null" json:"name"`
	Username            *string    `gorm:"size:100;unique" json:"username"`
	Email               string     `gorm:"size:255;unique;not null" json:"email"`
	Phone               *string    `gorm:"size:25" json:"phone"`
	Department          *string    `gorm:"size:150" json:"department"`
	AvatarPath          *string    `gorm:"size:255" json:"avatar_path"`
	AuthProvider        string     `gorm:"size:50;default:'local'" json:"auth_provider"`
	ProviderID          *string    `gorm:"size:255" json:"provider_id"`
	EmailVerifiedAt     *time.Time `json:"email_verified_at"`
	Password            string     `gorm:"size:255;not null" json:"-"`
	PasswordChangedAt   *time.Time `json:"password_changed_at"`
	Status              string     `gorm:"size:30;default:'active'" json:"status"`
	FailedLoginAttempts int        `gorm:"default:0" json:"failed_login_attempts"`
	LockedUntil         *time.Time `json:"locked_until"`
	TwoFactorEnabled    bool       `gorm:"default:false" json:"two_factor_enabled"`
	LastLoginAt         *time.Time `json:"last_login_at"`
	LastLoginIP         *string    `gorm:"size:45" json:"last_login_ip"`
	CreatedBy           *uint64    `json:"created_by"`
	RememberToken       *string    `gorm:"size:100" json:"remember_token"`
	RoleID              uint64     `gorm:"not null" json:"role_id"`
	CreatedAt           time.Time  `json:"created_at"`
	UpdatedAt           time.Time  `json:"updated_at"`
	DeletedAt           *time.Time `gorm:"index" json:"deleted_at,omitempty"`

	// Relationships
	Role       Role            `gorm:"foreignKey:RoleID" json:"role"`
	Projects   []Project       `gorm:"many2many:project_user;" json:"projects"`
	Preference *UserPreference `gorm:"foreignKey:UserID" json:"preference"`
}

type Role struct {
	ID          uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	Name        string    `gorm:"size:50;unique;not null" json:"name"`
	DisplayName string    `gorm:"size:100;not null" json:"display_name"`
	Description *string   `gorm:"size:255" json:"description"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

type Project struct {
	ID          uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	Name        string    `gorm:"size:255;not null" json:"name"`
	Code        string    `gorm:"size:100;unique;not null" json:"code"`
	Description *string   `json:"description"`
	OwnerID     uint64    `gorm:"not null" json:"owner_id"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

type UserPreference struct {
	ID               uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	UserID           uint64    `gorm:"unique;not null" json:"user_id"`
	AlertEmail       bool      `gorm:"default:true" json:"alert_email"`
	AlertTelegram    bool      `gorm:"default:false" json:"alert_telegram"`
	TelegramChatID   *string   `gorm:"size:100" json:"telegram_chat_id"`
	AlertMinSeverity string    `gorm:"size:30;default:'high'" json:"alert_min_severity"`
	CreatedAt        time.Time `json:"created_at"`
	UpdatedAt        time.Time `json:"updated_at"`
}

type PasswordResetToken struct {
	Email     string    `gorm:"primaryKey;size:255" json:"email"`
	Token     string    `gorm:"size:255;not null" json:"token"`
	CreatedAt time.Time `gorm:"not null" json:"created_at"`
}

type AuthenticationLog struct {
	ID            uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	UserID        *uint64   `json:"user_id"`
	Email         string    `gorm:"size:255;not null" json:"email"`
	IPAddress     string    `gorm:"size:45;not null" json:"ip_address"`
	UserAgent     string    `gorm:"type:text;not null" json:"user_agent"`
	Status        string    `gorm:"size:30;not null" json:"status"`
	FailureReason *string   `gorm:"size:100" json:"failure_reason"`
	CreatedAt     time.Time `json:"created_at"`
}

type AuditLog struct {
	ID         uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	UserID     *uint64   `json:"user_id"`
	Action     string    `gorm:"size:100;not null" json:"action"`
	Result     string    `gorm:"column:result;size:30;not null" json:"result"`
	ActorIP    string    `gorm:"column:actor_ip;size:45;not null" json:"actor_ip"`
	TargetType *string   `gorm:"size:50" json:"target_type"`
	TargetID   *uint64   `json:"target_id"`
	Metadata   *string   `gorm:"type:json" json:"metadata"`
	CreatedAt  time.Time `json:"created_at"`
	UpdatedAt  time.Time `json:"updated_at"`
}
