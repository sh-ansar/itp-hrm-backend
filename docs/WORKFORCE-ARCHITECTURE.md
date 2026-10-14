# Workforce planning architecture – Laravel 12 / Vue 3

Hand-off contract for the next maintainer. PostgreSQL + Laravel API own business data. Vercel demo data is synthetic.

## Layer boundaries

- Backend Laravel 12 / PHP 8.4 / PostgreSQL: route -> FormRequest authorization and validation -> thin controller -> domain service -> transaction, revision check and audit.
- Use UUID company and employee IDs. Scope EVERY read/write to company and effective role. Department managers can only read explicitly granted scopes.
- Vue 3 with composition API, Pinia and typed src/api clients. UI must use server capability flags, never trust a client-selected role.
- Feature flags are OFF until staging TLS/Sanctum, role review, backups and QA. Disabled write returns 404.
- No invisible synthetic fallback in production. Incomplete calendars remain visibly incomplete.

## Normalized data model already in the repository

| Table | Business key | Responsibility |
| --- | --- | --- |
| companies | UUID | Tenant boundary, month-write locking |
| company_user_roles | user_id + company_id | Effective company role |
| department_access_scopes | user_id + company_id + department_id | Non-inherited manager read scope |
| employees | UUID + company_id | Person master |
| leave_types | company_id + code unique | Leave policy type, annual entitlement hint |
| employee_leaves | UUID, employee_id, leave_type_id | Planned and actual leave dates, revision |
| production_calendar_days | company_id + work_date unique | Explicit company workday/weekend/holiday/shortened rows |
| work_schedules | company_id + code unique | Shift templates |
| employee_work_schedules | employee_id + validity | Effective-dated work schedule |
| worktime_entries | employee_id + work_date unique | Recorded planned/work/absence/overtime minutes |
| employee_absences | employee_id + dates | Non-attendance events |
| hr_audit_events | append-only ID | Actor, company, event and SAFE business identifiers |

Old migrations are immutable. All new schema changes use additive migrations and rollback plans. Never migrate:fresh outside isolated test databases. No cached balance as the canonical record.

## Implemented: company production calendar write

PUT /api/v1/companies/{company}/worktime/calendar

Request example:
    {"month":"2026-10","days":[{"date":"2026-10-15","day_type":"shortened","planned_minutes":360,"label":"Company short day","revision":null}]}

- Only company_admin with HRM_WORKTIME_WRITES_ENABLED=true.
- Day types: workday, shortened (positive minutes), weekend, holiday (zero/null minutes).
- One batch of 1–31 distinct dates within its month; all-or-nothing transaction.
- Client sends revision null for new day; integer revision for update. Stale or missing revision produces HTTP 409 and NO partial mutation.
- HTTP 422 validation errors; HTTP 403 wrong role/company; HTTP 404 feature disabled.
- Response data: id, date, day_type, planned_minutes, label, next revision. Meta: month, created_count, updated_count.
- GET calendar adds meta.can_edit_calendar, computed from actor+tenant+flag; existing response data stays compatible.
- Every successful write is accompanied by transaction-coupled non-sensitive audit.
- Weekday/weekend 5/2 prefilling is an explicit UI suggestion, NEVER automatic public-holiday inference.

## Next implementation contracts

1. Leave approval state machine must be explicit, not inferred from actual dates. Introduce an additive leave request/decision ledger and preserve employee_leaves as projected plan+actual record. Use controlled draft -> submitted -> approved/rejected -> cancelled transitions with version checks.
2. Leave types and accrual policies are company-owned master data. Yearly entitlement, carry-forward and accrual transactions are a separate auditable ledger; do not store a hand-edited balance as truth.
3. Work schedules are versioned, with start/end validity and overlap protection. Overnight shifts require a documented timezone policy.
4. Timesheet: draft -> submit -> approve -> lock -> adjustment. Approved/locked periods cannot be silently overwritten.
5. Regional public holidays require reviewed data import provenance. Manual overrides may be explicit and audited.
6. Replace demo-only role/permission Vue matrix with backend effective capabilities and approved role definitions; employee self-service must not inherit HR access.

## Acceptance and hand-off

Backend: PHPUnit SQLite and PostgreSQL integration (roles/tenant/validation/revisions/audit); frontend: Playwright 375/768/1440, 4 languages and both themes; API payloads typed and documented. PR and CI evidence is mandatory before merge. Deploy only to an approved HTTPS staging environment after backup and migration review. Avoid editing operational hosts.
