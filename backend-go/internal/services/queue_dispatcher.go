package services

import (
	"encoding/json"
	"fmt"
	"time"

	"github.com/google/uuid"
	"gorm.io/gorm"
)

type JobRecord struct {
	ID          uint64  `gorm:"primaryKey;autoIncrement" json:"id"`
	Queue       string  `gorm:"size:255;not null" json:"queue"`
	Payload     string  `gorm:"type:longtext;not null" json:"payload"`
	Attempts    uint8   `gorm:"not null" json:"attempts"`
	ReservedAt  *uint32 `json:"reserved_at"`
	AvailableAt uint32  `gorm:"not null" json:"available_at"`
	CreatedAt   uint32  `gorm:"not null" json:"created_at"`
}

func (JobRecord) TableName() string {
	return "jobs"
}

// DispatchRunScanJob enqueues a scan execution into the MySQL jobs table
// so that the background scan worker runner processes it immediately.
func DispatchRunScanJob(db *gorm.DB, scanJobID uint64, userID uint64) error {
	nowUnix := uint32(time.Now().Unix())
	jobUUID := uuid.New().String()

	phpCommand := fmt.Sprintf(`O:19:"App\Jobs\RunScanJob":3:{s:9:"scanJobId";i:%d;s:6:"userId";i:%d;s:11:"afterCommit";b:1;}`, scanJobID, userID)

	payloadMap := map[string]interface{}{
		"uuid":          jobUUID,
		"displayName":   "App\\Jobs\\RunScanJob",
		"job":           "Illuminate\\Queue\\CallQueuedHandler@call",
		"maxTries":      1,
		"maxExceptions": nil,
		"failOnTimeout": false,
		"backoff":       nil,
		"timeout":       1800,
		"retryUntil":    nil,
		"data": map[string]interface{}{
			"commandName": "App\\Jobs\\RunScanJob",
			"command":     phpCommand,
			"batchId":     nil,
		},
		"createdAt": nowUnix,
		"delay":     nil,
	}

	payloadBytes, err := json.Marshal(payloadMap)
	if err != nil {
		return err
	}

	job := JobRecord{
		Queue:       "default",
		Payload:     string(payloadBytes),
		Attempts:    0,
		ReservedAt:  nil,
		AvailableAt: nowUnix,
		CreatedAt:   nowUnix,
	}

	return db.Create(&job).Error
}

