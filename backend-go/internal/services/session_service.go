package services

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"sync"
	"time"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/database"
)

type SessionData struct {
	UserID    uint64    `json:"user_id"`
	Email     string    `json:"email"`
	RoleName  string    `json:"role_name"`
	Remember  bool      `json:"remember"`
	CreatedAt time.Time `json:"created_at"`
}

type SessionService struct {
	cfg       *config.Config
	memoryMap sync.Map
}

var (
	sessionInstance *SessionService
	sessionOnce     sync.Once
)

func GetSessionService(cfg *config.Config) *SessionService {
	sessionOnce.Do(func() {
		sessionInstance = &SessionService{
			cfg: cfg,
		}
	})
	return sessionInstance
}

func (s *SessionService) CreateSession(ctx context.Context, userID uint64, email, roleName string, remember bool) (string, time.Duration, error) {
	bytes := make([]byte, 32)
	if _, err := rand.Read(bytes); err != nil {
		return "", 0, fmt.Errorf("failed to generate random session token: %w", err)
	}
	sessionToken := hex.EncodeToString(bytes)

	var ttl time.Duration
	if remember {
		ttl = 30 * 24 * time.Hour // 30 days
	} else {
		ttl = 24 * time.Hour // 24 hours
	}

	data := SessionData{
		UserID:    userID,
		Email:     email,
		RoleName:  roleName,
		Remember:  remember,
		CreatedAt: time.Now(),
	}

	jsonData, err := json.Marshal(data)
	if err != nil {
		return "", 0, err
	}

	redisKey := fmt.Sprintf("session:%s", sessionToken)
	if database.Redis != nil {
		err = database.Redis.Set(ctx, redisKey, jsonData, ttl).Err()
		if err != nil {
			s.memoryMap.Store(sessionToken, data)
		}
	} else {
		s.memoryMap.Store(sessionToken, data)
	}

	return sessionToken, ttl, nil
}

func (s *SessionService) GetSession(ctx context.Context, sessionToken string) (*SessionData, error) {
	if sessionToken == "" {
		return nil, fmt.Errorf("empty session token")
	}

	if database.Redis != nil {
		redisKey := fmt.Sprintf("session:%s", sessionToken)
		val, err := database.Redis.Get(ctx, redisKey).Result()
		if err == nil {
			var data SessionData
			if err := json.Unmarshal([]byte(val), &data); err == nil {
				return &data, nil
			}
		}
	}

	if val, ok := s.memoryMap.Load(sessionToken); ok {
		if data, ok := val.(SessionData); ok {
			return &data, nil
		}
	}

	return nil, fmt.Errorf("session not found or expired")
}

func (s *SessionService) DestroySession(ctx context.Context, sessionToken string) error {
	if sessionToken == "" {
		return nil
	}

	if database.Redis != nil {
		redisKey := fmt.Sprintf("session:%s", sessionToken)
		_ = database.Redis.Del(ctx, redisKey).Err()
	}

	s.memoryMap.Delete(sessionToken)
	return nil
}
