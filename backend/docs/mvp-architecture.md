# Security Scan System MVP

SecSys is the deterministic control plane. Workers execute approved jobs only. Security engines produce evidence. SoluAI enriches findings and reports, but never decides target, scope, authorization, final severity, or execution.

## Locked Stack

- Backend: Laravel 12 on PHP 8.2.
- Frontend: Vue with Vite.
- Authentication: internal accounts created by administrators.
- Database: PostgreSQL for deployment, SQLite is acceptable for local bootstrap.
- Queue/cache/status: Redis.
- Artifact storage: MinIO.

## Execution Rules

- No verified target means no execution.
- Outside scope means deny.
- Active scanners must use egress proxy and scope validation.
- Workers are stateless and do not perform AI planning.
- Reports must work without AI.

## MVP Database Areas

- Access control: roles, permissions, users.
- Assets: projects, repositories, targets, verifications, scopes.
- Authorization: immutable authorization records with scope/profile/policy snapshots.
- Execution: scan profiles, scan jobs, workers, scan runs.
- Findings: normalized findings, evidence, dedup keys.
- AI: schema-bound finding or scan analysis.
- Output: reports and artifacts.
- Governance: audit logs and policy decisions.
