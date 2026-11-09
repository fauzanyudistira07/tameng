package handlers

import (
	"net/http"
	"strconv"

	"tameng-backend-go/internal/models"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
)

type NeurolinkHandler struct {
	neurolinkService *services.NeurolinkService
}

func NewNeurolinkHandler(neurolinkService *services.NeurolinkService) *NeurolinkHandler {
	return &NeurolinkHandler{
		neurolinkService: neurolinkService,
	}
}

// Stats handles GET /api/neurolink/stats
func (h *NeurolinkHandler) Stats(c *gin.Context) {
	stats := h.neurolinkService.GetNeuroLinkStats()
	c.JSON(http.StatusOK, gin.H{
		"neurolink": stats,
	})
}

// ListSynapses handles GET /api/neurolink/synapses
func (h *NeurolinkHandler) ListSynapses(c *gin.Context) {
	search := c.Query("search")
	category := c.Query("category")
	limit, _ := strconv.Atoi(c.DefaultQuery("limit", "50"))

	synapses, err := h.neurolinkService.ListSynapses(search, category, limit)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"message": "Gagal mengambil jaringan sinapsis NeuroLink."})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"synapses": synapses,
		"count":    len(synapses),
	})
}

// SubmitFeedback handles POST /api/neurolink/feedback
func (h *NeurolinkHandler) SubmitFeedback(c *gin.Context) {
	val, exists := c.Get("user")
	if !exists {
		c.JSON(http.StatusUnauthorized, gin.H{"message": "Unauthenticated."})
		return
	}
	user := val.(*models.User)

	var req struct {
		FindingID   uint64 `json:"finding_id" binding:"required"`
		Rating      string `json:"rating" binding:"required"` // helpful, needs_improvement, false_positive
		Notes       string `json:"notes"`
		CustomPatch string `json:"custom_patch"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"message": "Payload feedback tidak valid."})
		return
	}

	synapse, err := h.neurolinkService.RecordFeedback(user.ID, req.FindingID, req.Rating, req.Notes, req.CustomPatch)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"message": err.Error()})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"message": "Feedback berhasil diserap ke dalam memori sinaptik NeuroLink.",
		"synapse": synapse,
	})
}
