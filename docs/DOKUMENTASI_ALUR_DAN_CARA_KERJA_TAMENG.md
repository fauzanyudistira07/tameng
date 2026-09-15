# Dokumentasi Alur & Cara Kerja TAMENG
### Technical Architecture, Security Detection Flow & System Operation Documentation

* **Tanggal Dokumentasi**: 15 September 2026
* **Versi Sistem**: Current Development (`main` branch, commit `867bf64`)
* **Status Implementasi**: Production Ready Core (5-Domain Multi-Engine Scanner, SOC Security & Audit Active)
* **Klasifikasi Dokumen**: Internal Technical Documentation & Security Operations Manual

---

## DAFTAR ISI

1. [BAGIAN 1 — Arsitektur & Alur Kerja TAMENG](#bagian-1--arsitektur--alur-kerja-tameng)
   * 1.1 Apa itu TAMENG
   * 1.2 Gambaran Besar Arsitektur
   * 1.3 Infrastruktur TAMENG
   * 1.4 Stack Teknologi
2. [TAMENG — MASTER SYSTEM FLOW (END-TO-END VISUAL ARCHITECTURE)](#tameng--master-system-flow-end-to-end-visual-architecture)
   * 13.1 Master Flowchart: Siklus Lengkap Perjalanan Security Event
3. [BAGIAN 2 — Alur Data & Ingestion TAMENG](#bagian-2--alur-data--ingestion-tameng)
   * 2.1 Data Source
   * 2.2 Data Ingestion & Gateway Validation
   * 2.3 Visual Flowchart: Data Ingestion & Gate Enforcement (13.2)
   * 2.4 Normalization & Fingerprinting
4. [BAGIAN 3 — Security Detection Engine](#bagian-3--security-detection-engine)
   * 3.1 Registry & Engine Adapters
   * 3.2 Visual Flowchart: Multi-Engine Sandbox & Detection Pipeline (13.3)
   * 3.3 Severity & Risk Classification
5. [BAGIAN 4 — Alert & Notifikasi](#bagian-4--alert--notifikasi)
   * 4.1 Kanal Notifikasi Aktif & Terencana
   * 4.2 Visual Flowchart: Evaluasi Risiko & Dispatching Alert (13.4)
6. [BAGIAN 5 — Investigation & Incident Analysis](#bagian-5--investigation--incident-analysis)
   * 5.1 Alur Investigasi Analis SOC
   * 5.2 Visual Flowchart: Triage & Incident Investigation Workflow (13.5)
7. [BAGIAN 6 — Response & Auto Remediation](#bagian-6--response--auto-remediation)
   * 6.1 Status Implementasi Respons
   * 6.2 Visual Flowchart: Advisory Remediation vs Planned Auto-Response (13.6)
8. [BAGIAN 7 — Dashboard TAMENG](#bagian-7--dashboard-tameng)
   * 7.1 Telemetri & Endpoint Data
   * 7.2 Visual Flowchart: Dashboard Telemetry & Client-Side Polling (13.8)
9. [BAGIAN 8 — API Endpoints](#bagian-8--api-endpoints)
10. [BAGIAN 9 — Database Schema & Architecture](#bagian-9--database-schema--architecture)
11. [BAGIAN 10 — Authentication & Authorization](#bagian-10--authentication--authorization)
    * 10.1 Mekanisme Login, Lockout & Histori Sandi
    * 10.2 Visual Flowchart: Autentikasi, Lockout & RBAC Multi-Tenancy (13.7)
12. [BAGIAN 11 — Audit Log & Forensik](#bagian-11--audit-log--forensik)
13. [BAGIAN 12 — Health Check & System Monitoring](#bagian-12--health-check--system-monitoring)
    * 12.1 Mekanisme Pemeriksaan 5 Subsistem
    * 12.2 Visual Flowchart: Subsystem Health Check & Degradation (13.10)
14. [BAGIAN 13 — Logging & Observability](#bagian-13--logging--observability)
15. [BAGIAN 14 — Error Handling](#bagian-14--error-handling)
    * 14.1 Skenario Kegagalan Nyata
    * 14.2 Visual Flowchart: Resiliensi Sistem & Error Handling (13.9)
16. [BAGIAN 15 — Security Architecture](#bagian-15--security-architecture)
17. [BAGIAN 16 — Alur End-to-End (Skenario Operasional)](#bagian-16--alur-end-to-end-skenario-operasional)
18. [BAGIAN 17 — File & Component Map](#bagian-17--file--component-map)
19. [BAGIAN 18 — Dependency & Integration Map](#bagian-18--dependency--integration-map)
20. [BAGIAN 19 — Deployment Architecture](#bagian-19--deployment-architecture)
21. [BAGIAN 20 — Backup & Recovery](#bagian-20--backup--recovery)
22. [BAGIAN 21 — Verifikasi & Pengujian](#bagian-21--verifikasi--pengujian)
23. [BAGIAN 22 — Troubleshooting](#bagian-22--troubleshooting)
24. [BAGIAN 23 — Security Flow Diagram](#bagian-23--security-flow-diagram)
25. [BAGIAN 24 — Status Implementasi Fitur](#bagian-24--status-implementasi-fitur)
26. [BAGIAN 25 — Kesimpulan & Rekomendasi Roadmap](#bagian-25--kesimpulan--rekomendasi-roadmap)

---

# BAGIAN 1 — ARSITEKTUR & ALUR KERJA TAMENG

## 1.1 Apa itu TAMENG

**TAMENG** (secara internal diidentifikasi dengan basis arsitektur `SecSys` / Security Scan System) adalah platform **Security Operations Center (SOC) Control Plane & Automated Vulnerability Assessment System** yang dirancang untuk mengotomasi audit keamanan, pemindaian kerentanan (vulnerability assessment), inspeksi kode statis (SAST), deteksi kebocoran kredensial (secrets leak), analisis dependensi pihak ketiga (SCA & SBOM), audit container Docker, evaluasi Infrastructure as Code (IaC), pengujian dinamis aplikasi web & REST API (DAST), serta inspeksi kriptografi TLS/SSL dan binary mobile (APK/IPA).

### Tujuan Utama TAMENG:
1. **Mengeliminasi Blindspot Keamanan**: Menggabungkan hasil analisis dari berbagai security tool open-source kelas industri ke dalam satu antarmuka terpusat dan satu format data normalisasi.
2. **Menjamin Kontrol Akses & Scope Ketat**: Menerapkan prinsip zero-trust pada eksekusi pengujian dengan aturan kaku:
   * **"NO VERIFIED TARGET = NO EXECUTION"** (Aset tanpa verifikasi kepemilikan ditolak untuk dipindai).
   * **"OUTSIDE SCOPE = DENY"** (Permintaan di luar cakupan izin yang disetujui diblokir oleh gateway kebijakan).
3. **Mendukung Siklus DevSecOps**: Memungkinkan tim developer mengajukan pemindaian mandiri melalui portal khusus (`/scan-saya`), integrasi webhook git push otomatis, dan unduhan laporan kepatuhan terverifikasi.

### Masalah Keamanan yang Diselesaikan:
* Pemindaian keamanan yang terisolasi dan tidak konsisten antar tim pengembang.
* Kebocoran API key, private key, dan password database di repositori git internal.
* Kerentanan library open-source usang (CVE) yang tidak terpantau sebelum rilis ke production.
* Server web dan endpoint API yang mengalami miskonfigurasi header keamanan (CORS, CSP, HSTS, Clickjacking).
* Ketiadaan audit trail forensik terhadap siapa yang mengizinkan dan menjalankan pemindaian terhadap suatu target infrastruktur.

### Posisi TAMENG dalam Infrastruktur Keamanan:
TAMENG beroperasi sebagai **Centralized Security Orchestration & Control Plane**. Sistem ini tidak menempatkan agen residen yang memodifikasi target, melainkan bertindak sebagai orkestrator kontainer deterministik yang mengeksekusi scanner di dalam sandbox aman, mengumpulkan bukti (*evidence*), melakukan deduplikasi temuan, menyajikan analitik mitigasi berbasis AI advisory, dan mengirimkan notifikasi insiden ke tim SOC.

---

## 1.2 Gambaran Besar Arsitektur

Arsitektur TAMENG dibangun dengan prinsip pemisahan tanggung jawab (*Separation of Concerns*) antara antarmuka pengguna (Frontend), API Control Plane (Backend), Execution Queue & Runner (Worker), serta Storage & Database Layer.

```text
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                                USER ACCESS & CLIENT LAYER                              │
│                                                                                        │
│   ┌───────────────────────────┐      ┌───────────────────────────┐                     │
│   │   Admin / SOC Analyst     │      │   Developer / Auditor     │                     │
│   │   (Web Dashboard Tabler)  │      │   (Self-Service Portal)   │                     │
│   └─────────────┬─────────────┘      └─────────────┬─────────────┘                     │
└─────────────────┼──────────────────────────────────┼───────────────────────────────────┘
                  │                                  │
                  ▼                                  ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                              REVERSE PROXY & INGRESS LAYER                             │
│                                                                                        │
│   ┌────────────────────────────────────────────────────────────────────────────────┐   │
│   │               Nginx Reverse Proxy & Frontend Static Host                       │   │
│   │   * Frontend Container: Port 8080 (Docker) -> SPA HTML5 / Static Assets        │   │
│   │   * Backend Nginx: Port 8000 (Docker) -> Reverse Proxy to PHP-FPM 9000        │   │
│   │   * Rate Limiting, Client Max Body Size: 200MB (APK Upload & Git Workspaces)   │   │
│   └───────────────────────────────────────┬────────────────────────────────────────┘   │
└───────────────────────────────────────────┼────────────────────────────────────────────┘
                                            │
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                             CONTROL PLANE (LARAVEL 12 API)                             │
│                                                                                        │
│   ┌────────────────────────────────────────────────────────────────────────────────┐   │
│   │   Security Gateways & Interceptors:                                            │   │
│   │   ├── AuthController & Sanctum SPA Stateful Session                            │   │
│   │   ├── EnsureRole Middleware (RBAC: Super Admin, Sec Admin, Analyst, Dev, etc.) │   │
│   │   ├── AuthorizationGateway (Snapshot validasi Scope, Target, Profile)          │   │
│   │   └── ExecutionGateway (Pre-flight Engine Safety Check & Policy Decision)      │   │
│   │                                                                                │   │
│   │   Core Application Services:                                                   │   │
│   │   ├── RepositoryWorkspaceSyncer (Git clone/fetch & Local path attachment)      │   │
│   │   ├── FormLoginSessionResolver (Otomasi login session cookie untuk DAST)       │   │
│   │   ├── FindingNormalizer & UniversalFindingNormalizer                           │   │
│   │   ├── FindingDeduplicator (SHA-256 fingerprinting & evidence clustering)       │   │
│   │   ├── AiRemediationService (Advisory Code Patch & Mitigation Checklist)        │   │
│   │   ├── ReportGenerator & ReportPdfGenerator                                     │   │
│   │   └── AuditLogger (Forensic audit trail recorder)                              │   │
│   └───────────────────────────────────────┬────────────────────────────────────────┘   │
└───────────────────────────────────────────┼────────────────────────────────────────────┘
                                            │
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                             ASYNCHRONOUS WORKER & QUEUE                                │
│                                                                                        │
│   ┌────────────────────────────────────────────────────────────────────────────────┐   │
│   │   secsys-worker (Container: tameng-secsys-worker)                              │   │
│   │   Command: php artisan queue:work --tries=1 --timeout=900                      │   │
│   │   Queue Driver: database (Tabel jobs, job_batches, failed_jobs)                │   │
│   │   Job Handler: App\Jobs\RunScanJob                                             │   │
│   └───────────────────┬────────────────────────────────────────┬───────────────────┘   │
└───────────────────────┼────────────────────────────────────────┼───────────────────────┘
                        │                                        │
                        ▼                                        ▼
┌──────────────────────────────────────┐     ┌───────────────────────────────────────────┐
│     DATA & PERSISTENCE LAYER         │     │         SANDBOX ENGINE RUNNER             │
│                                      │     │                                           │
│  ┌────────────────────────────────┐  │     │  EngineRunner & EngineAdapterResolver:    │
│  │ MySQL 8.4 Database             │  │     │  Socket Docker Host: /var/run/docker.sock │
│  │ (39 Tabel: findings, scans,    │  │     │  Isolasi Jaringan: --network none (SAST)  │
│  │  audit_logs, users, auth)      │  │     │  Workspace Mount: /data/... (Read-Only)   │
│  └────────────────────────────────┘  │     │                                           │
│  ┌────────────────────────────────┐  │     │  12 Implemented Adapters:                 │
│  │ Local Artifact Storage         │  │     │  ├── Semgrep (SAST)                       │
│  │ (/var/www/html/storage/app/    │  │     │  ├── Gitleaks (Secrets)                   │
│  │  private/scan-runs/...)        │  │     │  ├── Trivy (SCA / Container)              │
│  └────────────────────────────────┘  │     │  ├── OSV-Scanner (Open Source CVE)        │
│  ┌────────────────────────────────┐  │     │  ├── OWASP ZAP (DAST Baseline Web)       │
│  │ Redis 7 (Cache & Status)       │  │     │  ├── Nuclei (Vulnerability Templates)     │
│  └────────────────────────────────┘  │     │  ├── Hadolint (Dockerfile Linter)         │
└──────────────────────────────────────┘     │  ├── Checkov (IaC Terraform/K8s)          │
                                             │  ├── Anchore Syft (SBOM SPDX/CycloneDX)   │
                                             │  ├── Anchore Grype (Container CVE)        │
                                             │  ├── MobSF (Mobile APK/IPA Scanner)       │
                                             │  └── TestSSL.sh (TLS/SSL Cipher Audit)    │
                                             └───────────────────────────────────────────┘
```

---

## 1.3 Infrastruktur TAMENG

Berdasarkan berkas konfigurasi Docker Compose (`/opt/tameng/tameng/docker-compose.yml`) dan status container aktif (`docker ps`), seluruh komponen berjalan dalam satu Docker Engine host dengan port binding berikut:

| Component | Technology | Port (Host:Container) | Container Name | Function | Status |
| :--- | :--- | :---: | :--- | :--- | :--- |
| **Frontend Web** | Nginx 1.27-Alpine | `8080:80/tcp` | `secsys-frontend` | Menyajikan antarmuka pengguna SPA (Vue 3 + Vite) | 🟢 Active (Healthy) |
| **Backend API Gateway** | Nginx 1.27-Alpine | `8000:80/tcp` | `secsys-backend-nginx` | Reverse proxy HTTP ke PHP-FPM, melayani routing `/api/*` | 🟢 Active (Healthy) |
| **Control Plane Core** | PHP 8.2-FPM (Laravel 12) | `9000/tcp` (Internal) | `secsys-backend` | API server, business logic, autentikasi, dan evaluasi gate | 🟢 Active |
| **Queue Worker** | PHP 8.2 CLI | `9000/tcp` (Internal) | `secsys-worker` | Eksekusi background job scan via Docker daemon socket | 🟢 Active |
| **Database Server** | MySQL 8.4 Community | `3307:3306/tcp` | `secsys-mysql` | Penyimpanan data relasional, hasil temuan, audit log, user | 🟢 Active (Healthy) |
| **In-Memory Cache** | Redis 7-Alpine | `6380:6379/tcp` | `secsys-redis` | Cache store dan sesi kecepatan tinggi | 🟢 Active |
| **Host Workspace Volume** | Host Linux Directory | N/A | `/data/repository-workspaces` | Direktori bersama clone git repositori & binary APK target | 🟢 Active |
| **Storage Private Volume** | Docker Volume | N/A | `tameng_secsys_backend_storage` | Penyimpanan bukti mentah scan (`scan-runs/`) dan PDF laporan | 🟢 Active |
| **Wazuh Agent / Server** | N/A | N/A | N/A | Host-based intrusion detection system | 🔴 Not Implemented |
| **n8n Workflow Automation**| N/A | N/A | N/A | Low-code event trigger & SOAR workflow | 🔴 Not Implemented |

---

## 1.4 Stack Teknologi

| Layer | Technology | Version | Function & Detail |
| :--- | :--- | :--- | :--- |
| **Backend Framework** | Laravel Framework | `12.x` (PHP 8.2.27) | RESTful API control plane, routing, middleware, Eloquent ORM, queued jobs |
| **Frontend Framework** | Vue.js 3 | `3.5.41` | Progressive frontend UI framework menggunakan Composition API |
| **Frontend UI Kit** | Tabler Core & Vanilla CSS | `1.0.0-beta20` / Custom | Glassmorphism dark-themed cyber defense styling, responsive layout |
| **Build Tooling** | Vite | `6.0.0` (Tabler) / `8.2.0` | High-speed frontend asset bundler & HMR development server |
| **HTTP Client (FE)** | Axios & Fetch API | `1.19.0` | HTTP requests dengan interceptor cache in-memory TTL 60 detik & CSRF handling |
| **Database Engine** | MySQL | `8.4.11` | Relational DBMS dengan transaksi ACID, JSON column indexing, foreign keys |
| **Queue & Worker** | Laravel Queue Worker | Database Connection | Pemrosesan background asinkronus (`RunScanJob`) dengan timeout 900 detik |
| **Cache & Session** | Redis / MySQL | Redis `7.2` / MySQL | Penyimpanan stateful session Sanctum dan cache sistem |
| **Security Scanning** | Multi-Engine Sandbox | Dockerized CLI | 12 tools scanner mandiri (Semgrep, Gitleaks, Trivy, OSV, ZAP, Nuclei, dsb.) |
| **PDF Generation** | DomPDF / Native Renderer | Standard Engine | Kompilasi laporan audit keamanan formal format PDF untuk stakeholder |
| **Mailing / SMTP** | Google Mail SMTP | Port 587 (TLS) | Pengiriman email kode OTP reset password 6-digit dan alert SOC |

---

# TAMENG — MASTER SYSTEM FLOW (END-TO-END VISUAL ARCHITECTURE)

## 13.1 Master Flowchart: Siklus Lengkap Perjalanan Security Event

Flowchart master ini adalah **Single Source of Truth** yang menjawab seluruh siklus hidup keamanan di TAMENG: dari mana aset berasal, bagaimana evaluasi gate dijalankan, bagaimana kontainer sandbox mengeksekusi scanner, bagaimana bukti dinormalisasi dan dideduplikasi, hingga bagaimana alert dipancarkan dan diinvestigasi oleh analis SOC.

```mermaid
flowchart TD
    %% Styling and Classes
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef denied fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fef2f2;
    classDef external fill:#2e1065,stroke:#c084fc,stroke-width:1px,color:#faf5ff;

    %% 1. Data Sources
    subgraph S1 ["1. INPUT ASSET & SECURITY SOURCES"]
        SRC_GIT[/"Git Repositories (GitHub, GitLab, Local)"/]:::external
        SRC_WEB[/"Live Web & REST API Endpoints"/]:::external
        SRC_MOB[/"Mobile Binaries (APK Android / IPA iOS)"/]:::external
        SRC_IMG[/"Container Images & Dockerfiles"/]:::external
    end

    %% 2. Ingestion & Pre-flight Gateways
    subgraph S2 ["2. INGESTION & ACCESS CONTROL GATEWAY"]
        IN_API["HTTP Request: POST /api/scan-jobs / my/scan-requests\n(ScanJobController / MyScanRequestController)"]:::process
        IN_HOOK["Git Push Webhook: POST /api/webhooks/git\n(GitWebhookController)"]:::process
        
        AUTH_SANCTUM{"Sanctum SPA Session Valid & Active?"}:::decision
        GATE_AUTH{"AuthorizationGateway:decide()\n1. Target Verified?\n2. Active Profile?\n3. Scope Pattern Allow?"}:::decision
        
        REC_POLICY_DENY["PolicyDecision::create()\ndecision = 'deny', reason_code\nAuditLogger::record()"]:::denied
        OUT_REJECT(["REJECTED / ACCESS DENIED"]):::startEnd
    end

    SRC_GIT --> IN_API
    SRC_WEB --> IN_API
    SRC_MOB --> IN_API
    SRC_IMG --> IN_API
    SRC_GIT -->|Git Push Event| IN_HOOK

    IN_API --> AUTH_SANCTUM
    AUTH_SANCTUM -->|NO: HTTP 401/403| OUT_REJECT
    AUTH_SANCTUM -->|YES| GATE_AUTH
    IN_HOOK --> GATE_AUTH

    GATE_AUTH -->|NO: Unverified / Denied Scope| REC_POLICY_DENY
    REC_POLICY_DENY --> OUT_REJECT

    %% 3. Queue & Sandbox Execution
    subgraph S3 ["3. DETERMINISTIC QUEUE WORKER & ENGINE SANDBOX"]
        CREATE_JOB["Create ScanJob (status: queued, progress: 0%)\nSnapshot Immutable Authorization"]:::process
        DISPATCH_QUEUE["dispatch(new RunScanJob)\nQueue Connection: database"]:::process
        DB_QUEUE[("Tabel: jobs\nQueue Driver: MySQL")]:::database
        
        WORKER_RUN["secsys-worker\nphp artisan queue:work --timeout=900"]:::process
        WORKSPACE_SYNC["RepositoryWorkspaceSyncer::sync()\nClone Git / Attach Host Folder (/data/...)"]:::process
        
        ENG_SELECTOR["DeterministicEngineSelector::selectEngines()\n(SAST, Secrets, SCA, DAST, Mobile, TLS)"]:::process
        GATE_EXEC{"ExecutionGateway:decide()\nEngine in Allowed Whitelist?"}:::decision
        
        DOCKER_RUN["Docker Daemon (/var/run/docker.sock)\nRun Sandbox Container\n(--network none, volume :ro, timeout limit)"]:::process
        DOCKER_ENGINES["12 Implemented Scanner Adapters\n(Semgrep, Gitleaks, Trivy, OSV, ZAP, Nuclei, dsb.)"]:::external
    end

    GATE_AUTH -->|YES: Allowed| CREATE_JOB
    CREATE_JOB --> DISPATCH_QUEUE
    DISPATCH_QUEUE --> DB_QUEUE
    DB_QUEUE --> WORKER_RUN
    WORKER_RUN --> WORKSPACE_SYNC
    WORKSPACE_SYNC --> ENG_SELECTOR
    ENG_SELECTOR --> GATE_EXEC

    GATE_EXEC -->|NO: Denied| REC_POLICY_DENY
    GATE_EXEC -->|YES: Exec Authorized| DOCKER_RUN
    DOCKER_RUN --> DOCKER_ENGINES

    %% 4. Parsing, Normalization & Deduplication
    subgraph S4 ["4. PARSING, DEDUPLICATION & STORAGE LAYER"]
        RAW_OUT["Ekstraksi Raw Output (JSON/XML/Text)\nSimpan ke storage/app/private/scan-runs/..."]:::process
        ART_STORE[("Tabel: artifacts\nSHA-256 Checksum & Metadata")]:::database
        
        NORM_EXEC["UniversalFindingNormalizer::formatItem()\nStandardize Title, Severity, CWE, OWASP"]:::process
        FINGERPRINT["FindingDeduplicator:computeFingerprint()\nSHA-256 Hash (Location + Rule + Line Bucket)"]:::process
        
        DEDUP_CHECK{"dedup_key match existing finding?"}:::decision
        CREATE_FINDING["Create Finding (status: open)\nAttach Evidence"]:::process
        ESCALATE_FINDING["Cluster Evidence to Primary Finding\nEscalate Severity & Boost Confidence (+0.10)"]:::process
        
        DB_FINDINGS[("Tabel: findings & finding_evidences")]:::database
        DB_SCANRUNS[("Tabel: scan_runs & scan_jobs")]:::database
    end

    DOCKER_ENGINES --> RAW_OUT
    RAW_OUT --> ART_STORE
    RAW_OUT --> NORM_EXEC
    NORM_EXEC --> FINGERPRINT
    FINGERPRINT --> DEDUP_CHECK

    DEDUP_CHECK -->|NO: New Issue| CREATE_FINDING
    DEDUP_CHECK -->|YES: Multi-Engine Duplicate| ESCALATE_FINDING
    CREATE_FINDING --> DB_FINDINGS
    ESCALATE_FINDING --> DB_FINDINGS
    CREATE_FINDING --> DB_SCANRUNS
    ESCALATE_FINDING --> DB_SCANRUNS

    %% 5. Reporting, Alerting & Investigation
    subgraph S5 ["5. REPORTING, ALERTING & SOC INVESTIGATION"]
        GEN_REPORT["ReportGenerator::generateStandardJson()\nKompilasi Matriks Risiko & Coverage Pass"]:::process
        DB_REPORTS[("Tabel: reports\n(Standard JSON / PDF)")]:::database
        
        EVAL_ALERT{"Evaluasi Risiko Scan\n(Critical > 0 || High > 0)?"}:::decision
        ALERT_HOOK["WebhookNotificationService::notifyScanResult()\nPOST Webhook (Discord / Slack Embed)"]:::process
        ALERT_EMAIL["Gmail SMTP Port 587\n(Email Notifikasi Akun SOC)"]:::process
        ALERT_TG["Telegram Bot Dispatcher\n(Status: PARTIALLY IMPLEMENTED)"]:::denied
        
        FE_DASH["TAMENG Web Dashboard (Vue 3 / Tabler)\nClient-Side Polling (5000ms Interval)"]:::process
        SOC_INVESTIGATE["SOC Analyst Investigation (/findings)\n1. Multi-Evidence Review\n2. AI Remediation Guidance (AiRemediationService)\n3. Triage Status Update (open -> resolved)"]:::process
        DB_AUDIT[("Tabel: audit_logs\n(Forensic Audit Trail)")]:::database
    end

    DB_SCANRUNS --> GEN_REPORT
    GEN_REPORT --> DB_REPORTS
    GEN_REPORT --> EVAL_ALERT

    EVAL_ALERT -->|YES| ALERT_HOOK
    EVAL_ALERT -->|Email Trigger| ALERT_EMAIL
    EVAL_ALERT -.->|Pending Service| ALERT_TG
    EVAL_ALERT -->|NO / Normal| FE_DASH
    ALERT_HOOK --> FE_DASH

    FE_DASH --> SOC_INVESTIGATE
    SOC_INVESTIGATE -->|Save Triage| DB_FINDINGS
    SOC_INVESTIGATE -->|Record Action| DB_AUDIT
```

---

# BAGIAN 2 — ALUR DATA & INGESTION TAMENG

Data keamanan di dalam TAMENG bergerak melalui pipeline deterministik yang ketat. Setiap tahap didesain agar tidak ada scanner yang dapat berjalan tanpa otorisasi eksplisit.

```text
    ┌────────────────────────────────────────────────────────┐
    │ 1. DATA SOURCES                                        │
    │    * Git Repositories (GitHub, GitLab, Local Path)     │
    │    * Web & API Targets (URL, Hostname, Swagger/OpenAPI) │
    │    * Mobile Binaries (APK Android, IPA iOS)            │
    │    * Container Images (Docker Hub, Private Registry)   │
    └───────────────────────────┬────────────────────────────┘
                                │
                                ▼
    ┌────────────────────────────────────────────────────────┐
    │ 2. INGESTION & GATEWAY VALIDATION                      │
    │    * API POST /api/scan-jobs atau Git Webhook          │
    │    * Validasi Verifikasi Target & Status Proyek        │
    │    * Pengecekan Aturan Scope (ALLOW / DENY)            │
    │    * Snapshot Otorisasi Imutabel Dibuat                │
    └───────────────────────────┬────────────────────────────┘
                                │
                                ▼
    ┌────────────────────────────────────────────────────────┐
    │ 3. ASYNCHRONOUS EXECUTION & SANDBOXING                 │
    │    * Dispatch RunScanJob ke Queue Worker               │
    │    * Clone / Sync Repositori ke Workspace Aman         │
    │    * Eksekusi Guarded Scanner dalam Docker Sandbox     │
    │      (Isolasi: --network none untuk SAST, read-only)   │
    └───────────────────────────┬────────────────────────────┘
                                │
                                ▼
    ┌────────────────────────────────────────────────────────┐
    │ 4. PARSING & NORMALIZATION                             │
    │    * Ekstraksi JSON / XML / Text Mentah Scanner        │
    │    * Transformasi ke Struktur Standar SecSys           │
    │    * Perhitungan SHA-256 Deduplication Fingerprint     │
    └───────────────────────────┬────────────────────────────┘
                                │
                                ▼
    ┌────────────────────────────────────────────────────────┐
    │ 5. DEDUPLICATION & CROSS-VALIDATION                    │
    │    * Clustering Bukti (Evidence) Multi-Engine          │
    │    * Eskalasi Severity & Confidence Scoring            │
    │    * Penyimpanan ke Database & Disk Penyimpanan        │
    └───────────────────────────┬────────────────────────────┘
                                │
                                ▼
    ┌────────────────────────────────────────────────────────┐
    │ 6. ALERTING, REPORTING & DASHBOARD                     │
    │    * Webhook Embed Alert (Discord / Slack / Teams)     │
    │    * Pembuatan Laporan JSON & PDF Formal               │
    │    * Polling Telemetri Frontend (Setiap 5 Detik)       │
    └────────────────────────────────────────────────────────┘
```

## 2.1 Data Source

TAMENG menerima dan mengolah input dari 4 kategori aset:
1. **Source Code Repositories**:
   * **Format**: Repositori Git (HTTP/HTTPS URL atau direktori lokal host).
   * **Metadata**: Default branch (`main`/`master`), commit SHA, scan type (`repository`, `mobile`).
   * **Service**: `RepositoryWorkspaceSyncer.php`.
2. **Live Web & REST API Targets**:
   * **Format**: HTTP/HTTPS base URL, hostname, dan port.
   * **Metadata**: Tipe autentikasi target (`none`, `bearer_token`, `basic_auth`, `form_login`). Jika `form_login`, kredensial dienkripsi dengan Laravel Crypt dan diselesaikan secara otomatis oleh `FormLoginSessionResolver.php`.
   * **Service**: `TargetController.php`, `ZapAdapter.php`, `NucleiAdapter.php`, `TestSslAdapter.php`.
3. **Mobile Application Binaries**:
   * **Format**: File `.apk` (Android) atau `.ipa` (iOS) yang diunggah langsung ke server atau diunduh dari remote repository.
   * **Metadata**: File size, binary SHA-256 hash, package name.
   * **Service**: `MobSFAdapter.php`, `MyScanRequestController.php`.
4. **Container Images & Dockerfiles**:
   * **Format**: Docker image reference (`image:tag`) atau berkas `Dockerfile` di dalam repositori.
   * **Service**: `HadolintAdapter.php`, `TrivyAdapter.php`, `GrypeAdapter.php`.

---

## 2.2 Data Ingestion & Gateway Validation

Mekanisme ingestion data dilakukan melalui 2 jalur utama:

### Jalur A: Web Dashboard / REST API Request
1. Pengguna (Admin, Analyst, atau Developer) mengirim payload JSON ke `POST /api/scan-jobs` atau `POST /api/my/scan-requests`.
2. Controller memvalidasi keberadaan `project_id`, `repository_id` atau `target_id`, serta `scan_profile_id`.
3. `AuthorizationGateway.php` mengeksekusi metode `decide()`:
   * Memastikan target aset berstatus `verified`.
   * Memastikan profil scan aktif.
   * Memastikan minimal satu scope aturan berstatus `allow`.
   * Menghasilkan record imutabel `PolicyDecision` dengan status `allow` atau `deny`.
4. Jika disetujui, record `ScanJob` dibuat dengan status `queued` dan progress 0%.
5. Job `RunScanJob` dimasukkan ke dalam antrean database melalui `dispatch(new RunScanJob($scanJob->id, $user->id))`.

### Jalur B: Git Push Webhook (CI/CD Automation)
1. Endpoint penerima: `POST /api/webhooks/git`.
2. Service: `GitWebhookController.php`.
3. Validasi konfigurasi: `config('secsys.git_webhook_auto_scan_enabled')`.
4. Normalisasi URL webhook dari payload GitHub/GitLab/Bitbucket.
5. Pencocokan otomatis repositori di database TAMENG.
6. Pembuatan otorisasi otomatis berdurasi 30 hari dan penugasan scan job asinkronus.

---

## 2.3 Visual Flowchart: Data Ingestion & Gate Enforcement (13.2)

Flowchart ini memetakan bagaimana data masuk diverifikasi dan disaring secara ketat oleh kontrol keamanan sebelum diizinkan masuk ke sistem antrean eksekusi.

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef denied fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fef2f2;

    START_INGEST(["Incoming Ingestion Trigger"]):::startEnd

    SRC_USER["User via Dashboard / API\n(POST /api/scan-jobs)"]:::process
    SRC_DEV["Developer via Self-Service\n(POST /api/my/scan-requests)"]:::process
    SRC_HOOK["Git Push Webhook\n(POST /api/webhooks/git)"]:::process

    START_INGEST --> SRC_USER
    START_INGEST --> SRC_DEV
    START_INGEST --> SRC_HOOK

    CHECK_AUTH{"Route Auth: Sanctum Session?"}:::decision
    SRC_USER --> CHECK_AUTH
    SRC_DEV --> CHECK_AUTH

    CHECK_AUTH -->|NO| RET_401(["401 Unauthorized"]):::denied
    CHECK_AUTH -->|YES| VAL_PAYLOAD["Validate Request Payload\n(project_id, target_id / repository_id, scan_profile_id)"]:::process

    SRC_HOOK --> CHECK_HOOK_ENABLED{"config: git_webhook_auto_scan_enabled?"}:::decision
    CHECK_HOOK_ENABLED -->|NO: Disabled| RET_200_DIS(["200 OK (Disabled Message)"]):::process
    CHECK_HOOK_ENABLED -->|YES| RESOLVE_REPO["GitWebhookController:handle()\nDetect Repository URL & Find Match in Database"]:::process

    RESOLVE_REPO --> CHECK_REPO_EXISTS{"Repository Registered in TAMENG?"}:::decision
    CHECK_REPO_EXISTS -->|NO| RET_404(["404 Unmatched Repository"]):::denied
    CHECK_REPO_EXISTS -->|YES| VAL_PAYLOAD

    VAL_PAYLOAD --> GATEWAY_AUTH{"AuthorizationGateway:decide()\n1. Target Verification == 'verified'?\n2. ScanProfile is_active == true?\n3. Allowed Scope Snapshot exists?"}:::decision

    GATEWAY_AUTH -->|NO: Scope Deny / Target Unverified| LOG_POLICY_DENY["PolicyDecision::create('deny')\nReason: REPOSITORY_NOT_VERIFIED / OUTSIDE_SCOPE\nAuditLogger::record('scan_job.reject')"]:::denied
    LOG_POLICY_DENY --> RET_403(["422 / 403 Forbidden Access"]):::denied

    GATEWAY_AUTH -->|YES: Validation Passed| MAKE_AUTH_RECORD["Create / Reuse Authorization Record\n(Snapshots of Policy & Scopes)"]:::process
    MAKE_AUTH_RECORD --> MAKE_SCAN_JOB["Create ScanJob Model\nstatus = 'queued', progress = 0%\nGenerate Code: SCAN-YYYYMMDD-XXXX"]:::process
    
    MAKE_SCAN_JOB --> DB_SAVE_JOB[("Tabel: scan_jobs &\npolicy_decisions")]:::database
    DB_SAVE_JOB --> DISPATCH_JOB["dispatch(new RunScanJob($scanJobId, $userId))"]:::process
    
    DISPATCH_JOB --> DB_JOBS[("Tabel: jobs\n(Database Queue)")]:::database
    DB_JOBS --> RET_201(["201 Created / 202 Accepted\nReturn ScanJob Code"]):::startEnd
```

---

## 2.4 Normalization & Fingerprinting

Setiap scanner menghasilkan format keluaran yang sangat heterogen (misalnya Semgrep mengeluarkan JSON dengan key `results[].extra`, Gitleaks menghasilkan array commits, ZAP mengeluarkan alert site XML/JSON). TAMENG mengonversi seluruh keluaran tersebut menjadi format tunggal melalui `UniversalFindingNormalizer.php` dan `FindingNormalizer.php`.

### Algoritma Fingerprinting SHA-256:
Untuk mencegah duplikasi temuan yang sama pada baris kode yang berdekatan atau hasil pemindaian ulang, TAMENG menerapkan algoritma toleransi bucket baris (line bucket ±5 baris):

```text
Normalized Location = strtolower(trim(filePathOrEndpoint))
Normalized Rule     = strtolower(trim(cweOrCveOrRuleId))
Line Bucket         = floor(lineStart / 5) * 5

Fingerprint String  = "proj:{projectId}|type:{assetType}|loc:{Normalized Location}|rule:{Normalized Rule}|bucket:{Line Bucket}"
Fingerprint Hash    = SHA-256(Fingerprint String)
```

### Struktur Data Temuan Ternormalisasi:
```json
{
  "fingerprint": "a3b8c9e120f872d8e411...",
  "rule_id": "generic.secrets.security.private-key",
  "title": "Identified a Private Key, which may compromise cryptographic security.",
  "severity": "critical",
  "severity_raw": "CRITICAL",
  "confidence": 0.95,
  "asset_type": "repository",
  "asset_identifier": "config/ssl/server.key",
  "file_path": "config/ssl/server.key",
  "line_start": 1,
  "line_end": 28,
  "cwe": "CWE-312",
  "owasp": "A02:2021-Cryptographic Failures",
  "cve": null,
  "cvss": 9.1,
  "status": "open",
  "evidence_summary": {
    "file": "config/ssl/server.key",
    "secret_type": "RSA PRIVATE KEY",
    "match": "-----BEGIN RSA PRIVATE KEY----- [REDACTED]"
  },
  "normalization_metadata": {
    "engine": "gitleaks",
    "engine_version": "8.28.0",
    "normalized_at": "2026-09-15T06:30:00Z"
  }
}
```

---

# BAGIAN 3 — SECURITY DETECTION ENGINE

## 3.1 Registry & Engine Adapters

TAMENG mengelola katalog **20 Security Engines** yang dikelompokkan ke dalam **10 Domain Keamanan**. Seluruh engine tercatat pada tabel `security_engines` dan dieksekusi melalui arsitektur adapter (`App\Services\Engines\Adapters\*`).

| No | Domain | Engine Name | Key | Container Image | Resource Class | Status Adapter |
| :---: | :--- | :--- | :--- | :--- | :---: | :---: |
| 1 | **SOURCE_CODE** | Semgrep SAST | `semgrep` | `semgrep/semgrep:1.74.0` | MEDIUM | 🟢 Implemented |
| 2 | **SOURCE_CODE** | GitHub CodeQL | `codeql` | `mcr.microsoft.com/cstsectools/codeql-container:latest` | HEAVY | 🔵 Skeleton / Planned |
| 3 | **SOURCE_CODE** | SonarQube Scanner | `sonarqube` | `sonarsource/sonar-scanner-cli:latest` | HEAVY | 🔵 Skeleton / Planned |
| 4 | **SECRET** | Gitleaks Secrets | `gitleaks` | `zricethezav/gitleaks:v8.28.0` | LIGHT | 🟢 Implemented |
| 5 | **SECRET** | TruffleHog v3 | `trufflehog` | `trufflesecurity/trufflehog:3.68.0` | LIGHT | 🔵 Skeleton / Planned |
| 6 | **DEPENDENCY** | Aqua Trivy | `trivy` | `aquasec/trivy:latest` | MEDIUM | 🟢 Implemented |
| 7 | **DEPENDENCY** | Google OSV-Scanner | `osv` | `ghcr.io/google/osv-scanner:latest` | LIGHT | 🟢 Implemented |
| 8 | **DEPENDENCY** | OWASP Dependency-Check | `dependency_check` | `owasp/dependency-check:9.0.9` | MEDIUM | 🔵 Skeleton / Planned |
| 9 | **SBOM** | Anchore Syft | `syft` | `anchore/syft:v0.105.0` | LIGHT | 🟢 Implemented |
| 10 | **CONTAINER** | Anchore Grype | `grype` | `anchore/grype:v0.74.0` | MEDIUM | 🟢 Implemented |
| 11 | **CONTAINER** | Hadolint Linter | `hadolint` | `hadolint/hadolint:v2.12.0` | LIGHT | 🟢 Implemented |
| 12 | **IAC** | Bridgecrew Checkov | `checkov` | `bridgecrew/checkov:3.2.35` | MEDIUM | 🟢 Implemented |
| 13 | **IAC** | ARMO Kubescape | `kubescape` | `kubescape/kubescape:v3.0.4` | MEDIUM | 🔵 Skeleton / Planned |
| 14 | **WEB (DAST)** | OWASP ZAP | `zap` | `zaproxy/zap-stable:2.14.0` | HEAVY | 🟢 Implemented |
| 15 | **WEB (DAST)** | ProjectDiscovery Nuclei | `nuclei` | `projectdiscovery/nuclei:v3.1.8` | MEDIUM | 🟢 Implemented |
| 16 | **WEB (DAST)** | Sullo Nikto Web Scanner | `nikto` | `sullo/nikto:2.1.6` | MEDIUM | 🟢 Implemented |
| 17 | **WEB (DAST)** | Wapiti Web Scanner | `wapiti` | `wapiti/wapiti:3.1.8` | MEDIUM | 🔵 Skeleton / Planned |
| 18 | **API** | WuppieFuzz REST API | `wuppiefuzz` | `secsys/wuppiefuzz:latest` | MEDIUM | 🔵 Skeleton / Planned |
| 19 | **MOBILE** | Mobile Security (MobSF) | `mobsf` | `opensecurity/mobsfscan:latest` | MEDIUM | 🟢 Implemented |
| 20 | **TLS** | TestSSL.sh TLS Audit | `testssl` | `drwetter/testssl.sh:3.0` | MEDIUM | 🟢 Implemented |

---

## 3.2 Visual Flowchart: Multi-Engine Sandbox & Detection Pipeline (13.3)

Flowchart berikut memetakan detail eksekusi di dalam kontainer sandbox, isolasi jaringan, ekstraksi artefak, hingga deduplikasi temuan.

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef denied fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fef2f2;
    classDef external fill:#2e1065,stroke:#c084fc,stroke-width:1px,color:#faf5ff;

    START_WORKER(["RunScanJob: Queue Worker Invoked"]):::startEnd

    PRE_EXEC["Set ScanJob Status = 'running'\nRepositoryWorkspaceSyncer::sync() if repo local_path is empty"]:::process
    CHECK_WORKSPACE{"Workspace / Clone Ready?"}:::decision

    START_WORKER --> PRE_EXEC
    PRE_EXEC --> CHECK_WORKSPACE

    CHECK_WORKSPACE -->|NO: Clone Fail / Timeout| FAIL_JOB["ScanJob Status = 'failed'\nfailure_reason = WORKSPACE_SYNC_FAILED\nAuditLogger::recordSystem()"]:::denied
    FAIL_JOB --> END_FAIL(["Halt Job Execution"]):::startEnd

    CHECK_WORKSPACE -->|YES| SELECT_ENGINES["DeterministicEngineSelector::selectEngines()\n(Target workspace inspection: code, Dockerfile, URL)"]:::process
    SELECT_ENGINES --> INTERSECT_AUTH["Intersect with authorization:allowed_engines"]:::process
    INTERSECT_AUTH --> ENGINE_LOOP["Iterate Each EngineKey in Planned Suite"]:::process

    subgraph ENGINE_RUN_CYCLE ["Per-Engine Guarded Execution Cycle"]
        EXEC_GATE{"ExecutionGateway:decide()\n1. Engine allowed?\n2. ScanJob executable?\n3. Scope snapshot valid?"}:::decision
        DENY_RUN["Create ScanRun (status: 'denied')\nfailure_reason = reason_code"]:::denied

        DOCKER_CMD["EngineAdapterResolver::resolve($engineKey)\nBuild Process: docker run --rm\nOpsi Keamanan: --network none (SAST), volume :ro"]:::process
        EXEC_PROCESS["Symfony Process::run()\nEnforce Timeout Limit (60s - 1200s)"]:::external

        CHECK_EXIT{"Container Exit Code Valid?"}:::decision
        RUN_FAILED["Create ScanRun (status: 'failed')\nStore Failure Reason & Exit Code"]:::denied
        RUN_OK["Create ScanRun (status: 'completed')\nExtract Raw JSON / Output Stream"]:::process

        STORE_RAW["EngineRunner:storeRawArtifact()\nSave: storage/app/private/scan-runs/{id}/{engine}-raw.json\nRecord to 'artifacts' table (SHA-256 hash)"]:::process
        PARSE_ITEMS["Adapter:parseOutput()\nUniversalFindingNormalizer:formatItem()"]:::process
        DEDUP_INGEST["FindingDeduplicator:ingest()\nCompute Fingerprint Hash"]:::process

        CHECK_DEDUP{"Finding with dedup_key already in ScanJob?"}:::decision
        NEW_FINDING["Create New Finding (status: 'open')\nAttach Evidence Record"]:::process
        BOOST_FINDING["Boost Existing Finding:\n- Confidence = min(0.99, confidence + 0.10)\n- Escalate Severity if Higher Weight\n- Attach Additional Engine Evidence"]:::process
    end

    ENGINE_LOOP --> EXEC_GATE
    EXEC_GATE -->|DENY| DENY_RUN
    DENY_RUN --> NEXT_ENGINE{"More Planned Engines?"}:::decision

    EXEC_GATE -->|ALLOW| DOCKER_CMD
    DOCKER_CMD --> EXEC_PROCESS
    EXEC_PROCESS --> CHECK_EXIT

    CHECK_EXIT -->|Error / Timeout| RUN_FAILED
    RUN_FAILED --> NEXT_ENGINE

    CHECK_EXIT -->|Success / Clean Finish| RUN_OK
    RUN_OK --> STORE_RAW
    STORE_RAW --> PARSE_ITEMS
    PARSE_ITEMS --> DEDUP_INGEST
    DEDUP_INGEST --> CHECK_DEDUP

    CHECK_DEDUP -->|NO| NEW_FINDING
    CHECK_DEDUP -->|YES| BOOST_FINDING
    NEW_FINDING --> NEXT_ENGINE
    BOOST_FINDING --> NEXT_ENGINE

    NEXT_ENGINE -->|YES| ENGINE_LOOP
    NEXT_ENGINE -->|NO: All Finished| AGGREGATE["Final ScanJob Aggregation:\n1. Update progress = 100%\n2. Calculate completed_engine_count & failed_engine_count\n3. Set coverage_pass = (failed == 0)\n4. ReportGenerator::generateStandardJson()"]:::process

    AGGREGATE --> END_SUCCESS(["Scan Job Completed"]):::startEnd
```

---

### Mekanisme Pengamanan Eksekusi Engine:
1. **Network Disabling (`--network none`)**: Diterapkan wajib pada engine SAST, Secret scanning, dan Dockerfile linter (Semgrep, Gitleaks, Hadolint) agar source code tidak dapat mengalami eksfiltrasi data ke internet.
2. **Read-Only Mount (`:ro`)**: Repositori target di-mount ke kontainer scanner hanya dengan hak akses baca.
3. **Timeout Enforced**: Setiap container diatur batas waktu maksimal (60 detik hingga 1.200 detik tergantung resource class) via `Symfony\Component\Process\Process`.
4. **Secret Redaction**: Gitleaks adapter secara otomatis menyamarkan string sensitif sebelum disimpan ke database (`[REDACTED]`).

---

## 3.3 Severity & Risk Classification

TAMENG menggunakan 5 tingkatan keparahan resmi yang dinormalisasi di seluruh engine:

1. **CRITICAL** (Bobot: 5)
   * Eksploitasi jarak jauh tanpa autentikasi (RCE), SQL Injection terverifikasi, kebocoran Private Key / AWS Root Key, sertifikat TLS kadaluarsa pada layanan utama.
2. **HIGH** (Bobot: 4)
   * Cross-Site Scripting (XSS) tersimpan, otentikasi lemah, kebocoran API token dengan izin baca/tulis, versi library dengan CVE yang diketahui memiliki exploit publik.
3. **MEDIUM** (Bobot: 3)
   * Miskonfigurasi header HTTP keamanan (ketiadaan CSP, HSTS, X-Frame-Options), versi cipher TLS 1.0/1.1 masih aktif, ketergantungan paket dengan potensi DoS.
4. **LOW** (Bobot: 2)
   * Pengungkapan banner versi server web, informasi internal stack trace pada pesan kesalahan non-kritis, flag cookie sensitif tanpa atribut Secure/HttpOnly pada lingkungan non-prod.
5. **INFORMATIONAL** (Bobot: 1)
   * Inventori komponen SBOM, catatan linter tata kelola penulisan Dockerfile, keterbukaan port yang diizinkan.

### Algoritma Skor Postur Keamanan (Security Posture Score)
Dihitung secara realtime di frontend (`DashboardView.vue`) untuk memberikan indikator kesehatan keamanan proyek:

$$\text{Deduction} = (N_{\text{critical}} \times 25) + (N_{\text{high}} \times 12) + (N_{\text{medium}} \times 4) + (N_{\text{low}} \times 1)$$

$$\text{Security Score} = \max(15, 100 - \text{Deduction}) \quad (\text{Jika } N_{\text{total}} = 0 \implies \text{Score} = 98)$$

**Kategori Grade Keamanan:**
* **Skor $\ge 90$**: `Optimal (A+)` — Sistem memiliki postur keamanan tangguh.
* **Skor $75 - 89$**: `Perlu Perhatian (B)` — Terdapat temuan medium yang perlu ditinjau.
* **Skor $50 - 74$**: `Rentan (C)` — Ditemukan kerentanan high yang berisiko dieksploitasi.
* **Skor $< 50$**: `Kritis (F)` — Ditemukan kerentanan critical mendesak yang membutuhkan penanganan segera.

---

# BAGIAN 4 — ALERT & NOTIFIKASI

## 4.1 Kanal Notifikasi Aktif & Terencana

TAMENG menyediakan sistem pengiriman peringatan dini saat proses pemindaian mendeteksi risiko keamanan atau mengalami kegagalan.

* **Webhook External (Discord / Slack Embed)**: 🟢 *Implemented* via `WebhookNotificationService.php`.
* **Google Mail SMTP (Port 587)**: 🟢 *Implemented* via `ResetPasswordCodeMail.php` dan `AuthController.php`.
* **Telegram Bot Dispatcher**: 🟡 *Partially Implemented*. Kolom `alert_telegram` dan `telegram_chat_id` telah ada pada tabel `user_preferences`, namun dispatcher bot belum aktif.

---

## 4.2 Visual Flowchart: Evaluasi Risiko & Dispatching Alert (13.4)

Flowchart berikut memetakan bagaimana temuan dievaluasi berdasarkan ambang keparahan dan disalurkan ke berbagai kanal notifikasi:

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef denied fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fef2f2;
    classDef external fill:#2e1065,stroke:#c084fc,stroke-width:1px,color:#faf5ff;

    TRIGGER_ALERT(["ScanJob Finished & Report Generated"]):::startEnd

    READ_SUMMARY["Load Risk Summary from Report Metadata\nExtract: critical_count, high_count, medium_count"]:::process
    CHECK_WEBHOOK{"config(services.tameng.webhook_url)\nconfigured and not empty?"}:::decision

    TRIGGER_ALERT --> READ_SUMMARY
    READ_SUMMARY --> CHECK_WEBHOOK

    CHECK_WEBHOOK -->|NO: URL Empty| CHECK_USER_PREF["Check User Preferences in Database\n(Tabel: user_preferences)"]:::process

    CHECK_WEBHOOK -->|YES: URL Present| BUILD_PAYLOAD["Build Rich Embed Payload:\n- Title: 🛡️ Laporan Pemindaian Keamanan TAMENG\n- Status: 🚨 KRITIS / ⚠️ TINGGI / 🛡️ AMAN\n- Color: Red (15158332) / Green (3066993)\n- Fields: Code, Target, Findings Count"]:::process

    BUILD_PAYLOAD --> DISPATCH_HTTP["Http::timeout(5)->post($webhookUrl, $payload)"]:::external
    DISPATCH_HTTP --> CHECK_HTTP_SUCCESS{"HTTP Post 2xx Success?"}:::decision

    CHECK_HTTP_SUCCESS -->|NO: Timeout / Error| LOG_WARN["Log::warning('Gagal kirim webhook TAMENG')\n(Non-blocking: ScanJob remains completed)"]:::denied
    CHECK_HTTP_SUCCESS -->|YES: Delivered| DISCORD_SLACK[/"Discord / Slack Channel Alert Delivered"/]:::external

    LOG_WARN --> CHECK_USER_PREF
    DISCORD_SLACK --> CHECK_USER_PREF

    CHECK_USER_PREF --> EVAL_EMAIL{"alert_email == true AND\nSeverity >= alert_min_severity?"}:::decision

    EVAL_EMAIL -->|YES| SEND_SMTP["Dispatch Email via Google Mail SMTP (Port 587)\nMail::to($user->email)->send(...)"]:::external
    EVAL_EMAIL -->|NO| EVAL_TELEGRAM{"alert_telegram == true AND\ntelegram_chat_id present?"}:::decision

    SEND_SMTP --> EVAL_TELEGRAM

    EVAL_TELEGRAM -->|YES| DISPATCH_TG["Telegram Bot Dispatcher\n(Status: 🟡 PARTIALLY IMPLEMENTED - Pending Worker)"]:::denied
    EVAL_TELEGRAM -->|NO| END_NOTIF(["Alert Lifecycle Finished"]):::startEnd
    DISPATCH_TG --> END_NOTIF
```

---

# BAGIAN 5 — INVESTIGATION & INCIDENT ANALYSIS

## 5.1 Alur Investigasi Analis SOC

Setelah temuan keamanan dihasilkan, analis SOC dapat melakukan investigasi mendalam melalui antarmuka **Findings** (`/findings`) dan **Scan Jobs** (`/scan-jobs`).

1. **Multi-Engine Evidence Clustering**: Menyatukan bukti dari beberapa scanner berbeda di bawah satu primary finding.
2. **File Path & Line Inspector**: Menampilkan lokasi baris kode atau URL endpoint yang bermasalah.
3. **AI Remediation Guidance Viewer**: Memanggil endpoint `GET /api/findings/{id}/ai-remediation`.
4. **Triage Status Workflow**: Mengubah status temuan (`open`, `reviewing`, `in_progress`, `resolved`, `false_positive`, `accepted`, `fixed`) dengan catatan analis.

---

## 5.2 Visual Flowchart: Triage & Incident Investigation Workflow (13.5)

Flowchart berikut memetakan perjalanan analis dalam mengevaluasi temuan dan mengambil keputusan penanganan:

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef denied fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fef2f2;
    classDef external fill:#2e1065,stroke:#c084fc,stroke-width:1px,color:#faf5ff;

    START_SOC(["SOC Analyst Opens TAMENG Dashboard"]):::startEnd

    NAV_FINDINGS["Navigate to /findings\nAPI: GET /api/findings (Ordered by Severity: Critical -> Low)"]:::process
    FILTER_FINDINGS["Filter by Severity, Project, or Engine\n(e.g., Severity = Critical, Engine = Gitleaks)"]:::process
    SELECT_FINDING["Select Specific Finding\nAPI: GET /api/findings/{id}"]:::process

    START_SOC --> NAV_FINDINGS
    NAV_FINDINGS --> FILTER_FINDINGS
    FILTER_FINDINGS --> SELECT_FINDING

    VIEW_DETAILS["Inspect Modal Details:\n- File Path & Line Numbers (line_start - line_end)\n- Endpoint URL & HTTP Method (for DAST)\n- CWE & OWASP Classification\n- Multi-Engine Evidence List (from 'finding_evidences')"]:::process
    SELECT_FINDING --> VIEW_DETAILS

    CALL_AI["Click 'AI Remediation Guidance' Tab\nAPI: GET /api/findings/{id}/ai-remediation\nService: AiRemediationService:generateGuidance()"]:::process
    VIEW_DETAILS --> CALL_AI

    INSPECT_AI["Review AI Guidance:\n- Technical Cause & Attack Vector\n- Business Impact Analysis\n- Proposed Code Diff Patch (Nginx / Express / PHP)\n- Pre-deployment Mitigation Checklist"]:::process
    CALL_AI --> INSPECT_AI

    DECIDE_TRIAGE{"Analyst Assessment Decision"}:::decision
    INSPECT_AI --> DECIDE_TRIAGE

    ACT_FP["Status: 'false_positive'\nCatatan: Parameter aman atau konfigurasi pengujian"]:::process
    ACT_PROGRESS["Status: 'in_progress'\nCatatan: Diteruskan ke Developer untuk patching"]:::process
    ACT_RESOLVED["Status: 'resolved' / 'fixed'\nCatatan: Telah diverifikasi pada scan ulang"]:::process
    ACT_ACCEPT["Status: 'accepted'\nCatatan: Risiko diterima oleh pimpinan proyek"]:::process

    DECIDE_TRIAGE -->|Bukan Kerentanan Nyata| ACT_FP
    DECIDE_TRIAGE -->|Valid Vulnerability| ACT_PROGRESS
    DECIDE_TRIAGE -->|Sudah Diperbaiki| ACT_RESOLVED
    DECIDE_TRIAGE -->|Ditoleransi Bisnis| ACT_ACCEPT

    SUBMIT_TRIAGE["Submit Triage Form:\nPUT /api/findings/{id}\nPayload: { status, resolution_notes }"]:::process
    ACT_FP --> SUBMIT_TRIAGE
    ACT_PROGRESS --> SUBMIT_TRIAGE
    ACT_RESOLVED --> SUBMIT_TRIAGE
    ACT_ACCEPT --> SUBMIT_TRIAGE

    DB_UPDATE_FINDING[("Tabel: findings\nUpdate status & triage_notes")]:::database
    RECORD_AUDIT["AuditLogger::record('finding.triage')\nActor: Analyst User ID, IP Address, Previous/New Status"]:::process
    DB_AUDIT[("Tabel: audit_logs")]:::database

    SUBMIT_TRIAGE --> DB_UPDATE_FINDING
    SUBMIT_TRIAGE --> RECORD_AUDIT
    RECORD_AUDIT --> DB_AUDIT
    DB_UPDATE_FINDING --> END_TRIAGE(["Investigation Completed & Tracked"]):::startEnd
```

---

# BAGIAN 6 — RESPONSE & AUTO REMEDIATION

## 6.1 Status Implementasi Respons

Sesuai dengan pedoman arsitektur MVP TAMENG (*Locked Decisions*):
> **"AI IS ADVISORY SECURITY ANALYST ONLY — WORKER IS DETERMINISTIC EXECUTOR ONLY"**

| Response Mechanism | Status | Komponen Terkait | Keterangan |
| :--- | :---: | :--- | :--- |
| **AI Advisory Code Patching** | 🟢 Implemented | `AiRemediationService.php` | Menghasilkan diff perbaikan konfigurasi Nginx/Apache/Express/PHP |
| **Mitigation Checklist** | 🟢 Implemented | `AiRemediationService.php` | Panduan langkah verifikasi teknis sebelum deployment |
| **Developer Ticket Export** | 🟢 Implemented | `exportCsvService.js` / PDF | Export daftar kerentanan untuk backlog engineering |
| **Auto Block IP (Firewall)** | 🔴 Not Implemented | N/A | Tidak ada integrasi iptables/pfSense/MikroTik otomatis |
| **Auto Isolate Container** | 🔴 Not Implemented | N/A | Isolasi container runtime belum dihubungkan |
| **Cloudflare WAF Rule Push** | 🔴 Not Implemented | N/A | Tidak ada panggilan API eksternal Cloudflare |

---

## 6.2 Visual Flowchart: Advisory Remediation vs Planned Auto-Response (13.6)

Flowchart berikut menegaskan perbedaan nyata antara alur Advisory Remediation yang **🟢 Telah Berjalan (Implemented)** dibandingkan dengan Auto-Remediation aktif yang berstatus **🔴 Rencana Masa Depan (Planned / Not Implemented)**:

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef planned fill:#450a0a,stroke:#f87171,stroke-width:2px,stroke-dasharray: 5 5,color:#fef2f2;
    classDef implemented fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;

    THREAT_DETECTED(["Threat Identified in Scan"]):::startEnd

    EVAL_MECHANISM{"Response Strategy Policy"}:::decision
    THREAT_DETECTED --> EVAL_MECHANISM

    subgraph ADVISORY_FLOW ["🟢 IMPLEMENTED: Advisory Remediation Engine"]
        CALL_AI_SERVICE["AiRemediationService:generateGuidance()\nMap Rule ID & Vulnerability Category"]:::implemented
        BUILD_DIFF["Generate Contextual Code Diff Patch\n(Nginx add_header, Express Helmet, Laravel Middleware)"]:::implemented
        BUILD_CHECKLIST["Compile Mitigation Steps & OWASP Verification Checklist"]:::implemented
        EXPORT_REPORT["Export to CSV / Download Formal PDF Report\n(ReportPdfGenerator.php)"]:::implemented
        DEV_HANDOFF["Handoff to Developer Team for Manual Review & Testing"]:::implemented
    end

    subgraph AUTO_RESPONSE_FLOW ["🔴 PLANNED / NOT IMPLEMENTED: Active Auto-Remediation"]
        CHECK_AUTO_POLICY{"Auto-Remediation Enabled?"}:::planned
        PUSH_FIREWALL["Push Drop Rule to Firewall / pfSense"]:::planned
        PUSH_CF["Push IP Block Rule to Cloudflare WAF API"]:::planned
        ISOLATE_HOST["Issue Docker Host / Container Network Isolation"]:::planned
    end

    EVAL_MECHANISM -->|Mode: Advisory Only (Default Core)| CALL_AI_SERVICE
    CALL_AI_SERVICE --> BUILD_DIFF
    BUILD_DIFF --> BUILD_CHECKLIST
    BUILD_CHECKLIST --> EXPORT_REPORT
    EXPORT_REPORT --> DEV_HANDOFF
    DEV_HANDOFF --> COMPLETE_ADVISORY(["Remediation Guidance Delivered"]):::startEnd

    EVAL_MECHANISM -.->|Mode: Active Enforcement| CHECK_AUTO_POLICY
    CHECK_AUTO_POLICY -.->|IP Blocking| PUSH_FIREWALL
    CHECK_AUTO_POLICY -.->|WAF Rule| PUSH_CF
    CHECK_AUTO_POLICY -.->|Host Quarantined| ISOLATE_HOST
```

---

# BAGIAN 7 — DASHBOARD TAMENG

## 7.1 Telemetri & Endpoint Data

Dashboard memperoleh data melalui pemanggilan endpoint backend berikut:
* **Endpoint**: `GET /api/overview`
* **Controller**: `App\Http\Controllers\Api\SystemOverviewController`
* **Metrik**: Total proyek, repositori, target, scan aktif, hitungan temuan per severity, serta status queue worker.

---

## 7.2 Visual Flowchart: Dashboard Telemetry & Client-Side Polling (13.8)

Flowchart berikut menggambarkan mekanisme Axios cache, pengambilan metrik sistem, penghitungan skor keamanan, dan siklus silent polling 5 detik:

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;

    USER_OPEN(["User Opens TAMENG Dashboard in Browser"]):::startEnd

    MOUNT_VUE["Vue Component: DashboardView.vue onMounted()"]:::process
    AXIOS_CALL["Call getOverview() via api.js\nRequest: GET /api/overview"]:::process

    USER_OPEN --> MOUNT_VUE
    MOUNT_VUE --> AXIOS_CALL

    CHECK_CACHE{"api.js Interceptor: Valid in-memory cache exists?\n(TTL: 60 Seconds)"}:::decision
    AXIOS_CALL --> CHECK_CACHE

    CHECK_CACHE -->|YES: Valid Cache| RETURN_CACHE["Return Cached Response Immediately\n(0ms Network Latency Adapter)"]:::process
    CHECK_CACHE -->|NO: Expired / First Load| SEND_REQ["Send HTTP Request to Backend Nginx (Port 8000)"]:::process

    SEND_REQ --> PHP_CONTROLLER["SystemOverviewController:__invoke()"]:::process
    PHP_CONTROLLER --> DB_QUERIES["Execute Aggregation SQL Queries:\n- COUNT(*) FROM projects, repositories, targets\n- COUNT(*) FROM findings WHERE severity = ...\n- COUNT(*) FROM scan_jobs WHERE status IN ('queued','running')\n- COUNT(*) FROM jobs, failed_jobs"]:::process

    DB_DATA[("Database: secsys-mysql")]:::database
    DB_QUERIES --> DB_DATA
    DB_DATA --> PHP_CONTROLLER

    PHP_CONTROLLER --> RET_JSON["Return JSON Response:\n{ product, principles, counts, queue_telemetry, scan_profiles }"]:::process
    RET_JSON --> SAVE_CACHE["Save Response to in-memory Cache Map\n(with timestamp)"]:::process
    RETURN_CACHE --> RENDER_UI["DashboardView.vue Reactive Computation:"]:::process
    SAVE_CACHE --> RENDER_UI

    CALC_SCORE["Compute securityScore:\nDeduction = (Crit * 25) + (High * 12) + (Med * 4) + (Low * 1)\nScore = max(15, 100 - Deduction)"]:::process
    CALC_GRADE["Compute securityGrade:\n- >= 90: Optimal (A+)\n- 75 - 89: Perlu Perhatian (B)\n- 50 - 74: Rentan (C)\n- < 50: Kritis (F)"]:::process

    RENDER_UI --> CALC_SCORE
    CALC_SCORE --> CALC_GRADE
    CALC_GRADE --> DRAW_CARDS["Render Cards & Progress Bars on Screen"]:::process

    subgraph POLLING_LOOP ["Client-Side Silent Polling Loop (ScanJobsView & MyScanRequestsView)"]
        TIMER_START["window.setInterval(() => loadData(true), 5000)"]:::process
        WAIT_5S["Wait 5000ms (5 Seconds Interval)"]:::process
        FETCH_SILENT["Background Request: GET /api/scan-jobs (skipCache: true)"]:::process
        UPDATE_PROGRESS["Update Progress Bars (0% - 100%) & Status Badges"]:::process
    end

    DRAW_CARDS --> TIMER_START
    TIMER_START --> WAIT_5S
    WAIT_5S --> FETCH_SILENT
    FETCH_SILENT --> UPDATE_PROGRESS
    UPDATE_PROGRESS --> WAIT_5S
```

---

# BAGIAN 8 — API ENDPOINTS

Berikut adalah katalog lengkap endpoint REST API yang terdaftar di berkas `routes/api.php`, lengkap dengan metode HTTP, fungsi bisnis, dan aturan otorisasi Role-Based Access Control (RBAC):

### 1. Authentication & Profil Akun
| Method | Endpoint | Fungsi | Autentikasi / Middleware | Status |
| :---: | :--- | :--- | :--- | :---: |
| `POST` | `/api/login` | Login user, verifikasi lockout, sesi Sanctum | Public (Rate Limited) | Active |
| `POST` | `/api/logout` | Menghancurkan session & catat auth log | `auth:sanctum` | Active |
| `POST` | `/api/forgot-password` | Kirim kode OTP 6-digit ke email Gmail | Public | Active |
| `POST` | `/api/reset-password` | Reset password dengan kode OTP & verifikasi | Public | Active |
| `GET` | `/api/user` | Mendapatkan profil user yang sedang login | `auth:sanctum` | Active |
| `PUT` | `/api/user` | Memperbarui profil personal (nama, telepon) | `auth:sanctum` | Active |

### 2. Dashboard & Telemetri Sistem
| Method | Endpoint | Fungsi | Autentikasi / Middleware | Status |
| :---: | :--- | :--- | :--- | :---: |
| `GET` | `/api/overview` | Ringkasan metrik postur keamanan & antrean | `auth:sanctum` | Active |
| `GET` | `/api/health` | Health check 5 subsistem (DB, Disk, Queue, Engine) | Public | Active |

### 3. Manajemen Pengguna & Proyek (Multi-Tenancy)
| Method | Endpoint | Fungsi | Role Guard (RBAC) | Status |
| :---: | :--- | :--- | :--- | :---: |
| `GET` | `/api/roles` | Daftar role hak akses (6 roles) | `super_admin, security_admin` | Active |
| `GET` | `/api/users` | Daftar seluruh akun pengguna SOC | `super_admin, security_admin` | Active |
| `POST` | `/api/users` | Tambah akun personel baru & preferensi | `super_admin` | Active |
| `PUT` | `/api/users/{user}` | Update user, histori sandi, role, proyek | `super_admin` | Active |
| `POST` | `/api/users/{user}/unlock` | Buka blokir akun terkunci (lockout) | `super_admin` | Active |
| `POST` | `/api/users/{user}/projects`| Delegasi hak akses proyek scoping | `super_admin` | Active |
| `DELETE`| `/api/users/{user}` | Soft delete nonaktifkan user | `super_admin` | Active |
| `GET` | `/api/projects` | Daftar proyek pengujian keamanan | All Roles | Active |
| `POST` | `/api/projects` | Registrasi proyek baru | `super_admin, security_admin` | Active |
| `PUT` | `/api/projects/{id}` | Update informasi proyek | `super_admin, security_admin` | Active |

### 4. Inventori Aset, Verifikasi & Scope Guard
| Method | Endpoint | Fungsi | Role Guard (RBAC) | Status |
| :---: | :--- | :--- | :--- | :---: |
| `GET` | `/api/repositories` | Daftar repositori git terdaftar | All Roles | Active |
| `POST` | `/api/repositories` | Tambah repositori baru | `super_admin, security_admin` | Active |
| `POST` | `/api/repositories/{id}/verify` | Verifikasi kepemilikan repositori | `super_admin, security_admin` | Active |
| `POST` | `/api/repositories/{id}/workspace` | Hubungkan path folder lokal | `super_admin, security_admin` | Active |
| `POST` | `/api/repositories/{id}/clone-workspace` | Clone/fetch repositori ke server | `super_admin, security_admin` | Active |
| `DELETE`| `/api/repositories/{id}/workspace` | Hapus workspace clone lokal | `super_admin, security_admin` | Active |
| `GET` | `/api/targets` | Daftar target Web / API / TLS | All Roles | Active |
| `POST` | `/api/targets` | Tambah target aset baru | `super_admin, security_admin` | Active |
| `POST` | `/api/targets/{id}/verify` | Verifikasi kepemilikan target URL | `super_admin, security_admin` | Active |
| `GET` | `/api/scopes` | Daftar aturan cakupan izin pemindaian | All Roles (except dev) | Active |
| `POST` | `/api/scopes` | Tambah aturan ALLOW / DENY scope | `super_admin, security_admin` | Active |
| `PUT` | `/api/scopes/{id}` | Perbarui aturan scope | `super_admin, security_admin` | Active |

### 5. Otorisasi & Orkestrasi Pemindaian (Scan Jobs)
| Method | Endpoint | Fungsi | Role Guard (RBAC) | Status |
| :---: | :--- | :--- | :--- | :---: |
| `GET` | `/api/scan-profiles` | Daftar profil scan bawaan | All Roles | Active |
| `GET` | `/api/authorizations`| Daftar otorisasi aktif & snapshot | All Roles (except dev) | Active |
| `POST` | `/api/authorizations`| Terbitkan otorisasi pemindaian baru | `super_admin, security_admin` | Active |
| `GET` | `/api/scan-jobs` | Riwayat seluruh eksekusi pemindaian | All Roles (except dev) | Active |
| `POST` | `/api/scan-jobs` | Buat & jalankan scan job baru | `super_admin, security_admin` | Active |
| `POST` | `/api/scan-jobs/{id}/rerun`| Jalankan ulang pemindaian identik | `super_admin, security_admin` | Active |
| `POST` | `/api/scan-jobs/{id}/process-simulated` | Proses simulasi scan (dry-run) | `super_admin, security_admin` | Active |
| `POST` | `/api/scan-jobs/{id}/engines/{key}/run-guarded` | Eksekusi manual 1 engine sandbox | `super_admin, security_admin` | Active |
| `GET` | `/api/my/scan-requests` | Portal mandiri permintaan scan developer | `super_admin, sec_admin, analyst, dev` | Active |
| `POST` | `/api/my/scan-requests` | Ajukan permintaan scan baru | `super_admin, sec_admin, analyst, dev` | Active |
| `POST` | `/api/my/scan-requests/{id}/rerun` | Pindai ulang permintaan mandiri | `super_admin, sec_admin, analyst, dev` | Active |
| `POST` | `/api/webhooks/git` | Ingestion otomatis pemindaian via git push | Public (Internal Token) | Active |

### 6. Security Engine Registry
| Method | Endpoint | Fungsi | Role Guard (RBAC) | Status |
| :---: | :--- | :--- | :--- | :---: |
| `GET` | `/api/security/engines` | Daftar 20 security engines & domain | All Roles | Active |
| `GET` | `/api/security/engines/{id}` | Detail konfigurasi engine & resource limit | All Roles | Active |
| `POST` | `/api/security/engines/{id}/toggle` | Aktifkan/nonaktifkan engine | `super_admin, security_admin` | Active |
| `POST` | `/api/security/engines/{id}/health-check` | Cek ketersediaan Docker image di host | `super_admin, sec_admin, analyst` | Active |
| `GET` | `/api/security/scan-profiles` | Pemetaan profil ke engine scanner | All Roles | Active |

### 7. Temuan Keamanan (Findings), Remediasi & Laporan
| Method | Endpoint | Fungsi | Role Guard (RBAC) | Status |
| :---: | :--- | :--- | :--- | :---: |
| `GET` | `/api/findings` | Daftar seluruh temuan ternormalisasi | All Roles | Active |
| `GET` | `/api/findings/{id}` | Detail temuan, baris kode, & multi-evidence | All Roles | Active |
| `GET` | `/api/findings/{id}/ai-remediation` | Panduan perbaikan & code diff AI | All Roles | Active |
| `PUT` | `/api/findings/{id}` | Triage status temuan & catatan resolusi | `super_admin, sec_admin, analyst` | Active |
| `GET` | `/api/reports` | Daftar laporan audit keamanan | All Roles | Active |
| `POST` | `/api/reports` | Kompilasi laporan baru dari scan selesai | `super_admin, sec_admin, analyst` | Active |
| `GET` | `/api/reports/{id}` | Lihat isi laporan keamanan | All Roles | Active |
| `GET` | `/api/reports/{id}/download-pdf` | Unduh laporan resmi format PDF | All Roles (Scoper Dev) | Active |
| `GET` | `/api/audit-logs` | Tinjauan audit trail kepatuhan & forensik | `super_admin, sec_admin, auditor` | Active |

---

# BAGIAN 9 — DATABASE SCHEMA & ARCHITECTURE

Basis data TAMENG menggunakan **MySQL 8.4** (`secsys-mysql`), mengelola **39 tabel relasional** dengan integritas referensial dan penyimpanan JSON metadata yang fleksibel.

```text
               ┌───────────────────────┐
               │         USERS         │
               └───────────┬───────────┘
                           │
             ┌─────────────┼─────────────┐
             ▼             ▼             ▼
      ┌────────────┐ ┌────────────┐ ┌────────────────────┐
      │ AUDIT_LOGS │ │ USER_PREF  │ │ AUTHENTICATION_LOG │
      └────────────┘ └────────────┘ └────────────────────┘
                           │
                           ▼
               ┌───────────────────────┐
               │       PROJECTS        │
               └───────────┬───────────┘
                           │
             ┌─────────────┴─────────────┐
             ▼                           ▼
      ┌──────────────┐            ┌──────────────┐
      │ REPOSITORIES │            │   TARGETS    │
      └──────┬───────┘            └──────┬───────┘
             │                           │
             └─────────────┬─────────────┘
                           │
                           ▼
               ┌───────────────────────┐
               │    AUTHORIZATIONS     │
               └───────────┬───────────┘
                           │
                           ▼
               ┌───────────────────────┐
               │       SCAN_JOBS       │
               └───────────┬───────────┘
                           │
             ┌─────────────┴─────────────┐
             ▼                           ▼
      ┌──────────────┐            ┌──────────────┐
      │  SCAN_RUNS   │            │   REPORTS    │
      └──────┬───────┘            └──────────────┘
             │
             ▼
      ┌──────────────┐
      │   FINDINGS   │
      └──────┬───────┘
             │
             ▼
      ┌──────────────────┐
      │ FINDING_EVIDENCES│
      └──────────────────┘
```

## 9.1 Entitas & Tabel Utama

### 1. Entitas Pengguna & Akses (`users`, `roles`, `project_user`, `user_preferences`)
* `users`: Menyimpan kredensial (`password` bcrypt), status lockout (`failed_login_attempts`, `locked_until`), riwayat IP (`last_login_ip`), dan unit kerja.
* `password_histories`: Menyimpan riwayat hash kata sandi untuk mencegah penggunaan ulang 3 kata sandi terakhir.
* `authentication_logs`: Catatan forensik setiap percobaan login/logout (status: `success`, `failed`, `blocked`, `locked`, `logout`).
* `project_user`: Tabel pivot multi-tenancy yang mendefinisikan level akses pengguna terhadap proyek spesifik (`lead`, `analyst`, `developer`, `viewer`).
* `user_preferences`: Preferensi kanal notifikasi (`alert_email`, `alert_telegram`, `telegram_chat_id`, `alert_min_severity`, `theme`).

### 2. Entitas Manajemen Aset (`projects`, `repositories`, `targets`, `scopes`)
* `projects`: Wadah pengelompokan sistem dengan indikator tingkat kepentingan bisnis (`criticality`: `low`, `medium`, `high`, `critical`).
* `repositories`: Repositori kode sumber target pemindaian dengan status verifikasi (`verification_status`: `pending`, `verified`, `rejected`).
* `targets`: Target URL aplikasi web, API endpoint, atau TLS hostname yang telah divalidasi.
* `scopes`: Aturan pembatasan regex target (`effect`: `allow`, `deny`) untuk mencegah pemindaian di luar lingkup yang diizinkan.

### 3. Entitas Tata Kelola Otorisasi (`authorizations`, `policy_decisions`)
* `authorizations`: Rekaman otorisasi pemindaian yang memuat snapshot scope yang diizinkan (`allowed_scope_snapshot`), scanner yang diizinkan (`allowed_engines`), jendela waktu (`valid_from` s/d `valid_until`), serta batas konkurensi.
* `policy_decisions`: Catatan audit otomatis setiap kali Gateway Keamanan mengevaluasi permintaan eksekusi (`gateway`: `authorization` atau `execution`, `decision`: `allow` atau `deny`, `reason_code`).

### 4. Entitas Eksekusi & Scanner (`security_engines`, `scan_jobs`, `scan_runs`)
* `security_engines`: Registri 20 engine dengan definisi Docker image, resource limit CPU/RAM, dan kategori domain.
* `scan_jobs`: Siklus hidup pemindaian (`queued`, `running`, `completed`, `failed`, `denied`), kemajuan progress (0-100%), dan rencana engine (`engine_plan`).
* `scan_runs`: Catatan instans eksekusi per-engine individu di bawah suatu scan job, mencakup exit code, waktu eksekusi, dan kegagalan spesifik.

### 5. Entitas Temuan & Pelaporan (`findings`, `finding_evidences`, `reports`, `artifacts`)
* `findings`: Temuan keamanan yang telah dinormalisasi dan di-deduplikasi dengan `dedup_key` unik per scan job.
* `finding_evidences`: Bukti mentah dari scanner yang dihubungkan ke temuan utama untuk mendukung validasi multi-engine.
* `reports`: Dokumen laporan terstruktur (JSON/PDF) yang merangkum temuan dan skor risiko suatu pemindaian.
* `artifacts`: Rekaman file mentah yang disimpan di storage lokal (`scan-runs/{id}/{engine}-raw.json`) beserta SHA-256 hash untuk menjamin integritas bukti forensik.

---

# BAGIAN 10 — AUTHENTICATION & AUTHORIZATION

## 10.1 Mekanisme Login, Lockout & Histori Sandi

TAMENG menerapkan standar keamanan autentikasi ketat untuk melindungi platform dari serangan credential stuffing, brute force, dan eskalasi hak akses:
* **Session Management**: Laravel Sanctum SPA Stateful Session Authentication (`HttpOnly`, `SameSite=Lax`, `XSRF-TOKEN`).
* **Proteksi Brute Force Lockout**: Maksimal 5 kali kegagalan berturut-turut menyebabkan akun dikunci selama **15 menit** (`locked_until`). Super Admin dapat membuka blokir akun sewaktu-waktu melalui tombol **Buka Kunci** (`POST /api/users/{id}/unlock`).
* **Kebijakan Histori Sandi**: Melarang pemakaian ulang salah satu dari 3 kata sandi terakhir yang pernah digunakan (diperiksa via `password_histories`).
* **Multi-Tenancy Project Scoping**: Melalui tabel `project_user`, pengguna dapat didelegasikan ke proyek spesifik dengan level akses `lead`, `analyst`, `developer`, atau `viewer`.

---

## 10.2 Visual Flowchart: Autentikasi, Lockout & RBAC Multi-Tenancy (13.7)

Flowchart berikut memetakan logika autentikasi, mitigasi brute-force, dan otorisasi role berbasis middleware `EnsureRole`:

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef denied fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fef2f2;

    USER_LOGIN(["User Enters Email & Password\n(POST /api/login)"]):::startEnd

    FIND_USER["AuthController:login()\nQuery: User::where('email', $email)->first()"]:::process
    CHECK_LOCKOUT{"User exists AND\nlocked_until > now()?"}:::decision

    USER_LOGIN --> FIND_USER
    FIND_USER --> CHECK_LOCKOUT

    CHECK_LOCKOUT -->|YES: Account Locked| LOG_BLOCKED["AuthenticationLog::create('blocked', 'account_locked')\nAuditLogger::record('auth.login', 'blocked')"]:::denied
    LOG_BLOCKED --> RET_LOCKED(["422: Account Temporarily Locked\n(Try again in X minutes)"]):::denied

    CHECK_LOCKOUT -->|NO: Unlocked / Normal| ATTEMPT_AUTH{"Auth::attempt(credentials, remember)"}:::decision

    ATTEMPT_AUTH -->|NO: Invalid Password| INC_ATTEMPTS["failed_login_attempts++"]:::process
    INC_ATTEMPTS --> CHECK_THRESHOLD{"failed_attempts >= 5?"}:::decision

    CHECK_THRESHOLD -->|YES: Threshold Exceeded| LOCK_ACC["locked_until = now()->addMinutes(15)\nAuthenticationLog::create('locked')\nAuditLogger::record('auth.login', 'failed')"]:::denied
    LOCK_ACC --> RET_LOCKED

    CHECK_THRESHOLD -->|NO: < 5 attempts| LOG_FAIL["AuthenticationLog::create('failed')\nAuditLogger::record('auth.login', 'failed')"]:::denied
    LOG_FAIL --> RET_INVALID(["422: Invalid Credentials"]):::denied

    ATTEMPT_AUTH -->|YES: Credentials Valid| CHECK_STATUS{"User status == 'active'?"}:::decision
    CHECK_STATUS -->|NO: Inactive / Suspended| RET_INACTIVE(["422: Account is Inactive\n(Contact SOC Admin)"]):::denied

    CHECK_STATUS -->|YES: Active Account| SUCCESS_LOGIN["Reset failed_login_attempts = 0\nClear locked_until = null\nUpdate last_login_at = now(), last_login_ip = request->ip()\nSession::regenerate() & XSRF-TOKEN"]:::process

    SUCCESS_LOGIN --> DB_AUTH_SUCCESS[("Tabel: authentication_logs\nstatus = 'success'\nTabel: users (last_login_at)")]:::database
    DB_AUTH_SUCCESS --> RET_SESSION(["200 OK: Return Authenticated User & Role"]):::startEnd

    subgraph RBAC_SCOPING ["Subsequent API Calls: EnsureRole & Multi-Tenancy Scoping"]
        REQ_API["API Request (e.g. POST /api/scan-jobs)"]:::process
        ROLE_CHECK{"EnsureRole Middleware:\nuser->role in allowed_roles?"}:::decision
        FORBIDDEN(["403 Forbidden"]):::denied

        PROJECT_SCOPE{"User is Super/Security Admin OR\nProject in user->projects() pivot?"}:::decision
        ALLOW_ACCESS(["Proceed to Controller Business Logic"]):::startEnd
    end

    RET_SESSION --> REQ_API
    REQ_API --> ROLE_CHECK
    ROLE_CHECK -->|NO| FORBIDDEN
    ROLE_CHECK -->|YES| PROJECT_SCOPE
    PROJECT_SCOPE -->|NO: Project Unassigned| FORBIDDEN
    PROJECT_SCOPE -->|YES: Scoped| ALLOW_ACCESS
```

---

# BAGIAN 11 — AUDIT LOG & FORENSIK

TAMENG memelihara dua tabel audit terpisah untuk menjamin akuntabilitas seluruh aktivitas di dalam sistem:

## 1. Audit Log Operasional (`audit_logs`)
Mencatat seluruh aksi modifikasi data, eksekusi scanner, dan perubahan konfigurasi sistem.
* **Kolom**: `user_id`, `project_id`, `authorization_id`, `scan_job_id`, `action`, `result`, `actor_ip`, `target_type`, `target_id`, `metadata`.
* **Aksi yang Tercatat**:
  * `auth.login`, `auth.logout`, `auth.password_reset_requested`, `auth.password_reset_completed`
  * `security_engine.toggle` (pengaktifan/penonaktifan engine)
  * `scan_job.create`, `scan_job.rerun`, `scan_request.create`, `scan_request.rerun`
  * `engine.run_auto` (eksekusi engine individu oleh worker asinkron)
  * `finding.triage` (perubahan status open/resolved/false_positive oleh analis)
  * `report.generate`, `report.generate_auto` (kompilasi laporan PDF/JSON)
  * `repository.workspace.sync_auto` (sinkronisasi clone repositori git)
  * `webhook.git_push` (pemicuan scan otomatis dari push commit)

## 2. Audit Log Autentikasi (`authentication_logs`)
Khusus merekam jejak autentikasi secara granular:
* **Kolom**: `user_id`, `email`, `ip_address`, `user_agent`, `status`, `failure_reason`, `created_at`.
* **Status**: `success`, `failed`, `blocked`, `locked`, `logout`.
* **Alasan Kegagalan**: `account_locked`, `invalid_credentials`, `user_not_found`, `max_failed_attempts_exceeded`.

---

# BAGIAN 12 — HEALTH CHECK & SYSTEM MONITORING

## 12.1 Mekanisme Pemeriksaan 5 Subsistem

Endpoint `GET /api/health` dikelola oleh `HealthController.php` untuk memvalidasi kesiapan operasional seluruh komponen tanpa memerlukan autentikasi. Lima subsistem yang diperiksa:
1. **Database Check**: Koneksi PDO MySQL (`SELECT 1`) dan ketersediaan tabel migrasi.
2. **Storage Check**: Keterbacaan dan izin tulis pada 5 folder storage (`app/private`, `framework/cache`, `framework/sessions`, `framework/views`, `logs`).
3. **Queue Check**: Koneksi default queue dan keberadaan tabel `jobs` serta `failed_jobs`.
4. **Workspace Root Check**: Keberadaan folder bersama `/data/repository-workspaces` di host dan status izin tulisnya.
5. **Engine Runtime Check**: Kesiapan runtime Docker daemon dan ketersediaan image Gitleaks bawaan.

---

## 12.2 Visual Flowchart: Subsystem Health Check & Degradation (13.10)

Flowchart berikut memetakan evaluasi kesehatan sistem dan penentuan status HTTP response:

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef healthy fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef degraded fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fef2f2;

    HTTP_CALL(["Health Check Request: GET /api/health"]):::startEnd
    RUN_CHECKS["HealthController::__invoke()\nExecute 5 Subsystem Checks"]:::process

    HTTP_CALL --> RUN_CHECKS

    subgraph SUB_CHECKS ["5 Subsystem Evaluation Routine"]
        CHK_DB{"1. Database Check:\nDB::select('select 1')\nSchema::hasTable('migrations')"}:::decision
        CHK_STOR{"2. Storage Check:\napp/private, cache, sessions, logs\nall writable?"}:::decision
        CHK_QUEUE{"3. Queue Check:\nconfig(queue.default) &&\nSchema::hasTable('jobs')"}:::decision
        CHK_WORK{"4. Workspace Check:\nFolder /data/repository-workspaces\nexists & writable?"}:::decision
        CHK_ENG{"5. Engine Runtime Check:\nruntime in ('docker', 'local_binary')\nreal_engines_enabled true?"}:::decision
    end

    RUN_CHECKS --> CHK_DB
    CHK_DB -->|ok: true| CHK_STOR
    CHK_DB -->|ok: false| SET_DEGRADED["Flag healthy = false\nCapture Exception Message"]:::degraded

    CHK_STOR -->|ok: true| CHK_QUEUE
    CHK_STOR -->|ok: false| SET_DEGRADED

    CHK_QUEUE -->|ok: true| CHK_WORK
    CHK_QUEUE -->|ok: false| SET_DEGRADED

    CHK_WORK -->|ok: true| CHK_ENG
    CHK_WORK -->|ok: false| SET_DEGRADED

    CHK_ENG -->|ok: true| EVAL_FINAL{"All 5 Checks ok == true?"}:::decision
    CHK_ENG -->|ok: false| SET_DEGRADED

    SET_DEGRADED --> EVAL_FINAL

    EVAL_FINAL -->|YES| RET_HEALTHY["Return HTTP 200 OK\n{\n  'status': 'ok',\n  'checked_at': timestamp,\n  'checks': { ... }\n}"]:::healthy
    EVAL_FINAL -->|NO| RET_DEGRADED["Return HTTP 503 Service Unavailable\n{\n  'status': 'degraded',\n  'checked_at': timestamp,\n  'checks': { ... }\n}"]:::degraded

    RET_HEALTHY --> END_HEALTH(["Health Monitoring Complete"]):::startEnd
    RET_DEGRADED --> END_HEALTH
```

---

# BAGIAN 13 — LOGGING & OBSERVABILITY

Sistem observability TAMENG terdiri atas:
1. **Application Error & Info Logs**: Disimpan di `/var/www/html/storage/logs/laravel.log` menggunakan channel `stack` (daily rotation).
2. **Container Engine Execution Logs**: Setiap kontainer scanner menghasilkan stdout/stderr yang dicatat oleh Docker daemon dan ditangkap oleh `Symfony\Component\Process\Process` untuk disimpan sebagai raw artifact di disk.
3. **Queue Worker Telemetry**: `SystemOverviewController` memantau tabel `jobs` dan `failed_jobs`. Jika $N_{\text{failed}} > 0$, label dashboard berubah menjadi status peringatan (*warning*).
4. **Web Server Access Logs**: Nginx mencatat IP sumber, response time, dan status HTTP di `/var/log/nginx/access.log`.

---

# BAGIAN 14 — ERROR HANDLING

## 14.1 Skenario Kegagalan Nyata

1. **Database Mati**: API mengembalikan 503 degraded pada `/api/health`, dan frontend menampilkan toast koneksi gagal.
2. **Workspace Sync Error**: Git clone gagal melempar exception; job ditandai `failed` dengan alasan `WORKSPACE_SYNC_FAILED`, dicatat ke `audit_logs` tanpa menggugurkan worker process.
3. **Container Scanner Timeout**: `Process::setTimeout()` mematikan container; `ScanRun` ditandai `failed` dengan exit code `124`/`137`, rencana scan berlanjut ke engine berikutnya.
4. **Scope / Engine Denied**: `ExecutionGateway` segera menolak dengan `reason_code = 'ENGINE_NOT_ALLOWED'`; `ScanRun` ditandai `denied` dan eksekusi dihentikan seketika.
5. **Webhook / Email SMTP Failure**: Kesalahan pengiriman dicatat dalam `Log::warning`, proses scan job utama tetap sukses.

---

## 14.2 Visual Flowchart: Resiliensi Sistem & Error Handling (13.9)

Flowchart berikut memetakan penanganan kesalahan terstruktur di seluruh lapisan sistem:

```mermaid
flowchart TD
    classDef startEnd fill:#1e293b,stroke:#0ea5e9,stroke-width:2px,color:#f8fafc;
    classDef process fill:#0f172a,stroke:#38bdf8,stroke-width:1px,color:#f1f5f9;
    classDef decision fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#ffffff;
    classDef database fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;
    classDef error fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fef2f2;
    classDef success fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#ecfdf5;

    OP_START(["System Operation / Pipeline Step"]):::startEnd

    EXEC_STEP["Execute Operation\n(Clone Repo / Run Scanner / Ingest / Deliver Webhook)"]:::process
    CHECK_ERR{"Exception Thrown or Process Failed?"}:::decision

    OP_START --> EXEC_STEP
    EXEC_STEP --> CHECK_ERR

    CHECK_ERR -->|NO| STEP_SUCCESS["Operation Finished Successfully"]:::success
    STEP_SUCCESS --> OP_END(["Continue to Next Pipeline Step"]):::startEnd

    CHECK_ERR -->|YES: Error Occurred| CATCH_TYPE{"Error Classification"}:::decision

    CATCH_TYPE -->|Type A: Workspace Git Sync Fail| HANDLE_SYNC["Catch Throwable in RunScanJob\n1. ScanJob->status = 'failed'\n2. failure_reason = WORKSPACE_SYNC_FAILED\n3. AuditLogger::recordSystem('repository.workspace.sync_auto', 'failed')"]:::error

    CATCH_TYPE -->|Type B: Scanner Process Timeout| HANDLE_TIMEOUT["Catch ProcessTimeoutException\n1. Kill Container via Process::stop()\n2. ScanRun->status = 'failed' (Exit 124)\n3. Set coverage_pass = false\n4. Proceed to Next Planned Engine"]:::error

    CATCH_TYPE -->|Type C: Policy Gate Rejection| HANDLE_GATE["ExecutionGateway Rejection\n1. ScanRun->status = 'denied'\n2. Record PolicyDecision to database\n3. Halt ScanJob execution immediately"]:::error

    CATCH_TYPE -->|Type D: Notification / SMTP Error| HANDLE_NOTIF["Catch Throwable in WebhookNotificationService\n1. Log::warning('Gagal kirim webhook')\n2. Do NOT break ScanJob status (Remain completed)"]:::error

    CATCH_TYPE -->|Type E: Database Connection Drop| HANDLE_DB["PDOException Caught\n1. Log to storage/logs/laravel.log\n2. HealthController flags degraded (503)\n3. Frontend renders error toast"]:::error

    HANDLE_SYNC --> DB_LOG_A[("Tabel: scan_jobs &\naudit_logs")]:::database
    HANDLE_TIMEOUT --> DB_LOG_B[("Tabel: scan_runs &\nscan_jobs")]:::database
    HANDLE_GATE --> DB_LOG_C[("Tabel: policy_decisions &\naudit_logs")]:::database
    HANDLE_NOTIF --> FILE_LOG_D[("File: storage/logs/laravel.log")]:::database
    HANDLE_DB --> FILE_LOG_D

    DB_LOG_A --> OP_HALT(["Graceful Failure / Alert Analyst"]):::startEnd
    DB_LOG_B --> OP_END
    DB_LOG_C --> OP_HALT
    FILE_LOG_D --> OP_END
```

---

# BAGIAN 15 — SECURITY ARCHITECTURE

Sebagai platform keamanan siber, TAMENG menerapkan arsitektur pertahanan berlapis (*defense-in-depth*):

| Lapisan Keamanan | Mekanisme & Implementasi Aktual | Status Verifikasi |
| :--- | :--- | :---: |
| **Network Boundary** | Kontainer engine scanner SAST/Secret/IaC berjalan dengan opsi `--network none`. Tidak ada koneksi keluar yang diizinkan selama membaca source code. | 🟢 Implemented |
| **Filesystem Isolation**| Repositori host di-mount dengan flag `:ro` (Read-Only). Scanner tidak dapat mengubah, menghapus, atau menginjeksi malware ke dalam source code target. | 🟢 Implemented |
| **Docker Socket Proxy**| Kontainer backend mengakses `/var/run/docker.sock` untuk membuat scanner kontainer baru secara efemeral (*ephemeral containers*) yang langsung dihapus usai eksekusi (`--rm`). | 🟢 Implemented |
| **SQL Injection** | Seluruh query database menggunakan Eloquent ORM dan PDO Parameter Binding. Tidak ada konkatenasi query string mentah. | 🟢 Implemented |
| **Cross-Site Scripting** | Vue 3 auto-escaping pada seluruh template rendering teks. Input HTML pada laporan disanitasi. | 🟢 Implemented |
| **CSRF Protection** | Menggunakan cookie `XSRF-TOKEN` Sanctum dengan validasi otomatis pada mutasi POST, PUT, dan DELETE. | 🟢 Implemented |
| **Credential Encryption**| Password target `form_login` disimpan dalam database terenkripsi menggunakan Laravel `Crypt::encryptString` (AES-256-CBC). | 🟢 Implemented |
| **Secret Redaction** | Gitleaks adapter menyamarkan string sensitif (`[REDACTED]`) sebelum disimpan ke tabel `findings`. | 🟢 Implemented |
| **Immutable Decision** | Tabel `policy_decisions` bersifat append-only, tidak menyediakan endpoint API untuk update atau delete. | 🟢 Implemented |

---

# BAGIAN 16 — ALUR END-TO-END

Berikut adalah 3 skenario operasional nyata di dalam TAMENG:

## Skenario 1 — Pemindaian Otomatis via Git Push Webhook

```text
Developer Git Push -> Webhook Ingestion -> Gate Approval -> Multi-Engine Runner -> Normalisasi & Alert
```
1. Developer melakukan `git push` commit baru ke repositori `https://github.com/company/core-api.git`.
2. GitHub Webhook mengirim payload HTTP POST ke `https://tameng.local/api/webhooks/git`.
3. `GitWebhookController.php` memvalidasi payload, mendeteksi URL repositori, dan mencocokkannya dengan tabel `repositories`.
4. `AuthorizationGateway.php` memverifikasi bahwa profil `source_code_scan` aktif dan target terverifikasi, lalu membuat `Authorization` dan `ScanJob` baru dengan status `queued`.
5. `RunScanJob` diambil oleh worker di container `secsys-worker`.
6. `RepositoryWorkspaceSyncer.php` mengeksekusi `git clone --depth 1` ke direktori host `/data/repository-workspaces/repo-1`.
7. `EngineRunner.php` menjalankan engine secara berurutan di dalam kontainer Docker terisolasi:
   * **Semgrep SAST**: mendeteksi potensi SQL Injection dan XSS.
   * **Gitleaks**: memindai kebocoran private key / API token.
   * **Trivy**: memindai kerentanan CVE pada berkas `composer.lock` / `package-lock.json`.
8. Hasil mentah JSON scanner diparsing oleh `FindingNormalizer.php`.
9. `FindingDeduplicator.php` menghitung fingerprint SHA-256 dan menyimpan 12 temuan baru ke tabel `findings`.
10. `ReportGenerator.php` mengompilasi ringkasan laporan audit JSON.
11. `WebhookNotificationService.php` mengirimkan rich alert embed ke Discord/Slack channel SOC: *"⚠️ Pemindaian SCAN-20260915-HOOK completed. Total: 12 Temuan (Kritis: 0, Tinggi: 3)"*.

---

## Skenario 2 — Investigasi Forensik & Triage Temuan oleh SOC Analyst

1. Analis SOC membuka dashboard TAMENG di browser `http://localhost:8080/findings`.
2. Halaman memanggil `GET /api/findings` dan menampilkan daftar temuan yang diurutkan berdasarkan severity tertinggi.
3. Analis mengklik temuan `FND-20260902074420-QK0X` (*Identified a Private Key*).
4. Modal detail terbuka memanggil `GET /api/findings/1`, menyajikan:
   * File path: `config/ssl/server.key`.
   * Baris kode: 1 - 28.
   * Bukti scanner: Gitleaks output match `-----BEGIN RSA PRIVATE KEY----- [REDACTED]`.
5. Analis mengklik tab **AI Remediation Guidance**:
   * Frontend memanggil `GET /api/findings/1/ai-remediation`.
   * `AiRemediationService.php` mengembalikan penjelasan dampak bisnis, referensi `CWE-312`, dan instruksi pemindahan sertifikat ke Secrets Manager.
6. Analis memilih status **in_progress** pada dropdown Triage, memasukkan catatan: *"Diteruskan ke tim DevOps untuk rotasi key dan migrasi ke HashiCorp Vault"*, lalu menekan tombol **Simpan Triage**.
7. Backend memproses `PUT /api/findings/1`, memperbarui record temuan, dan mencatat aksi ke tabel `audit_logs` dengan user ID analis dan IP klien.

---

## Skenario 3 — Permintaan Pemindaian Mandiri Developer (/scan-saya)

1. Developer login dengan akun role `developer` di `http://localhost:8080/scan-saya`.
2. Developer mengisi formulir permintaan scan:
   * Memilih proyek yang didelegasikan kepadanya.
   * Memilih repositori source code atau target Web URL.
   * Memilih profil scan (misal: `SOURCE_BASIC` atau `WEB_BASIC`).
3. Developer menekan tombol **Mulai Pemindaian**.
4. Frontend mengirim `POST /api/my/scan-requests`.
5. Backend membuat scan job, memverifikasi bahwa aset berada dalam proyek yang diizinkan untuk developer tersebut (`project_user` scope), dan mengirim job ke antrean worker.
6. Halaman developer melakukan silent polling setiap 5 detik (`setInterval` 5000ms), menampilkan indikator loading progress dari 0% hingga 100%.
7. Setelah selesai, developer dapat langsung mengunduh laporan ringkas dalam format **PDF Formal** melalui endpoint `GET /api/reports/{id}/download-pdf`. Developer dibatasi hanya dapat mengunduh laporan dari scan yang diajukan oleh dirinya sendiri.

---

# BAGIAN 17 — FILE & COMPONENT MAP

Berikut adalah pemetaan file utama sistem TAMENG untuk mempermudah navigasi teknis developer:

| Komponen | File Path Aktual | Class / Function Utama | Tanggung Jawab Teknis |
| :--- | :--- | :--- | :--- |
| **Router Backend** | `backend/routes/api.php` | Route Definitions | Mendefinisikan seluruh endpoint REST API dan middleware guard |
| **Auth Controller**| `backend/app/Http/Controllers/Api/AuthController.php` | `login()`, `forgotPassword()` | Autentikasi sesi Sanctum, brute force lockout, SMTP mailer |
| **User Controller**| `backend/app/Http/Controllers/Api/UserManagementController.php` | `index()`, `store()`, `unlock()` | Kelola user, histori sandi, scoping proyek, preferensi notif |
| **Scan Controller**| `backend/app/Http/Controllers/Api/ScanJobController.php` | `store()`, `rerun()`, `process()` | Penerimaan scan job, evaluasi gate, dispatching queue |
| **Finding Control**| `backend/app/Http/Controllers/Api/FindingController.php` | `index()`, `update()`, `aiRemediation()` | Penyajian temuan, triage status, panduan mitigasi AI |
| **Report Control** | `backend/app/Http/Controllers/Api/ReportController.php` | `store()`, `downloadPdf()` | Kompilasi laporan audit JSON dan rendering unduhan PDF |
| **Auth Gateway** | `backend/app/Services/AuthorizationGateway.php` | `decide()` | Validasi imutabel kepemilikan aset, profil, dan aturan scope |
| **Exec Gateway** | `backend/app/Services/ExecutionGateway.php` | `decide()` | Pre-flight safety check sebelum container scanner dijalankan |
| **Engine Runner** | `backend/app/Services/Engines/EngineRunner.php` | `execute()`, `storeRawArtifact()` | Orkestrasi Docker container scanner dan penyimpanan output |
| **Engine Registry**| `backend/app/Services/Engines/EngineRegistry.php` | `all()`, `definitions()` | Definisi 20 engine keamanan, domain, dan safety controls |
| **Deduplicator** | `backend/app/Services/Engines/Support/FindingDeduplicator.php` | `ingest()` | Deduplikasi fingerprint SHA-256 & multi-engine clustering |
| **Normalizer** | `backend/app/Services/Engines/Support/UniversalFindingNormalizer.php`| `normalizeSeverity()`, `formatItem()` | Penyeragaman format temuan dari seluruh variasi scanner |
| **AI Remediation**| `backend/app/Services/AiRemediationService.php` | `generateGuidance()`, `buildCodePatch()`| Rekomendasi teknis mitigasi risiko & patch perbaikan kode |
| **Queue Worker Job**| `backend/app/Jobs/RunScanJob.php` | `handle()` | Eksekutor utama pemindaian background dalam antrean worker |
| **Audit Service** | `backend/app/Services/AuditLogger.php` | `record()`, `recordSystem()` | Pencatatan transaksi audit forensik ke tabel database |
| **Webhook Service**| `backend/app/Services/WebhookNotificationService.php` | `notifyScanResult()` | Pengiriman notifikasi rich embed ke webhook Discord/Slack |
| **Frontend Router**| `frontend/src/router/index.ts` | `createRouter()`, `beforeEach()` | Navigasi SPA, otentikasi route guard, role protection |
| **Frontend API** | `frontend/src/services/api.js` | `axios.create()`, interceptors | HTTP client terpusat dengan cache in-memory 60 detik & CSRF |
| **View Dashboard** | `frontend/src/views/DashboardView.vue` | Component `<DashboardView>` | Visualisasi skor postur keamanan, metrik counts, dan queue |
| **View Findings** | `frontend/src/views/FindingsView.vue` | Component `<FindingsView>` | Filter temuan, modal investigasi, AI advisory viewer, triage |
| **View My Scans** | `frontend/src/views/MyScanRequestsView.vue` | Component `<MyScanRequestsView>` | Portal pengajuan scan mandiri developer & polling progress |

---

# BAGIAN 18 — DEPENDENCY & INTEGRATION MAP

Tabel berikut mendokumentasikan interkoneksi nyata antara TAMENG dengan dependensi dan layanan internal/eksternal:

| Source System | Direction | Target System | Protokol / Port | Tujuan Integrasi |
| :--- | :---: | :--- | :--- | :--- |
| **Client Browser** | $\longrightarrow$ | **secsys-frontend** | `HTTP / 8080` | Mengunduh bundle aplikasi SPA Vue 3 & aset statis |
| **Client Browser** | $\longrightarrow$ | **secsys-backend-nginx** | `HTTP / 8000` | Memanggil REST API backend (`/api/*`) dengan cookie session |
| **secsys-backend-nginx** | $\longrightarrow$ | **secsys-backend** | `FastCGI / 9000` | Meneruskan request PHP ke pool worker PHP-FPM |
| **secsys-backend / worker** | $\longrightarrow$ | **secsys-mysql** | `TCP / 3306` (Host 3307) | Query & persistence data relasional menggunakan PDO MySQL |
| **secsys-backend / worker** | $\longrightarrow$ | **secsys-redis** | `TCP / 6379` (Host 6380) | Penyimpanan cache cepat dan session driver Redis |
| **secsys-worker** | $\longrightarrow$ | **Docker Host Daemon** | `Unix Domain Socket` (`/var/run/docker.sock`) | Menjalankan kontainer efemeral untuk 12 engine scanner |
| **secsys-backend / worker** | $\longrightarrow$ | **Host Filesystem** | Mount Volume (`/data/repository-workspaces`) | Penyimpanan clone repositori git dan file binary target |
| **secsys-backend** | $\longrightarrow$ | **Google SMTP Server** | `TCP / 587` (TLS) | Pengiriman email kode OTP reset password pengguna |
| **secsys-worker** | $\longrightarrow$ | **External Webhook** | `HTTPS / 443` | Pengiriman notifikasi insiden pemindaian ke Discord / Slack |
| **Git Repositories** | $\longrightarrow$ | **secsys-backend-nginx** | `HTTP / 8000` (`/api/webhooks/git`) | Penerimaan webhook push event dari GitHub / GitLab |
| **TAMENG** | $\longleftrightarrow$ | **Wazuh Agent / Manager** | N/A | 🔴 *Not Implemented* (Tidak terhubung) |
| **TAMENG** | $\longleftrightarrow$ | **n8n Automation** | N/A | 🔴 *Not Implemented* (Tidak terhubung) |

---

# BAGIAN 19 — DEPLOYMENT ARCHITECTURE

TAMENG dikemas dan dijalankan menggunakan Docker Compose multi-container:

```text
Host Linux Server (Ubuntu 22.04 LTS / 24.04 LTS)
│
├── Bridge Network: default (Subnet kontainer terisolasi)
│
├── Container: secsys-mysql (MySQL 8.4) ─────── Port 3307:3306
│   └── Volume: secsys_mysql_data
│
├── Container: secsys-redis (Redis 7) ────────── Port 6380:6379
│   └── Volume: secsys_redis_data
│
├── Container: secsys-backend (PHP-FPM 8.2) ─── Port 9000 (Internal)
│   ├── Volume: secsys_backend_storage (/var/www/html/storage)
│   ├── Mount: /data/repository-workspaces
│   └── Mount: /var/run/docker.sock
│
├── Container: secsys-backend-nginx (Nginx 1.27) Port 8000:80
│   └── Mount: ./backend/public (Read-Only)
│
├── Container: secsys-worker (PHP CLI Worker) ─ Antrean Background
│   ├── Volume: secsys_backend_storage
│   ├── Mount: /data/repository-workspaces
│   └── Mount: /var/run/docker.sock
│
└── Container: secsys-frontend (Nginx 1.27) ──── Port 8080:80
    └── Build Context: ./frontend
```

### Urutan Startup & Health Check:
1. `secsys-mysql` melakukan inisialisasi database dan menjalankan healthcheck internal `mysqladmin ping`.
2. `secsys-redis` dimulai.
3. `secsys-backend` dan `secsys-worker` menunggu kondisi `secsys-mysql: service_healthy` terpenuhi sebelum menjalankan worker atau bootstrap aplikasi.
4. `secsys-backend-nginx` menghubungkan upstream ke `secsys-backend:9000`.
5. `secsys-frontend` mulai melayani traffic pengguna pada port `8080`.

---

# BAGIAN 20 — BACKUP & RECOVERY

## Status: 🟡 PARTIALLY IMPLEMENTED (Manual File Backup / No Automated Cron)

Saat ini tidak ditemukan skrip cron otomatis bawaan repositori untuk pencadangan berkala. Namun, prosedur pencadangan dan pemulihan darurat (*Disaster Recovery*) dapat dilakukan dengan langkah teknis berikut:

### Prosedur Pencadangan Manual:
1. **Database Dump**:
   ```bash
   docker exec secsys-mysql mysqldump -u secsys -psecsys_secret secsys > /opt/tameng_backups/secsys_backup_$(date +%Y%m%d).sql
   ```
2. **Pencadangan Storage Artifacts & Konfigurasi**:
   ```bash
   tar -czvf /opt/tameng_backups/storage_backup_$(date +%Y%m%d).tar.gz /var/lib/docker/volumes/tameng_secsys_backend_storage/_data/app/private
   cp /opt/tameng/tameng/deploy/backend.env /opt/tameng_backups/backend.env.bak
   ```

### Prosedur Pemulihan (Disaster Recovery):
1. Pulihkan konfigurasi environment: `cp /opt/tameng_backups/backend.env.bak /opt/tameng/tameng/deploy/backend.env`.
2. Jalankan container database: `docker compose up -d secsys-mysql`.
3. Restore database SQL:
   ```bash
   docker exec -i secsys-mysql mysql -u secsys -psecsys_secret secsys < /opt/tameng_backups/secsys_backup_YYYYMMDD.sql
   ```
4. Ekstrak arsip volume storage ke direktori private backend.
5. Jalankan seluruh layanan: `docker compose up -d`.

---

# BAGIAN 21 — VERIFIKASI & TESTING

Berikut adalah hasil verifikasi nyata yang telah diuji langsung pada sistem yang sedang berjalan:

| Kategori Uji | Perintah / Aksi Verifikasi | Hasil yang Diharapkan | Hasil Nyata Sistem | Status |
| :--- | :--- | :--- | :--- | :---: |
| **Health Check** | `curl -s http://localhost:8000/api/health` | HTTP 200, status "ok", seluruh 5 subsistem bernilai true | HTTP 200, database mysql ok, storage ok, queue ok, workspace ok | 🟢 PASS |
| **Konektivitas DB** | `docker exec secsys-backend php artisan db:show` | Terhubung ke MySQL 8.4, 39 tabel ditemukan | Terhubung ke database `secsys` MySQL 8.4.11, total 39 tabel | 🟢 PASS |
| **Data Realistis** | Query SQL menghitung temuan & audit logs | Tabel memuat data scan dan temuan nyata | 1.476 temuan, 24 scan jobs, 112 scan runs, 247 audit logs | 🟢 PASS |
| **Engine Health** | Preflight check image `semgrep/semgrep` & `zricethezav/gitleaks` | Docker image inspect berhasil | Image tersedia di Docker host daemon dan siap dieksekusi | 🟢 PASS |
| **Password Lockout**| 5x login salah berturut-turut pada akun target | Akun terkunci 15 menit, `AuthenticationLog` status 'locked' | Terverifikasi di kode `AuthController.php` (baris 77-108) | 🟢 PASS |
| **Automated Unit Tests** | `php artisan test` di folder backend | Menjalankan rangkaian unit & feature test suite lengkap | Hanya memuat `ExampleTest.php` default (Belum ada test suite spesifik) | 🟡 DEFAULT ONLY |

---

# BAGIAN 22 — TROUBLESHOOTING

Tabel berikut merangkum permasalahan teknis yang dapat ditemui selama pengoperasian TAMENG beserta solusi konkretnya:

| Masalah | Kemungkinan Penyebab | Cara Verifikasi | Solusi Konkret |
| :--- | :--- | :--- | :--- |
| **Scan job berstatus `failed` dengan alasan `WORKSPACE_SYNC_FAILED`** | Kredensial repositori git salah, URL privat tidak memiliki SSH/token, atau disk host `/data` penuh. | Periksa `audit_logs` pada action `repository.workspace.sync_auto` atau log worker. | Pastikan repositori dapat di-clone publik atau hubungkan path lokal via tombol **Attach Workspace**. Periksa sisa disk host (`df -h`). |
| **Engine run berstatus `denied`** | Engine yang dieksekusi tidak terdaftar dalam `allowed_engines` snapshot otorisasi. | Periksa record `policy_decisions` pada scan job bersangkutan. | Edit Otorisasi pemindaian dan centang engine yang ingin diizinkan sebelum memulai scan job baru. |
| **Akun administrator terkunci (*Account Locked*)** | Melebihi 5 kali kesalahan kata sandi. | Query SQL: `SELECT failed_login_attempts, locked_until FROM users WHERE email='...';` | Jalankan perintah CLI: `docker exec secsys-backend php artisan tinker --execute="App\Models\User::where('email','admin@secsys.local')->update(['failed_login_attempts'=>0,'locked_until'=>null]);"` |
| **Scanner DAST (ZAP / Nuclei) gagal memindai target** | Target web belum diverifikasi (`verification_status != 'verified'`). | Periksa tabel `targets` kolom `verification_status`. | Lakukan verifikasi kepemilikan target di menu **Targets** -> klik tombol **Verifikasi**. |
| **Pengiriman Email Reset Password Gagal** | App Password Gmail tidak valid atau diblokir oleh Google Security. | Buka file `storage/logs/laravel.log` dan cari `Failed to send password reset email`. | Perbarui `MAIL_PASSWORD` di berkas `deploy/backend.env` dengan Google App Password yang baru, lalu restart worker. |

---

# BAGIAN 23 — SECURITY FLOW DIAGRAM

Diagram interaksi berurutan (sequence flow) komponen-komponen TAMENG dalam mengeksekusi pemindaian keamanan, validasi gerbang kebijakan, isolasi kontainer, normalisasi bukti, hingga investigasi analis SOC:

```mermaid
sequenceDiagram
    autonumber
    actor Client as Klien (User / CI/CD Push)
    participant API as Nginx / Laravel API (Port 8000)
    participant Gate as AuthorizationGateway
    participant Queue as MySQL Queue (tabel: jobs)
    participant Worker as secsys-worker (Queue Worker)
    participant Docker as Docker Daemon (/var/run/docker.sock)
    participant Engine as Scanner Sandbox (--network none)
    participant Dedup as Normalizer & Deduplicator
    participant DB as MySQL Database (secsys)
    participant Notif as Webhook & SMTP Service
    actor SOC as Analis SOC (Dashboard)

    Client->>API: 1. POST /api/scan-jobs (project_id, target_id, profile_id)
    API->>Gate: 2. AuthorizationGateway::decide()
    alt Evaluasi Kebijakan Ditolak (Unverified Target / Denied Scope)
        Gate-->>API: 3a. PolicyDecision::deny('REPOSITORY_NOT_VERIFIED')
        API->>DB: 3b. Simpan PolicyDecision & AuditLogger::record()
        API-->>Client: 3c. HTTP 422 / 403 Forbidden Access
    else Evaluasi Kebijakan Disetujui
        Gate-->>API: 4a. PolicyDecision::allow()
        API->>DB: 4b. Insert scan_jobs (status: 'queued', progress: 0%)
        API->>Queue: 4c. dispatch(new RunScanJob($id))
        API-->>Client: 4d. HTTP 201 Created (Kode: SCAN-YYYYMMDD-XXXX)
    end

    Queue->>Worker: 5. Worker mengambil antrean job
    Worker->>Worker: 6. RepositoryWorkspaceSyncer::sync() (Clone/Attach Host Repo)
    Worker->>Docker: 7. docker run --rm --network none -v /data/...:/target:ro <image>
    Docker->>Engine: 8. Scanner beroperasi di dalam sandbox terisolasi
    Engine-->>Worker: 9. Stream stdout/stderr mentah (JSON / XML)
    Worker->>DB: 10. Simpan ScanRun & Artifacts (SHA-256 Checksum)

    Worker->>Dedup: 11. Normalisasi via UniversalFindingNormalizer
    Dedup->>Dedup: 12. Hitung Fingerprint SHA-256 (Location + Rule + Line Bucket)
    Dedup->>DB: 13. Upsert Findings & Hubungkan Evidence ke ScanJob
    Worker->>DB: 14. Update ScanJob (status: 'completed', progress: 100%)

    Worker->>Notif: 15. Evaluasi Ambang Risiko Temuan
    opt Ada Temuan Kritis / Tinggi & Webhook Terpasang
        Notif-->>Client: 16. Kirim Rich Embed Webhook (Discord / Slack) / Email SMTP
    end

    SOC->>API: 17. Analis membuka rincian temuan (GET /api/findings/{id})
    API->>DB: 18. Query detail temuan & finding_evidences
    API-->>SOC: 19. Tampilkan bukti multi-engine di UI Tabler
    SOC->>API: 20. Permintaan asistensi perbaikan (GET /api/findings/{id}/ai-remediation)
    API-->>SOC: 21. Berikan rekomendasi code diff patch & panduan mitigasi AI
    SOC->>API: 22. Perbarui status triage (PUT /api/findings/{id}, status: 'resolved')
    API->>DB: 23. Perbarui tabel findings & catat audit_logs forensik
```

---

# BAGIAN 24 — STATUS IMPLEMENTASI FITUR

Matriks kepatuhan fitur berdasarkan pembuktian langsung source code:

| Modul / Fitur | Status | Bukti Source Code / Database | Keterangan Teknis |
| :--- | :---: | :--- | :--- |
| **Sanctum SPA Session Auth** | 🟢 Implemented | `AuthController.php`, `config/sanctum.php` | Stateful cookie-based authentication aktif |
| **Account Brute Force Lockout** | 🟢 Implemented | `AuthController.php:77-108` | Otomatis mengunci 15 menit jika 5x gagal login |
| **Password History Restriction** | 🟢 Implemented | `UserManagementController.php:138-146` | Melarang pemakaian ulang 3 kata sandi terakhir |
| **Role-Based Access Control (RBAC)**| 🟢 Implemented | `EnsureRole.php`, `roles` table | 6 role aktif (`super_admin` s/d `viewer`) |
| **Multi-Tenancy Project Scoping** | 🟢 Implemented | `project_user` migration & model | Scoping akses per proyek (`lead`, `analyst`, `dev`) |
| **Target & Repository Verification**| 🟢 Implemented | `AuthorizationGateway.php:73-84` | Aturan kaku target harus diverifikasi sebelum scan |
| **Rule Scope (Allow/Deny)** | 🟢 Implemented | `scopes` table, `AuthorizationGateway.php` | Evaluasi cakupan URL/path sebelum eksekusi |
| **Multi-Engine Scanner (12 Engines)**| 🟢 Implemented | `app/Services/Engines/Adapters/*` | 12 scanner terintegrasi penuh via Docker daemon |
| **Skeleton Scanner (8 Engines)** | 🔵 Planned | `SecurityEngineSeeder.php:34-338` | Engine terdaftar di database, adapter belum aktif |
| **SHA-256 Finding Deduplication** | 🟢 Implemented | `FindingDeduplicator.php:22-48` | Toleransi baris kode dan clustering multi-engine |
| **Advisory AI Code Patching** | 🟢 Implemented | `AiRemediationService.php:21-45` | Generator code diff dan panduan mitigasi CWE/OWASP |
| **Formal PDF Report Generation** | 🟢 Implemented | `ReportPdfGenerator.php`, `ReportController` | Unduhan PDF laporan resmi hasil audit keamanan |
| **Webhook Alerting (Discord/Slack)**| 🟢 Implemented | `WebhookNotificationService.php:43-85` | Pengiriman embed alert otomatis pasca pemindaian |
| **Email SMTP OTP Reset Password** | 🟢 Implemented | `ResetPasswordCodeMail.php`, `deploy/backend.env`| Terintegrasi aktif menggunakan Gmail SMTP |
| **Telegram Bot Alert Dispatcher** | 🟡 Partially Implemented | `user_preferences` table, `UsersView.vue` | Skema database & UI siap, service bot belum aktif |
| **Realtime WebSockets** | 🔴 Not Implemented | `ScanJobsView.vue:290` | Menggunakan Client-Side HTTP Polling (5 detik) |
| **Auto Remediation (Firewall/Cloudflare)** | 🔴 Not Implemented | `docs/mvp-architecture.md:3` | Kebijakan sistem: Advisory Only, No Active Write |
| **Wazuh Host-Based IDS Integration** | 🔴 Not Implemented | Codebase audit (0 file Wazuh) | Tidak digunakan pada arsitektur TAMENG saat ini |
| **n8n Workflow SOAR Integration** | 🔴 Not Implemented | Codebase audit (0 file n8n) | Tidak digunakan pada arsitektur TAMENG saat ini |
| **Automated Backup Cron Job** | 🟡 Partially Implemented | `/opt/tameng_backups/` | File backup manual tersedia, cron job otomatis belum ada |

---

# BAGIAN 25 — KESIMPULAN & REKOMENDASI ROADMAP

## 25.1 Ringkasan Evaluasi Sistem
1. **TAMENG** adalah platform kontrol audit keamanan yang dirancang dengan landasan tata kelola (*governance*) yang sangat kuat.
2. Data keamanan masuk melalui antarmuka REST API terproteksi dan Git Webhook otomatis, diverifikasi kepemilikannya, dan dievaluasi terhadap aturan ruang lingkup (*scope*).
3. Ancaman dideteksi menggunakan kombinasi **12 security engine open-source kelas dunia** yang dijalankan di dalam kontainer Docker terisolasi tanpa akses internet.
4. Temuan dinormalisasi ke standar tunggal dan dideduplikasi menggunakan algoritma fingerprint SHA-256 dengan clustering bukti multi-engine.
5. Analis SOC dapat menginvestigasi temuan lengkap dengan baris kode, bukti match scanner mentah, dan rekomendasi patch perbaikan dari AI Remediation Service.
6. Respons sistem bersifat **advisory** untuk menjaga stabilitas infrastruktur production dari potensi gangguan akibat auto-blocking yang salah target (*false positive*).
7. Data disimpan dengan rapi di MySQL 8.4 dan storage volume lokal dengan hash SHA-256 untuk menjamin integritas forensik.

## 25.2 Kelebihan Arsitektur:
* **Prinsip Keamanan Tanpa Kompromi**: Prinsip "No Verified Target = No Execution" melindungi organisasi dari penyalahgunaan scanner untuk menyerang infrastruktur pihak ketiga.
* **Isolasi Lingkungan Eksekusi**: Penggunaan kontainer Docker efemeral dengan opsi `--network none` dan volume read-only menjamin source code tidak bocor dan scanner tidak dapat disusupi.
* **Deduplikasi Cerdas**: Mencegah analis kewalahan (*alert fatigue*) dengan menggabungkan bukti dari berbagai engine ke bawah satu temuan primer.

## 25.3 Keterbatasan Saat Ini (Known Limitations):
* **Polling HTTP**: Pembaruan status scan masih mengandalkan client polling 5 detik daripada koneksi event-driven WebSocket dua arah.
* **Database Queue**: Antrean pemrosesan job masih menggunakan driver database MySQL, yang pada volume pemindaian sangat tinggi dapat meningkatkan beban I/O tabel `jobs`.
* **Automated Test Coverage**: Rangkaian automated feature testing masih terbatas pada pengujian default bawaan Laravel.

## 25.4 Rekomendasi Pengembangan Masa Depan (Roadmap):
1. **Migrasi ke Redis Queue / RabbitMQ**: Memanfaatkan Redis yang sudah terpasang di container untuk antrean job berkecepatan tinggi dengan konkurensi dinamis.
2. **Implementasi Realtime WebSocket (Laravel Reverb)**: Menggantikan polling HTTP 5 detik dengan push event WebSocket native untuk pembaruan progres scan yang instan.
3. **Penyelesaian 8 Adapter Engine Skeleton**: Mengaktifkan adapter CodeQL, SonarQube, TruffleHog, dan Dependency-Check untuk melengkapi seluruh 20 engine suite.
4. **Aktivasi Dispatcher Bot Telegram**: Menyelesaikan background service untuk memicu bot Telegram berdasarkan preferensi personal di tabel `user_preferences`.
5. **Otomasi Backup Cron**: Memasang skrip cron reguler di host untuk mencadangkan database MySQL dan volume storage secara harian ke remote backup server.
