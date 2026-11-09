package models

import (
	"encoding/json"
	"time"
)

type User struct {
	ID                  uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	Name                string     `gorm:"size:255;not null" json:"name"`
	Username            *string    `gorm:"size:100;unique" json:"username"`
	Email               string     `gorm:"size:255;unique;not null" json:"email"`
	Phone               *string    `gorm:"size:25" json:"phone"`
	Department          *string    `gorm:"size:150" json:"department"`
	AvatarPath          *string    `gorm:"size:255" json:"avatar_path"`
	AuthProvider        string     `gorm:"size:50;default:'local'" json:"auth_provider"`
	ProviderID          *string    `gorm:"size:255" json:"provider_id"`
	EmailVerifiedAt     *time.Time `json:"email_verified_at"`
	Password            string     `gorm:"size:255;not null" json:"-"`
	PasswordChangedAt   *time.Time `json:"password_changed_at"`
	Status              string     `gorm:"size:30;default:'active'" json:"status"`
	FailedLoginAttempts int        `gorm:"default:0" json:"failed_login_attempts"`
	LockedUntil         *time.Time `json:"locked_until"`
	TwoFactorEnabled    bool       `gorm:"default:false" json:"two_factor_enabled"`
	LastLoginAt         *time.Time `json:"last_login_at"`
	LastLoginIP         *string    `gorm:"size:45" json:"last_login_ip"`
	CreatedBy           *uint64    `json:"created_by"`
	RememberToken       *string    `gorm:"size:100" json:"remember_token"`
	RoleID              uint64     `gorm:"not null" json:"role_id"`
	CreatedAt           time.Time  `json:"created_at"`
	UpdatedAt           time.Time  `json:"updated_at"`
	DeletedAt           *time.Time `gorm:"index" json:"deleted_at,omitempty"`

	// Relationships
	Role       Role            `gorm:"foreignKey:RoleID" json:"role"`
	Projects   []Project       `gorm:"many2many:project_user;" json:"projects"`
	Preference *UserPreference `gorm:"foreignKey:UserID" json:"preference"`
}

