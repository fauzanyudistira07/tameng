package middleware

import (
	"net/http"
	"strings"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
)

func AuthRequired(authService *services.AuthService, cfg *config.Config) gin.HandlerFunc {
	return func(c *gin.Context) {
		var sessionToken string

		// 1. Check session cookie
		if cookie, err := c.Cookie(cfg.SessionCookieName); err == nil && cookie != "" {
			sessionToken = cookie
		}

		// 2. Check Authorization header (Bearer token)
		if sessionToken == "" {
			authHeader := c.GetHeader("Authorization")
			if strings.HasPrefix(authHeader, "Bearer ") {
				sessionToken = strings.TrimPrefix(authHeader, "Bearer ")
			}
		}

		if sessionToken == "" {
			c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
			c.Abort()
			return
		}

		user, err := authService.GetUserBySession(c.Request.Context(), sessionToken)
		if err != nil || user == nil {
			c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
			c.Abort()
			return
		}

		c.Set("user", user)
		c.Set("session_token", sessionToken)
		c.Next()
	}
}

// AuthOptional extracts user if session exists, but does not block unauthenticated requests.
func AuthOptional(authService *services.AuthService, cfg *config.Config) gin.HandlerFunc {
	return func(c *gin.Context) {
		var sessionToken string
		if cookie, err := c.Cookie(cfg.SessionCookieName); err == nil && cookie != "" {
			sessionToken = cookie
		}
		if sessionToken == "" {
			authHeader := c.GetHeader("Authorization")
			if strings.HasPrefix(authHeader, "Bearer ") {
				sessionToken = strings.TrimPrefix(authHeader, "Bearer ")
			}
		}
		if sessionToken != "" {
			if user, err := authService.GetUserBySession(c.Request.Context(), sessionToken); err == nil && user != nil {
				c.Set("user", user)
				c.Set("session_token", sessionToken)
			}
		}
		c.Next()
	}
}
