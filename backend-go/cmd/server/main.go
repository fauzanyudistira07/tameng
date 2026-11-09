package main

import (
	"fmt"
	"log"
	"net/http"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/database"
	"tameng-backend-go/internal/handlers"
	"tameng-backend-go/internal/middleware"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
)

func main() {
	log.Println("==================================================")
	log.Println("  TAMENG BACKEND GO — High-Performance API Service")
	log.Println("==================================================")

	// 1. Load Configuration
	cfg := config.LoadConfig()

	if !cfg.AppDebug {
		gin.SetMode(gin.ReleaseMode)
	}

	// 2. Initialize Database & Redis
	db, err := database.InitDatabase(cfg)
	if err != nil {
		log.Fatalf("[FATAL] Could not initialize database: %v", err)
	}

	rdb, err := database.InitRedis(cfg)
	if err != nil {
		log.Printf("[WARNING] Redis initialization warning: %v", err)
	}

	// 3. Initialize Services
	mailService := services.NewMailService(cfg)
	auditService := services.NewAuditService(db)
	authService := services.NewAuthService(db, rdb, cfg, mailService, auditService)

	// 4. Initialize Handlers
	authHandler := handlers.NewAuthHandler(authService, cfg)

	// 5. Setup Gin Router
	r := gin.New()
	r.Use(gin.Recovery())
	r.Use(gin.Logger())
	r.Use(middleware.CORSMiddleware())

	// Health Check
	r.GET("/api/health", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{
			"status":   "ok",
			"engine":   "tameng-backend-go",
			"version":  "1.0.0",
			"database": "connected",
		})
	})

	// Sanctum CSRF Cookie compatibility
	r.GET("/sanctum/csrf-cookie", authHandler.CsrfCookie)

	// Public Auth Endpoints
	api := r.Group("/api")
	{
		api.POST("/login", authHandler.Login)
		api.POST("/forgot-password", authHandler.ForgotPassword)
		api.POST("/reset-password", authHandler.ResetPassword)
	}

	// Protected Auth Endpoints
	protected := api.Group("")
	protected.Use(middleware.AuthRequired(authService, cfg))
	{
		protected.GET("/user", authHandler.Me)
		protected.POST("/logout", authHandler.Logout)
	}

	addr := fmt.Sprintf(":%s", cfg.Port)
	log.Printf("[SERVER] Starting TAMENG Go Backend on http://0.0.0.0%s", addr)
	if err := r.Run(addr); err != nil {
		log.Fatalf("[FATAL] Server terminated unexpectedly: %v", err)
	}
}
