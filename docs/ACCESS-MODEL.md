# ITP HRM: company and department access — technical implementation

**Status:** technical implementation only, NOT an approved business role matrix. The role-administration API is disabled by default with `HRM_ROLE_MANAGEMENT_ENABLED=false`.

## Effective permissions

| Role code | Current employee list access | May provision roles |
| --- | --- | --- |
| `company_admin` | Minimal employee fields in granted company | May grant/revoke non-admin roles in the same company **only after explicit feature activation** |
| `hr_specialist` | Minimal employee fields in granted company | No |
| `department_manager` | Employees with current assignment in explicitly scoped departments only | No |
| `employee` | No personnel-list access (self-service mapping not built) | No |
| Unknown/no role | Denied | No |

Memberships are unique per user and company. No implicit department hierarchy inheritance. Cross-company membership never provides privileges in another company.

## Guarded management API (off until approved)

- `PUT /api/v1/companies/{company}/memberships/{member}`
  - Body: `{"role_code":"department_manager","department_ids":["uuid", "..."]}`.
  - `role_code` may be `employee`, `hr_specialist`, `department_manager`. **Never** `company_admin`.
  - `department_ids` accepts max 100 distinct same-company UUIDs; non-managers must have empty list.
  - Replaces existing role and scopes atomically; replaying identical requests does not duplicate the audit.
  - Returns only `user_id`, `role_code` and `department_ids`, not email/PII.
- `DELETE /api/v1/companies/{company}/memberships/{member}`
  - Revokes a non-admin membership and dependent scopes atomically.
  - Returns 204 when successful; 404 for unknown member/membership.
- Authentication via `auth:sanctum` (401 anonymous). Actor must already have `company_admin` in that exact company (403 otherwise). Actor cannot change their own membership or any admin membership.
- Flag OFF returns 404 even to an authorized admin. Do not activate until business-role and session/CSRF threat model are approved.
- Initial company-admin creation must be done by a separately controlled, audited offline bootstrap process; no unauthenticated signup or HTTP admin grant is provided.

## Transactional audit

`hr_audit_events` records company, actor, optional target account, action, safe server-built JSON context and timestamp; it never stores salary, employee names or exported records.

- `company.role_granted`, `company.role_changed`, `company.role_revoked`: written **inside the same transaction** as role/scope writes; audit errors abort the mutation.
- `personnel.employees_listed`: synchronous success log after an authorized listing query and before returning 200; only page number and returned count are recorded.
- Denied reads cannot be incorrectly logged as successful reads. Denial audit, exports, sensitive document downloads and writes to employee records are future requirements.
- The audit table is append-only **at application level**. Privileged DB accounts could still modify it; storage immutability, retention policy, protected viewing and external log replication are NOT implemented.

## Security constraints and evidence

- Per-company role and department scopes have database uniqueness/composite foreign-key constraints.
- An administrator may never grant `company_admin`, demote an admin or change their own membership via this API.
- Validation of the requested role and all department IDs precedes any write.
- Target account row locked before granting/revoking, minimizing races for concurrent changes to one target; concurrency/load tests still pending.
- Index endpoint returns only first/last/middle name, personnel number and employee UUID; scoped pagination max 100.
- Automated tests exercise anonymous access, unauthorized roles, other-company attempts, self-escalation, admin protection, invalid scopes, idempotency, revocation, audit and transaction rollback on validation failure, on SQLite and ephemeral PostgreSQL 16.

## Open requirements

1. Approved role/permission matrix, effective-date semantics and department inheritance.
2. Real login/session flow, CSRF protection and separate approval for activating the role-management flag.
3. Controlled bootstrap provisioning and privileged-user lifecycle.
4. Denial/export audit, audit record permissions, retention and DB-level write-once strategy.
5. Concurrent role change stress tests, safe personnel writes, SSO integration and backups.

Do not mark the full role-based security requirement complete before the above are addressed.
