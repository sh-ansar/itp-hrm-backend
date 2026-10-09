# Backend architecture (target)

Laravel modular monolith, PostgreSQL primary database, Redis for cache/queue, private file storage. The repository currently contains architecture/migration seeds, not a fully bootstrapped executable Laravel application. Install and verify the Laravel framework skeleton in a supported environment before claiming backend readiness.

## Modules
Organization; Employee; Staffing; Personnel; Leave; Timekeeping; Payroll; Documents; Reporting; Identity; Integration.

## Constraints
- API under `/api/v1`; Form Request validation; authorization via policies and organizational scope.
- Effective-dated assignments and versioned staffing; immutable audit events for personnel mutations.
- Approval workflows via explicit state machines and DB transactions; no side effects before commit.
- Private document storage with authorization for every download, upload MIME validation, virus scanning integration before production.
- Security: secure HTTP-only cookies + Sanctum CSRF for first-party SPA, TLS, data minimization, audit and retention policies.
- PostgreSQL constraints/indices; transactional outbox for integrations when needed; queue jobs idempotent.
- Redis only for cache/queues/locks, never canonical employee records.
- Distinct test/stage/prod secrets, backups, restoration rehearsal and migration rollback plan.
