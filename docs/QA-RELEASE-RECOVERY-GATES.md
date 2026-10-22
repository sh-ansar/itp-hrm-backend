# HRM release, QA and recovery gates

This is an operational checklist, not proof that the system is approved for real employee records.

## Environment boundaries
- The Vercel build is demonstration-only; no canonical personnel data is hosted there.
- Production and staging must use distinct PostgreSQL 16 databases, credentials, object-storage buckets, app keys, mail credentials and domains.
- Never use `migrate:fresh`, synthetic fixtures or QA scripts against production. Verify target DB name, hostname and tenancy before every stateful command.
- Secrets belong in the deployment secret store, not Git, reports, issue bodies or screenshots.

## Before first live personnel record (mandatory release gate)
1. Security owner approves threat model, privacy classification, retention/deletion rules and access matrix (company_admin, hr_specialist, department_manager, employee); test cross-company isolation at API level.
2. Product owner approves exact state-machine definitions for appointments, transfer, termination, leave, payroll-adjacent information and private document handling.
3. DB owner verifies all migrations on fresh PostgreSQL **and** the exact upgrade path from the current deployed schema. Take and verify a consistent backup before migrating.
4. Storage owner verifies PDF/photo private bucket policy, malware scanning/quarantine, encryption/key management, size/MIME checks and authenticated download denial for forbidden roles.
5. Test two-person staged leave review end-to-end with `HRM_LEAVE_MANAGER_APPROVAL_ENABLED=true` only in isolated staging: employee submission -> manager recommendation -> distinct HR final decision; verify rejection, self-review, stale revision, department transfer, role revocation, balance and audit. Keep flag **OFF** in production pending sign-off.
6. Test release artifact and rollback for both independent repositories; pin compatible backend and frontend commits (record both SHAs).
7. Run browser acceptance on desktop and mobile: auth and logout; companies; employee create/edit; org tree; staffing; hire/transfer/terminate; leave; draft document/PDF; all supported locales and dark theme.
8. Verify mail delivery and queued retries, sensitive-data-safe logs, retention and monitoring/alerts. A passing SMTP connectivity check alone is not an end-to-end notification check.

## Every release
- Record change ticket, approver, timestamp, both Git SHAs, migration IDs, backup identifier, and feature flag values.
- Deploy additive backward-compatible backend migrations first, then matching API implementation, then frontend. Never rely on a frontend permission check alone.
- Perform read-only smoke checks before enabling write flags. Verify health, CSRF/session login, `/api/v1/me/access`, unauthorized tenant denial, employee list and organization read paths.
- Enable writes only for the approved pilot tenant/roles and run synthetic test accounts in the isolated staging tenant. Never write a fake employee into a tenant holding genuine HR data.
- Check HTTP 4xx/5xx anomalies, request correlation IDs, API latency, exception redaction, queue failures and audit trail after deploy.

## Recovery drill (must be rehearsed, not assumed)
1. Record recovery point objective (RPO) and recovery time objective (RTO); name on-call owner.
2. Restore a recent encrypted PostgreSQL backup to a disposable isolated host. Verify counts and critical relational integrity for tenant/company, assignments, leave revisions and audit events.
3. Recover private object store files/versions corresponding to restored DB. Check private PDF download with an authorized QA account; verify direct unauthenticated/object-path access is denied.
4. Validate APP_KEY and encryption key management (do not rotate blindly: encrypted IIN/documents can become unreadable).
5. Run contract/API smoke tests and trace one employee through organization, assignment, leave and document metadata. Record duration and evidence.
6. If recovery fails, stop release and document blocker. Never run a restore test against production.

## Rollback decision
- **Frontend-only regression:** revert frontend to prior known-good immutable deployment, keeping backend on backward-compatible release.
- **Backend regression without incompatible writes:** roll back application artifact, leave additive migrations in place unless a DB owner explicitly approves a safe compensating migration.
- **Incompatible or corrupt writes:** halt affected operations, preserve evidence, notify security/operations owners and use rehearsed point-in-time restore plan. Do not delete or rewrite audit rows to conceal failures.
- Flag-based emergency stop: disable specific write/manager-review flags while evaluating consistency.

## Evidence and handoff
Attach sanitized CI URLs, browser test report, cross-role/tenant negative cases, migration status, restore drill record, storage authorization verification and sign-off in the release ticket. Keep a maintainer-owned inventory of feature flags, owners, service accounts, data retention and on-call procedures. Mark untested items explicitly; never equate green CI with acceptance of production personal data.