type Role struct {
	ID          uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	Name        string    `gorm:"size:50;unique;not null" json:"name"`
	DisplayName string    `gorm:"size:100;not null" json:"display_name"`
	Description *string   `gorm:"size:255" json:"description"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

type Project struct {
	ID          uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	Name        string    `gorm:"size:255;not null" json:"name"`
	Code        string    `gorm:"size:100;unique;not null" json:"code"`
	Description *string   `gorm:"type:text" json:"description"`
	Criticality string    `gorm:"size:50;default:'medium'" json:"criticality"`
	Status      string    `gorm:"size:50;default:'active'" json:"status"`
	OwnerID     uint64    `gorm:"not null" json:"owner_id"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`

	Owner          *User           `gorm:"foreignKey:OwnerID" json:"owner,omitempty"`
	Authorizations []Authorization `gorm:"foreignKey:ProjectID" json:"authorizations,omitempty"`
}

type ProjectUser struct {
	ProjectID   uint64 `gorm:"primaryKey" json:"project_id"`
	UserID      uint64 `gorm:"primaryKey" json:"user_id"`
	AccessLevel string `gorm:"size:50;default:'analyst'" json:"access_level"`
}

func (ProjectUser) TableName() string {
	return "project_user"
}

type UserPreference struct {
	ID               uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	UserID           uint64    `gorm:"unique;not null" json:"user_id"`
	AlertEmail       bool      `gorm:"default:true" json:"alert_email"`
	AlertTelegram    bool      `gorm:"default:false" json:"alert_telegram"`
	TelegramChatID   *string   `gorm:"size:100" json:"telegram_chat_id"`
	AlertMinSeverity string    `gorm:"size:30;default:'high'" json:"alert_min_severity"`
	Theme            string    `gorm:"size:20;default:'dark'" json:"theme"`
	CreatedAt        time.Time `json:"created_at"`
	UpdatedAt        time.Time `json:"updated_at"`
}

type PasswordHistory struct {
	ID        uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	UserID    uint64    `gorm:"index;not null" json:"user_id"`
	Password  string    `gorm:"size:255;not null" json:"-"`
	CreatedAt time.Time `gorm:"index" json:"created_at"`
}

type Repository struct {
	ID                 uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	ProjectID          uint64     `gorm:"index;not null" json:"project_id"`
	Provider           string     `gorm:"size:255;default:'git'" json:"provider"`
	Name               string     `gorm:"size:255;not null" json:"name"`
	URL                string     `gorm:"size:255;not null" json:"url"`
	DefaultBranch      string     `gorm:"size:100;default:'main'" json:"default_branch"`
	VerificationStatus string     `gorm:"size:50;default:'pending'" json:"verification_status"`
	VerifiedAt         *time.Time `json:"verified_at"`
	VerifiedBy         *uint64    `json:"verified_by"`
	Metadata           *string    `gorm:"type:json" json:"metadata"`
	CreatedAt          time.Time  `json:"created_at"`
	UpdatedAt          time.Time  `json:"updated_at"`

	Project  Project `gorm:"foreignKey:ProjectID" json:"project"`
	Verifier *User   `gorm:"foreignKey:VerifiedBy" json:"verifier,omitempty"`
}

type Target struct {
	ID                 uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	ProjectID          uint64     `gorm:"index;not null" json:"project_id"`
	Type               string     `gorm:"size:50;not null" json:"type"`
	Name               string     `gorm:"size:255;not null" json:"name"`
	BaseURL            *string    `gorm:"size:255" json:"base_url"`
	Hostname           *string    `gorm:"size:255" json:"hostname"`
	VerificationStatus string     `gorm:"size:50;default:'pending'" json:"verification_status"`
	VerifiedAt         *time.Time `json:"verified_at"`
	VerifiedBy         *uint64    `json:"verified_by"`
	Metadata           *string    `gorm:"type:json" json:"metadata"`
	CreatedAt          time.Time  `json:"created_at"`
	UpdatedAt          time.Time  `json:"updated_at"`

	Project  Project `gorm:"foreignKey:ProjectID" json:"project"`
	Verifier *User   `gorm:"foreignKey:VerifiedBy" json:"verifier,omitempty"`
}

type Scope struct {
	ID        uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	ProjectID uint64    `gorm:"index;not null" json:"project_id"`
	TargetID  *uint64   `gorm:"index" json:"target_id"`
	Type      string    `gorm:"size:50;not null" json:"type"`
	Pattern   string    `gorm:"size:255;not null" json:"pattern"`
	Effect    string    `gorm:"size:50;default:'allow'" json:"effect"`
	Status    string    `gorm:"size:50;default:'active'" json:"status"`
	Reason    *string   `gorm:"type:text" json:"reason"`
	CreatedBy uint64    `gorm:"not null" json:"created_by"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`

	Project Project `gorm:"foreignKey:ProjectID" json:"project"`
	Target  *Target `gorm:"foreignKey:TargetID" json:"target,omitempty"`
	Creator *User   `gorm:"foreignKey:CreatedBy" json:"creator,omitempty"`
}

type ScanProfile struct {
	ID                 uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	Key                string    `gorm:"size:255;unique;not null" json:"key"`
	Name               string    `gorm:"size:255;not null" json:"name"`
	Description        *string   `gorm:"type:text" json:"description"`
	AllowedTargetTypes string    `gorm:"type:json" json:"allowed_target_types"`
	EngineKeys         string    `gorm:"type:json" json:"engine_keys"`
	Policy             string    `gorm:"type:json" json:"policy"`
	ActiveTesting      bool      `gorm:"default:false" json:"active_testing"`
	IsActive           bool      `gorm:"default:true" json:"is_active"`
	CreatedAt          time.Time `json:"created_at"`
	UpdatedAt          time.Time `json:"updated_at"`
}

type Authorization struct {
	ID                   uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	Code                 string     `gorm:"size:255;unique;not null" json:"code"`
	ProjectID            uint64     `gorm:"index;not null" json:"project_id"`
	RepositoryID         *uint64    `gorm:"index" json:"repository_id"`
	TargetID             *uint64    `gorm:"index" json:"target_id"`
	ScanProfileID        uint64     `gorm:"not null" json:"scan_profile_id"`
	RequestedBy          uint64     `gorm:"not null" json:"requested_by"`
	ApprovedBy           *uint64    `json:"approved_by"`
	Status               string     `gorm:"size:50;default:'active'" json:"status"`
	ValidFrom            time.Time  `json:"valid_from"`
	ValidUntil           *time.Time `json:"valid_until"`
	MaxConcurrency       uint32     `gorm:"default:1" json:"max_concurrency"`
	RateLimitPerMinute   uint32     `gorm:"default:60" json:"rate_limit_per_minute"`
	AllowedEngines       string     `gorm:"type:json" json:"allowed_engines"`
	AllowedScopeSnapshot string     `gorm:"type:json" json:"allowed_scope_snapshot"`
	DeniedScopeSnapshot  *string    `gorm:"type:json" json:"denied_scope_snapshot"`
	PolicySnapshot       string     `gorm:"type:json" json:"policy_snapshot"`
	Notes                *string    `gorm:"type:text" json:"notes"`
	CreatedAt            time.Time  `json:"created_at"`
	UpdatedAt            time.Time  `json:"updated_at"`

	Project     Project     `gorm:"foreignKey:ProjectID" json:"project"`
	Repository  *Repository `gorm:"foreignKey:RepositoryID" json:"repository,omitempty"`
	Target      *Target     `gorm:"foreignKey:TargetID" json:"target,omitempty"`
	ScanProfile ScanProfile `gorm:"foreignKey:ScanProfileID" json:"scan_profile"`
	Requester   *User       `gorm:"foreignKey:RequestedBy" json:"requester,omitempty"`
	Approver    *User       `gorm:"foreignKey:ApprovedBy" json:"approver,omitempty"`
}

type SecurityEngine struct {
	ID               uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	Code             string     `gorm:"size:50;unique;not null" json:"code"`
	Name             string     `gorm:"size:100;not null" json:"name"`
	Domain           string     `gorm:"size:50;not null" json:"domain"`
	Category         string     `gorm:"size:50;not null" json:"category"`
	Version          string     `gorm:"size:50;default:'1.0.0'" json:"version"`
	AdapterVersion   string     `gorm:"size:50;default:'1.0.0'" json:"adapter_version"`
	ContainerImage   string     `gorm:"size:255;not null" json:"container_image"`
	ResourceClass    string     `gorm:"size:20;default:'MEDIUM'" json:"resource_class"`
	Enabled          bool       `gorm:"default:true" json:"enabled"`
	Status           string     `gorm:"size:30;default:'AVAILABLE'" json:"status"`
	SupportedTargets *string    `gorm:"type:json" json:"supported_targets"`
	DefaultTimeout   int        `gorm:"default:300" json:"default_timeout"`
	CPULimit         float64    `gorm:"type:decimal(4,2);default:1.50" json:"cpu_limit"`
	MemoryLimitMB    int        `gorm:"default:2048" json:"memory_limit_mb"`
	Description      *string    `gorm:"type:text" json:"description"`
	LastHealthCheck  *time.Time `json:"last_health_check"`
	CreatedAt        time.Time  `json:"created_at"`
	UpdatedAt        time.Time  `json:"updated_at"`
}

type ScanProfileEngine struct {
	ID               uint64          `gorm:"primaryKey;autoIncrement" json:"id"`
	ProfileCode      string          `gorm:"size:50;not null" json:"profile_code"`
	SecurityEngineID uint64          `gorm:"not null" json:"security_engine_id"`
	IsRequired       bool            `gorm:"default:true" json:"is_required"`
	ExecutionOrder   int             `gorm:"default:1" json:"execution_order"`
	CreatedAt        time.Time       `json:"created_at"`
	UpdatedAt        time.Time       `json:"updated_at"`

	SecurityEngine   *SecurityEngine `gorm:"foreignKey:SecurityEngineID" json:"security_engine,omitempty"`
}

type ScanJob struct {
	ID                       uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	Code                     string     `gorm:"size:255;unique;not null" json:"code"`
	ProjectID                uint64     `gorm:"index;not null" json:"project_id"`
	RepositoryID             *uint64    `gorm:"index" json:"repository_id"`
	TargetID                 *uint64    `gorm:"index" json:"target_id"`
	ScanProfileID            uint64     `gorm:"not null" json:"scan_profile_id"`
	AuthorizationID          uint64     `gorm:"not null" json:"authorization_id"`
	CreatedBy                uint64     `gorm:"not null" json:"created_by"`
	Status                   string     `gorm:"size:50;default:'queued'" json:"status"`
	Progress                 uint8      `gorm:"default:0" json:"progress"`
	RequiredEngineCount      int        `gorm:"default:0" json:"required_engine_count"`
	CompletedEngineCount     int        `gorm:"default:0" json:"completed_engine_count"`
	FailedEngineCount        int        `gorm:"default:0" json:"failed_engine_count"`
	CoveragePass             bool       `gorm:"default:true" json:"coverage_pass"`
	Attempt                  uint32     `gorm:"default:0" json:"attempt"`
	QueuedAt                 *time.Time `json:"queued_at"`
	StartedAt                *time.Time `json:"started_at"`
	FinishedAt               *time.Time `json:"finished_at"`
	CancelledAt              *time.Time `json:"cancelled_at"`
	FailureReason            *string    `gorm:"type:text" json:"failure_reason"`
	EnginePlan               string     `gorm:"type:json" json:"engine_plan"`
	ExecutionPolicySnapshot  *string    `gorm:"type:json" json:"execution_policy_snapshot"`
	CreatedAt                time.Time  `json:"created_at"`
	UpdatedAt                time.Time  `json:"updated_at"`

	Project       Project        `gorm:"foreignKey:ProjectID" json:"project"`
	Repository    *Repository    `gorm:"foreignKey:RepositoryID" json:"repository,omitempty"`
	Target        *Target        `gorm:"foreignKey:TargetID" json:"target,omitempty"`
	ScanProfile   *ScanProfile   `gorm:"foreignKey:ScanProfileID" json:"scan_profile,omitempty"`
	Authorization *Authorization `gorm:"foreignKey:AuthorizationID" json:"authorization,omitempty"`
	Creator       *User          `gorm:"foreignKey:CreatedBy" json:"creator,omitempty"`
	ScanRuns      []ScanRun      `gorm:"foreignKey:ScanJobID" json:"scan_runs,omitempty"`
	Reports       []Report       `gorm:"foreignKey:ScanJobID" json:"reports,omitempty"`
}

type ScanRun struct {
	ID             uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	ScanJobID      uint64     `gorm:"index;not null" json:"scan_job_id"`
	EngineKey      string     `gorm:"size:255;not null" json:"engine_key"`
	Status         string     `gorm:"size:50;default:'pending'" json:"status"`
	ExitCode       *int       `json:"exit_code"`
	CommandSpec    *string    `gorm:"type:json" json:"command_spec"`
	RuntimeMetrics *string    `gorm:"type:json" json:"runtime_metrics"`
	StartedAt      *time.Time `json:"started_at"`
	FinishedAt     *time.Time `json:"finished_at"`
	FailureReason  *string    `gorm:"type:text" json:"failure_reason"`
	CreatedAt      time.Time  `json:"created_at"`
	UpdatedAt      time.Time  `json:"updated_at"`
}

type Finding struct {
	ID                    uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	Code                  string     `gorm:"size:255;unique;not null" json:"code"`
	ProjectID             uint64     `gorm:"index;not null" json:"project_id"`
	ScanJobID             uint64     `gorm:"index;not null" json:"scan_job_id"`
	ScanRunID             *uint64    `gorm:"index" json:"scan_run_id"`
	EngineKey             string     `gorm:"size:255;index;not null" json:"engine_key"`
	RuleID                *string    `gorm:"size:255;index" json:"rule_id"`
	Title                 string     `gorm:"type:text;not null" json:"title"`
	SeverityRaw           *string    `gorm:"size:255" json:"severity_raw"`
	Severity              string     `gorm:"size:255;index;not null" json:"severity"`
	Confidence            *float64   `gorm:"type:decimal(3,2)" json:"confidence"`
	AssetType             string     `gorm:"size:255;index;not null" json:"asset_type"`
	AssetIdentifier       string     `gorm:"size:255;not null" json:"asset_identifier"`
	FilePath              *string    `gorm:"type:text" json:"file_path"`
	LineStart             *uint32    `json:"line_start"`
	LineEnd               *uint32    `json:"line_end"`
	HTTPMethod            *string    `gorm:"size:50" json:"http_method"`
	Endpoint              *string    `gorm:"type:text" json:"endpoint"`
	CWE                   *string    `gorm:"size:255;index" json:"cwe"`
	OWASP                 *string    `gorm:"size:255;index" json:"owasp"`
	CVE                   *string    `gorm:"size:255;index" json:"cve"`
	CVSS                  *float64   `gorm:"type:decimal(3,1)" json:"cvss"`
	Status                string     `gorm:"size:50;default:'open'" json:"status"`
	DedupKey              string     `gorm:"size:255;not null" json:"dedup_key"`
	EvidenceSummary       *string    `gorm:"type:json" json:"evidence_summary"`
	NormalizationMetadata *string    `gorm:"type:json" json:"normalization_metadata"`
	CreatedAt             time.Time  `json:"created_at"`
	UpdatedAt             time.Time  `json:"updated_at"`

	Project   Project           `gorm:"foreignKey:ProjectID" json:"project"`
	ScanJob   *ScanJob          `gorm:"foreignKey:ScanJobID" json:"scan_job,omitempty"`
	ScanRun   *ScanRun          `gorm:"foreignKey:ScanRunID" json:"scan_run,omitempty"`
	Evidences []FindingEvidence `gorm:"foreignKey:FindingID" json:"evidence,omitempty"`
}

type FindingEvidence struct {
	ID              uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	FindingID       uint64    `gorm:"index;not null" json:"finding_id"`
	EngineKey       string    `gorm:"size:50;not null" json:"engine_key"`
	EngineVersion   *string   `gorm:"size:50" json:"engine_version"`
	Confidence      float64   `gorm:"type:decimal(3,2);default:0.80" json:"confidence"`
	FingerprintHash *string   `gorm:"size:64" json:"fingerprint_hash"`
	EvidenceSummary *string   `gorm:"type:json" json:"evidence_summary"`
	RawArtifactPath *string   `gorm:"size:255" json:"raw_artifact_path"`
	CreatedAt       time.Time `json:"created_at"`
	UpdatedAt       time.Time `json:"updated_at"`
}

func (FindingEvidence) TableName() string {
	return "finding_evidences"
}

type Report struct {
	ID           uint64     `gorm:"primaryKey;autoIncrement" json:"id"`
	ScanJobID    uint64     `gorm:"index;not null" json:"scan_job_id"`
	Type         string     `gorm:"size:50;default:'standard'" json:"type"`
	Status       string     `gorm:"size:50;default:'pending'" json:"status"`
	Format       string     `gorm:"size:50;default:'pdf'" json:"format"`
	ArtifactPath *string    `gorm:"size:255" json:"artifact_path"`
	GeneratedBy  *uint64    `json:"generated_by"`
	GeneratedAt  *time.Time `json:"generated_at"`
	Metadata     *string    `gorm:"type:json" json:"-"`
	CreatedAt    time.Time  `json:"created_at"`
	UpdatedAt    time.Time  `json:"updated_at"`

	ScanJob   *ScanJob `gorm:"foreignKey:ScanJobID" json:"scan_job,omitempty"`
	Generator *User    `gorm:"foreignKey:GeneratedBy" json:"generator,omitempty"`
}

func (r Report) MarshalJSON() ([]byte, error) {
	type Alias Report
	var metaObj interface{}
	if r.Metadata != nil && *r.Metadata != "" {
		_ = json.Unmarshal([]byte(*r.Metadata), &metaObj)
	}
	return json.Marshal(&struct {
		Alias
		Metadata interface{} `json:"metadata"`
	}{
		Alias:    (Alias)(r),
		Metadata: metaObj,
	})
}

type PasswordResetToken struct {
	Email     string    `gorm:"primaryKey;size:255" json:"email"`
	Token     string    `gorm:"size:255;not null" json:"token"`
	CreatedAt time.Time `gorm:"not null" json:"created_at"`
}

type AuthenticationLog struct {
	ID            uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	UserID        *uint64   `json:"user_id"`
	Email         string    `gorm:"size:255;not null" json:"email"`
	IPAddress     string    `gorm:"size:45;not null" json:"ip_address"`
	UserAgent     string    `gorm:"type:text;not null" json:"user_agent"`
	Status        string    `gorm:"size:30;not null" json:"status"`
	FailureReason *string   `gorm:"size:100" json:"failure_reason"`
	CreatedAt     time.Time `json:"created_at"`
}

type AuditLog struct {
	ID         uint64    `gorm:"primaryKey;autoIncrement" json:"id"`
	UserID     *uint64   `json:"user_id"`
	Action     string    `gorm:"size:100;not null" json:"action"`
	Result     string    `gorm:"column:result;size:30;not null" json:"result"`
	ActorIP    string    `gorm:"column:actor_ip;size:45;not null" json:"actor_ip"`
	TargetType *string   `gorm:"size:50" json:"target_type"`
	TargetID   *uint64   `json:"target_id"`
	Metadata   *string   `gorm:"type:json" json:"metadata"`
	CreatedAt  time.Time `json:"created_at"`
	UpdatedAt  time.Time `json:"updated_at"`

	User *User `gorm:"foreignKey:UserID" json:"user,omitempty"`
}
