package handlers

import (
	"net/http"
	"tameng-backend-go/internal/models"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
)

type NotificationHandler struct {
	notifService *services.NotificationService
}

func NewNotificationHandler(notifService *services.NotificationService) *NotificationHandler {
	return &NotificationHandler{notifService: notifService}
}

// List handles GET /api/notifications
func (h *NotificationHandler) List(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	items, err := h.notifService.GetNotifications(c.Request.Context(), user)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{
			"message": "Gagal memuat notifikasi.",
			"error":   err.Error(),
		})
		return
	}

	unreadCount := 0
	starredCount := 0
	for _, item := range items {
		if !item.IsRead {
			unreadCount++
		}
		if item.IsStarred {
			starredCount++
		}
	}

	c.JSON(http.StatusOK, gin.H{
		"notifications": items,
		"unread_count":  unreadCount,
		"starred_count": starredCount,
		"total":         len(items),
	})
}

// MarkRead handles POST /api/notifications/:id/read
func (h *NotificationHandler) MarkRead(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)
	notifID := c.Param("id")

	if err := h.notifService.MarkAsRead(c.Request.Context(), user.ID, notifID); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menandai notifikasi dibaca."})
		return
	}

	c.JSON(http.StatusOK, gin.H{"message": "Notifikasi ditandai dibaca."})
}

// MarkAllRead handles POST /api/notifications/read-all
func (h *NotificationHandler) MarkAllRead(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req struct {
		IDs []string `json:"ids"`
	}
	_ = c.ShouldBindJSON(&req)

	if len(req.IDs) == 0 {
		// Fetch current list to get IDs
		items, _ := h.notifService.GetNotifications(c.Request.Context(), user)
		for _, item := range items {
			req.IDs = append(req.IDs, item.ID)
		}
	}

	if err := h.notifService.MarkAllAsRead(c.Request.Context(), user.ID, req.IDs); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menandai semua notifikasi dibaca."})
		return
	}

	c.JSON(http.StatusOK, gin.H{"message": "Seluruh notifikasi berhasil ditandai telah dibaca."})
}

// ToggleStar handles POST /api/notifications/:id/star
func (h *NotificationHandler) ToggleStar(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)
	notifID := c.Param("id")

	isStarred, err := h.notifService.ToggleStar(c.Request.Context(), user.ID, notifID)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengubah bintang notifikasi."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"message":    "Status bintang diperbarui.",
		"is_starred": isStarred,
	})
}

// Dismiss handles DELETE /api/notifications/:id
func (h *NotificationHandler) Dismiss(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)
	notifID := c.Param("id")

	if err := h.notifService.Dismiss(c.Request.Context(), user.ID, notifID); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal menghapus notifikasi."})
		return
	}

	c.JSON(http.StatusOK, gin.H{"message": "Notifikasi berhasil dihapus."})
}

