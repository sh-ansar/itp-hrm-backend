# HR audit integrity — database safeguards

Status: **partial security control**. This is not a tamper-proof audit archive and not production security approval.

## Goal
The `hr_audit_events` table receives append-only events for approved company-role changes and successful employee-list reads. Ordinary application SQL must never rewrite or erase those events.

## Implemented guards
- **PostgreSQL 16:** a `BEFORE UPDATE OR DELETE` row trigger rejects both operations; a separate `BEFORE TRUNCATE` statement trigger prevents bypassing row-level DELETE protection.
- **SQLite in tests:** `BEFORE UPDATE` and `BEFORE DELETE` triggers reject modifications.
- Unsupported database drivers cause the migration to fail rather than running without protection.
- Audit inserts are unaffected. The existing transactional writer continues to abort role mutations when audit insertion fails.
- Migrations install guards *after* the audit table exists. Rolling back the guard migration deliberately removes the guards; migration privilege must be limited to trusted deployment operators.

## Validation
`tests/Feature/HrAuditImmutabilityTest.php` passes on both SQLite and ephemeral PostgreSQL in GitHub Actions (PR #6, run 38045155468; 45 tests each, SQLite 150 and PostgreSQL 149 assertions). Test cases include regular append, changed and no-op UPDATE, row DELETE, bulk DELETE, and PostgreSQL TRUNCATE. A failed SQL statement is isolated in a test savepoint, then the original row is verified intact.

## Limits / operations work still required
- **Database owners and superusers can disable/drop triggers or modify the schema.** The eventual application's runtime DB role must be a non-owner with only essential permissions. Migrations require a separate privileged account. Test CI uses the migration/owner role and is not a production privileges test.
- A compromised database host, backup administrator or privileged deployment credential could still alter records. Independent write-once / externally replicated audit storage, retention periods, review permissions and anomaly alerting have not been implemented.
- Denied sensitive reads, exports, employee changes, documents and payroll actions need complete event coverage before real HR records are loaded.
- An approved retention/archival procedure must not silently purge the audit log; ordinary application endpoints must never expose audit update/delete.
- No changes to existing production databases or personal employee data are involved in this development task.

Related: `docs/SECURITY.md`, `docs/ACCESS-MODEL.md`, Issue #3.
