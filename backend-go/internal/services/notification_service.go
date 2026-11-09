package services

import (
	"context"
	"fmt"
	"sort"
	"strings"
	"time"

	"tameng-backend-go/internal/models"

	"github.com/redis/go-redis/v9"
	"gorm.io/gorm"
)

type NotificationItem struct {
	ID          string `json:"id"`
	Title       string `json:"title"`
	Description string `json:"description"`
	Time        string `json:"time"`
	Timestamp   int64  `json:"timestamp"`
	Category    string `json:"category"`
	StatusDot   string `json:"statusDot"`
	Badge       string `json:"badge"`
	BadgeClass  string `json:"badgeClass"`
	IsRead      bool   `json:"isRead"`
	IsStarred   bool   `json:"isStarred"`
	URL         string `json:"url"`
}

type NotificationService struct {
	db    *gorm.DB
	redis *redis.Client
}

func NewNotificationService(db *gorm.DB, rdb *redis.Client) *NotificationService {
	return &NotificationService{
		db:    db,
		redis: rdb,
	}
}

func (s *NotificationService) formatRelativeTime(t time.Time) string {
	diff := time.Since(t)
	if diff < time.Minute {
		return "Baru saja"
	}
	if diff < time.Hour {
		return fmt.Sprintf("%d menit yang lalu", int(diff.Minutes()))
	}
	if diff < 24*time.Hour {
		return fmt.Sprintf("%d jam yang lalu", int(diff.Hours()))
	}
	if diff < 48*time.Hour {
		return "Kemarin"
	}
	return t.Format("02 Jan 2006")
}

