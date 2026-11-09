package services

import (
	"encoding/json"
	"log"
	"time"

	"tameng-backend-go/internal/models"

	"gorm.io/gorm"
)

type AuditService struct {
	db *gorm.DB
}

func NewAuditService(db *gorm.DB) *AuditService {
	return &AuditService{db: db}
}

func (s *AuditService) LogAuth(userID *uint64, email, ip, userAgent, status string, failureReason *string) {
	entry := models.AuthenticationLog{
		UserID:        userID,
		Email:         email,
		IPAddress:     ip,
		UserAgent:     userAgent,
		Status:        status,
		FailureReason: failureReason,
		CreatedAt:     time.Now(),
	}

	if err := s.db.Create(&entry).Error; err != nil {
		log.Printf("[AUDIT] Failed to write authentication log: %v", err)
	}
}

func (s *AuditService) LogAction(userID *uint64, action, result string, targetType *string, targetID *uint64, metadata map[string]interface{}, ip, userAgent string) {
	var metaJSON *string
	if metadata != nil {
		bytes, err := json.Marshal(metadata)
		if err == nil {
			str := string(bytes)
			metaJSON = &str
		}
	}

	now := time.Now()
	entry := models.AuditLog{
		UserID:     userID,
		Action:     action,
		Result:     result,
		TargetType: targetType,
		TargetID:   targetID,
		Metadata:   metaJSON,
		ActorIP:    ip,
		CreatedAt:  now,
		UpdatedAt:  now,
	}

	if err := s.db.Create(&entry).Error; err != nil {
		log.Printf("[AUDIT] Failed to write audit log: %v", err)
	}
}
