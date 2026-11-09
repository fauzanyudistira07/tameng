package handlers

import (
	"net/http"
	"tameng-backend-go/internal/models"
	"tameng-backend-go/internal/services"

	"github.com/gin-gonic/gin"
)

type SearchHandler struct {
	searchService *services.SearchService
}

func NewSearchHandler(searchService *services.SearchService) *SearchHandler {
	return &SearchHandler{searchService: searchService}
}

// Search handles GET /api/search?q={query}
func (h *SearchHandler) Search(c *gin.Context) {
	q := c.Query("q")
	var user *models.User
	if val, exists := c.Get("user"); exists {
		user = val.(*models.User)
	}

	results, err := h.searchService.Search(c.Request.Context(), q, user)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{
			"message": "Gagal melakukan pencarian.",
			"error":   err.Error(),
		})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"query":   q,
		"results": results,
		"total":   len(results),
	})
}

