# HR data security baseline

## Repository and test data
Both repositories contain only source code and synthetic test fixtures. Repositories should be private before importing any non-synthetic data or migration samples. Never commit .env, secrets, encryption keys, real employee records, payroll data, identity documents or unredacted legacy exports.

## Identity and authorization
- API authentication is currently `auth:sanctum`; a complete production login/session/CSRF deployment design has not yet been implemented.
- Company and department permissions deny by default. Scope must be verified in queries, not inferred from a request-supplied company ID.
- Provisional role-management endpoints are disabled by default (`HRM_ROLE_MANAGEMENT_ENABLED=false`). Only company admins can manage non-admin memberships if enabled; self changes and admin grants are blocked.
- Private document upload/download permissions, special-category fields (health, identity documents, payroll), and company-scoped export controls remain future features.

## Audit
- Role grant/change/revoke audit writes are in the same DB transaction as membership changes.
- Successful personnel list reads are synchronously audited with minimal metadata. Failed/denied reads are not falsely recorded as successful.
- Database admins can still edit `hr_audit_events`: audit record permissions, independent immutable log retention, denial and export auditing must be built before sensitive data is introduced.
- Retention and access to audit entries must be specified with the data controller.

## Release gates
- No production rollout until approved role matrix, secure HTTPS cookies/CSRF, least-privilege database accounts, secrets management, backup/restore, data retention and independent security review.
- SQLite tests provide fast feedback; all migrations and access decisions also run on ephemeral PostgreSQL 16 in CI.
- Existing development or production PostgreSQL services are not used by GitHub CI.