// GetNotifications compiles real security alerts for the user and checks read/star status in Redis.
func (s *NotificationService) GetNotifications(ctx context.Context, user *models.User) ([]NotificationItem, error) {
	var items []NotificationItem

	var isSuperOrSecAdmin bool
	var allowedProjectIDs []uint64
	var userID uint64
	if user != nil {
		userID = user.ID
		roleName := strings.ToLower(user.Role.Name)
		isSuperOrSecAdmin = (roleName == "super_admin" || roleName == "security_admin")
		if !isSuperOrSecAdmin {
			s.db.WithContext(ctx).Table("project_user").Where("user_id = ?", user.ID).Pluck("project_id", &allowedProjectIDs)
		}
	}

	// 1. Fetch Read, Starred, and Dismissed sets from Redis
	readKey := fmt.Sprintf("user:%d:notif_read", userID)
	starredKey := fmt.Sprintf("user:%d:notif_starred", userID)
	dismissedKey := fmt.Sprintf("user:%d:notif_dismissed", userID)

	readSet, _ := s.redis.SMembers(ctx, readKey).Result()
	starredSet, _ := s.redis.SMembers(ctx, starredKey).Result()
	dismissedSet, _ := s.redis.SMembers(ctx, dismissedKey).Result()

	readMap := make(map[string]bool)
	for _, id := range readSet {
		readMap[id] = true
	}
	starredMap := make(map[string]bool)
	for _, id := range starredSet {
		starredMap[id] = true
	}
	dismissedMap := make(map[string]bool)
	for _, id := range dismissedSet {
		dismissedMap[id] = true
	}

	// 2. Query High & Critical Findings
	var findings []models.Finding
	fQuery := s.db.WithContext(ctx).
		Preload("Project").
		Where("LOWER(severity) IN (?)", []string{"critical", "high"})
	if !isSuperOrSecAdmin && len(allowedProjectIDs) > 0 {
		fQuery = fQuery.Where("project_id IN (?)", allowedProjectIDs)
	} else if !isSuperOrSecAdmin && user != nil {
		fQuery = fQuery.Where("1 = 0")
	}
	fQuery.Order("id DESC").Limit(10).Find(&findings)

	for _, f := range findings {
		notifID := fmt.Sprintf("notif-fnd-%d", f.ID)
		if dismissedMap[notifID] {
			continue
		}

		isCrit := strings.ToLower(f.Severity) == "critical"
		dot := "status-dot-warning"
		badgeCls := "bg-orange-lt text-orange"
		if isCrit {
			dot = "status-dot-danger"
			badgeCls = "bg-red-lt text-red"
		}

		ruleName := f.Title
		if ruleName == "" && f.RuleID != nil {
			ruleName = *f.RuleID
		}
		if ruleName == "" {
			ruleName = "Kerentanan Terdeteksi"
		}

		projectName := "Aset"
		if f.Project.Name != "" {
			projectName = f.Project.Name
		}

		items = append(items, NotificationItem{
			ID:          notifID,
			Title:       fmt.Sprintf("[%s] %s", strings.ToUpper(f.Severity), ruleName),
			Description: fmt.Sprintf("Terdeteksi pada %s (Proyek: %s)", f.AssetIdentifier, projectName),
			Time:        s.formatRelativeTime(f.CreatedAt),
			Timestamp:   f.CreatedAt.UnixMilli(),
			Category:    "Celah Keamanan",
			StatusDot:   dot,
			Badge:       strings.ToUpper(f.Severity),
			BadgeClass:  badgeCls,
			IsRead:      readMap[notifID],
			IsStarred:   starredMap[notifID],
			URL:         "/findings",
		})
	}

	// 3. Query Scan Jobs (Completed or Failed)
	var jobs []models.ScanJob
	jQuery := s.db.WithContext(ctx).
		Preload("Project").
		Where("status IN (?)", []string{"completed", "failed", "running"})
	if !isSuperOrSecAdmin && len(allowedProjectIDs) > 0 {
		jQuery = jQuery.Where("project_id IN (?)", allowedProjectIDs)
	} else if !isSuperOrSecAdmin && user != nil {
		jQuery = jQuery.Where("1 = 0")
	}
	jQuery.Order("id DESC").Limit(6).Find(&jobs)

	for _, j := range jobs {
		notifID := fmt.Sprintf("notif-job-%d", j.ID)
		if dismissedMap[notifID] {
			continue
		}

		statusDot := "status-dot-info"
		badgeCls := "bg-azure-lt text-azure"
		title := fmt.Sprintf("Pekerjaan Scan %s", j.Code)
		desc := fmt.Sprintf("Sedang berjalan (%d%%)", j.Progress)

		switch strings.ToLower(j.Status) {
		case "completed":
			statusDot = "status-dot-success"
			badgeCls = "bg-green-lt text-green"
			title = fmt.Sprintf("Pemindaian Selesai: %s", j.Code)
			desc = "Seluruh 20 engine telah rampung dieksekusi."
		case "failed":
			statusDot = "status-dot-danger"
			badgeCls = "bg-red-lt text-red"
			title = fmt.Sprintf("Pemindaian Gagal: %s", j.Code)
			if j.FailureReason != nil && *j.FailureReason != "" {
				desc = *j.FailureReason
			} else {
				desc = "Terjadi kegagalan pada saat eksekusi container sandbox."
			}
		}

		items = append(items, NotificationItem{
			ID:          notifID,
			Title:       title,
			Description: desc,
			Time:        s.formatRelativeTime(j.UpdatedAt),
			Timestamp:   j.UpdatedAt.UnixMilli(),
			Category:    "Pekerjaan Scan",
			StatusDot:   statusDot,
			Badge:       strings.ToUpper(j.Status),
			BadgeClass:  badgeCls,
			IsRead:      readMap[notifID],
			IsStarred:   starredMap[notifID],
			URL:         "/scan-jobs",
		})
	}

	// 4. Query Auth Security Events (Blocked / Locked)
	var authLogs []models.AuthenticationLog
	s.db.WithContext(ctx).
		Where("status IN (?)", []string{"blocked", "locked"}).
		Order("id DESC").Limit(3).Find(&authLogs)

	for _, a := range authLogs {
		notifID := fmt.Sprintf("notif-auth-%d", a.ID)
		if dismissedMap[notifID] {
			continue
		}

		items = append(items, NotificationItem{
			ID:          notifID,
			Title:       "Peringatan Brute Force Terdeteksi",
			Description: fmt.Sprintf("Upaya login mencurigakan diblokir untuk akun %s dari IP %s", a.Email, a.IPAddress),
			Time:        s.formatRelativeTime(a.CreatedAt),
			Timestamp:   a.CreatedAt.UnixMilli(),
			Category:    "Tata Kelola",
			StatusDot:   "status-dot-danger",
			Badge:       "SECURITY ALERT",
			BadgeClass:  "bg-red-lt text-red",
			IsRead:      readMap[notifID],
			IsStarred:   starredMap[notifID],
			URL:         "/audit-logs",
		})
	}

	// 5. Sort by Timestamp descending
	sort.Slice(items, func(i, j int) bool {
		return items[i].Timestamp > items[j].Timestamp
	})

	return items, nil
}

func (s *NotificationService) MarkAsRead(ctx context.Context, userID uint64, notifID string) error {
	key := fmt.Sprintf("user:%d:notif_read", userID)
	return s.redis.SAdd(ctx, key, notifID).Err()
}

func (s *NotificationService) MarkAllAsRead(ctx context.Context, userID uint64, notifIDs []string) error {
	if len(notifIDs) == 0 {
		return nil
	}
	key := fmt.Sprintf("user:%d:notif_read", userID)
	args := make([]interface{}, len(notifIDs))
	for i, id := range notifIDs {
		args[i] = id
	}
	return s.redis.SAdd(ctx, key, args...).Err()
}

func (s *NotificationService) ToggleStar(ctx context.Context, userID uint64, notifID string) (bool, error) {
	key := fmt.Sprintf("user:%d:notif_starred", userID)
	isMember, err := s.redis.SIsMember(ctx, key, notifID).Result()
	if err != nil {
		return false, err
	}
	if isMember {
		err := s.redis.SRem(ctx, key, notifID).Err()
		return false, err
	}
	err = s.redis.SAdd(ctx, key, notifID).Err()
	return true, err
}

func (s *NotificationService) Dismiss(ctx context.Context, userID uint64, notifID string) error {
	key := fmt.Sprintf("user:%d:notif_dismissed", userID)
	return s.redis.SAdd(ctx, key, notifID).Err()
}

