package main

import (
	"context"
	"fmt"
	"log"
	"net/http"

	"tameng-backend-go/internal/config"
	"tameng-backend-go/internal/database"
	"tameng-backend-go/internal/handlers"
	"tameng-backend-go/internal/middleware"
	"tameng-backend-go/internal/scanner"
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
	searchService := services.NewSearchService(db)
	notifService := services.NewNotificationService(db, rdb)
	pdfService := services.NewPDFService(db)
	neurolinkService := services.NewNeurolinkService(db, rdb)
	aiRemediationService := services.NewAiRemediationService(db, rdb, cfg, neurolinkService)

	// 4. Initialize Handlers
	authHandler := handlers.NewAuthHandler(authService, cfg)
	searchHandler := handlers.NewSearchHandler(searchService)
	notifHandler := handlers.NewNotificationHandler(notifService)
	overviewHandler := handlers.NewOverviewHandler(db)
	roleHandler := handlers.NewRoleHandler(db)
	auditHandler := handlers.NewAuditHandler(db)
	userMgmtHandler := handlers.NewUserManagementHandler(db, auditService)
	projectHandler := handlers.NewProjectHandler(db)
	repoHandler := handlers.NewRepositoryHandler(db)
	targetHandler := handlers.NewTargetHandler(db)
	scopeHandler := handlers.NewScopeHandler(db)
	scanProfileHandler := handlers.NewScanProfileHandler(db)
	authzHandler := handlers.NewAuthorizationHandler(db)
	scanJobHandler := handlers.NewScanJobHandler(db)
	findingHandler := handlers.NewFindingHandler(db, aiRemediationService, neurolinkService)
	reportHandler := handlers.NewReportHandler(db, pdfService)
	engineHandler := handlers.NewEngineHandler(db)
	gitWebhookHandler := handlers.NewGitWebhookHandler(db)
	neurolinkHandler := handlers.NewNeurolinkHandler(neurolinkService)

	// 5. Start Background Native Docker Scanner Worker
	scannerRunner := scanner.NewRunner(db, cfg)
	workerCtx, cancelWorker := context.WithCancel(context.Background())
	defer cancelWorker()
	go scannerRunner.StartWorker(workerCtx)

	// 6. Setup Gin Router
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

	// Public Auth & Webhook Endpoints
	api := r.Group("/api")
	{
		api.POST("/login", authHandler.Login)
		api.POST("/forgot-password", authHandler.ForgotPassword)
		api.POST("/reset-password", authHandler.ResetPassword)
		api.POST("/webhooks/git", gitWebhookHandler.Handle)
		api.GET("/search", middleware.AuthOptional(authService, cfg), searchHandler.Search)
	}

	// Protected Endpoints
	protected := api.Group("")
	protected.Use(middleware.AuthRequired(authService, cfg))
	{
		// 1. User Profile & Preferences
		protected.GET("/user", authHandler.Me)
		protected.PUT("/user", authHandler.UpdateProfile)
		protected.POST("/logout", authHandler.Logout)

		// 2. System Overview
		protected.GET("/overview", overviewHandler.GetOverview)

		// 3. Security Notifications
		protected.GET("/notifications", notifHandler.List)
		protected.POST("/notifications/:id/read", notifHandler.MarkRead)
		protected.POST("/notifications/read-all", notifHandler.MarkAllRead)
		protected.POST("/notifications/:id/star", notifHandler.ToggleStar)
		protected.DELETE("/notifications/:id", notifHandler.Dismiss)

		// 4. Developer Scan Requests (Self-Service)
		protected.GET("/my/scan-requests", scanJobHandler.MyScanRequests)
		protected.POST("/my/scan-requests", scanJobHandler.StoreMyScanRequest)
		protected.POST("/my/scan-requests/:id/rerun", scanJobHandler.Rerun)
		protected.POST("/my/scan-requests/:id/cancel", scanJobHandler.Cancel)
		protected.POST("/my/scan-requests/:id/approve", scanJobHandler.ApproveMyScanRequest)
		protected.POST("/my/scan-requests/:id/reject", scanJobHandler.RejectMyScanRequest)

		// 5. Security Engines Suite
		protected.GET("/security/engines", engineHandler.List)
		protected.POST("/security/engines/sync", engineHandler.SyncAll)
		protected.GET("/security/engines/:id", engineHandler.Show)
		protected.POST("/security/engines/:id/toggle", engineHandler.Toggle)
		protected.POST("/security/engines/:id/health-check", engineHandler.HealthCheck)
		protected.POST("/security/engines/:id/pull-image", engineHandler.PullImage)
		protected.GET("/security/scan-profiles", engineHandler.ScanProfiles)
		protected.GET("/engines", engineHandler.List)
		protected.GET("/engines/:engineKey", engineHandler.Show)

		// 6. Audit Logs & Roles
		protected.GET("/audit-logs", auditHandler.List)
		protected.GET("/roles", roleHandler.List)

		// 7. User Management (Admin)
		protected.GET("/users", userMgmtHandler.List)
		protected.POST("/users", userMgmtHandler.Store)
		protected.PUT("/users/:id", userMgmtHandler.Update)
		protected.POST("/users/:id/unlock", userMgmtHandler.Unlock)
		protected.POST("/users/:id/projects", userMgmtHandler.AssignProjects)
		protected.DELETE("/users/:id", userMgmtHandler.Destroy)

		// 8. Project Management
		protected.GET("/projects", projectHandler.List)
		protected.GET("/projects/:id", projectHandler.Show)
		protected.POST("/projects", projectHandler.Store)
		protected.PUT("/projects/:id", projectHandler.Update)

		// 9. Repositories
		protected.GET("/repositories", repoHandler.List)
		protected.GET("/repositories/:id", repoHandler.Show)
		protected.POST("/repositories", repoHandler.Store)
		protected.PUT("/repositories/:id", repoHandler.Update)
		protected.POST("/repositories/:id/verify", repoHandler.Verify)
		protected.DELETE("/repositories/:id", repoHandler.Destroy)
		protected.POST("/repositories/:id/scan", repoHandler.Scan)

		// 10. Targets
		protected.GET("/targets", targetHandler.List)
		protected.GET("/targets/:id", targetHandler.Show)
		protected.POST("/targets", targetHandler.Store)
		protected.PUT("/targets/:id", targetHandler.Update)
		protected.DELETE("/targets/:id", targetHandler.Destroy)
		protected.POST("/targets/:id/verify", targetHandler.Verify)
		protected.POST("/targets/:id/scan", targetHandler.Scan)

		// 11. Scopes
		protected.GET("/scopes", scopeHandler.List)
		protected.POST("/scopes", scopeHandler.Store)
		protected.PUT("/scopes/:id", scopeHandler.Update)

		// 12. Scan Profiles
		protected.GET("/scan-profiles", scanProfileHandler.List)

		// 13. Authorizations
		protected.GET("/authorizations", authzHandler.List)
		protected.POST("/authorizations", authzHandler.Store)

		// 14. Scan Jobs
		protected.GET("/scan-jobs", scanJobHandler.List)
		protected.POST("/scan-jobs", scanJobHandler.Store)
		protected.POST("/scan-jobs/:id/rerun", scanJobHandler.Rerun)
		protected.POST("/scan-jobs/:id/cancel", scanJobHandler.Cancel)
		protected.POST("/scan-jobs/:id/process-simulated", scanJobHandler.ProcessSimulated)

		// 15. Findings
		protected.GET("/findings", findingHandler.List)
		protected.GET("/findings/:id", findingHandler.Show)
		protected.GET("/findings/:id/ai-remediation", findingHandler.AIRemediation)
		protected.PUT("/findings/:id", findingHandler.Update)

		// 15.1 NeuroLink Continuous Learning Synaptic Hub
		neurolink := protected.Group("/neurolink")
		{
			neurolink.GET("/stats", neurolinkHandler.Stats)
			neurolink.GET("/synapses", neurolinkHandler.ListSynapses)
			neurolink.POST("/feedback", neurolinkHandler.SubmitFeedback)
		}

		// 16. Reports
		protected.GET("/reports", reportHandler.List)
		protected.POST("/reports", reportHandler.Store)
		protected.GET("/reports/:id", reportHandler.Show)
		protected.POST("/reports/:id/sync", reportHandler.Sync)
		protected.GET("/reports/:id/download-pdf", reportHandler.DownloadPDF)
	}

	addr := fmt.Sprintf(":%s", cfg.Port)
	log.Printf("[SERVER] Starting TAMENG Go Backend on http://0.0.0.0%s", addr)
	if err := r.Run(addr); err != nil {
		log.Fatalf("[FATAL] Server terminated unexpectedly: %v", err)
	}
}
