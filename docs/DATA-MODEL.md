# HRM data model — actual implemented vs planned

This is a navigation/data-ownership guide to **merged Laravel migrations** as of 15 October 2026, not a generated entity-relationship diagram. For exact FK names, nullability, indexes and defaults consult `database/migrations`. All company/employee IDs are opaque UUIDs; user IDs follow the application's users table. A real production database is NOT provisioned by GitHub Actions.

| Module | Actual tables (implemented) | Canonical ownership |
| --- | --- | --- |
| Organization | `companies`, `departments`, `positions` | Tenant/company, hierarchy and position reference |
| Identity/authorization | `users`, `company_user_roles`, `department_access_scopes` | User account, role **per company**, explicit manager department scope |
| Employee | `employees`, `employee_assignments` | Stable employment identity and effective-dated assignment history |
| Development | `employee_experience`, `employee_reserve`, `employee_training` | Individual history and professional-development records |
| Staffing | `staffing_plans` and related plan/line tables (see migration) | Versioned staffing and approval boundaries |
| Personnel | `personnel_actions`, `personnel_documents` | Auditable hire/transfer/termination and protected document drafts |
| Timekeeping | `work_schedules`, `employee_work_schedules`, `production_calendar_days`, `worktime_entries`, `employee_absences` | Explicit work calendars, effective shift plan and time records |
| Leave | `leave_types`, `employee_leaves`, `leave_requests` | Policies, approved planned/actual leave and request/approval state |
| Private employee one-to-one | `employee_private_profiles`, `employee_military_registration` | Encrypted national identifier, HR-only personal/military details |
| Private employee one-to-many | `employee_family_members`, `employee_identity_documents` | Repeatable relative and identity details; encrypted numbers, soft archive |
| Audit | `hr_audit_events` | Actor/action/company and safe record UUIDs, append-only guards |

### Relational rules

- `employee_private_profiles.employee_id` and `employee_military_registration.employee_id` are primary keys and composite FK-ed with company ID to `employees(id,company_id)`.
- `employee_family_members` and `employee_identity_documents`: many records per employee; `archived_at` hides inactive rows without deleting history; `revision` guards concurrent edits.
- Production PostgreSQL and test SQLite enforce a **partial unique index** so each employee has at most one active primary identity document.
- Identity-document `number_ciphertext` and `personal_number_ciphertext`, and private profile `iin_ciphertext`, use Laravel Crypt; do not query or index plaintext identifiers.
- `leave_requests` uses composite FKs to same-company employee/leave type and one published approved leave ID; approvals are a state machine, not inferred by elapsed time.
- `production_calendar_days` is unique by `(company_id,work_date)`; incomplete months must remain explicitly incomplete.
- Time-effective assignments, approved staffing and employee schedule periods must retain original effective dates. Any overlap and correction policies belong in a reviewed domain service, not arbitrary Vue calculations.
- Source `personnel_documents` PDFs and employee photo bytes are **private storage**, not columns or public paths in `employee_identity_documents`.

### Not yet implemented as canonical data sources

- Accrual/entitlement ledger for leave balances and statutory holiday provenance.
- Fully locked/approved payroll/timesheet ledger and revision/correction workflow.
- Payroll calculations, statutory deductions, payslips and bank payment integration.
- Import batch lineage, external source mappings, employer-specific finance directories and reconciliation.
- Configurable granular custom roles, delegated approvals, employee self-service, consent and retention records.
- Secure scanned file attachment linkage for identity, family, birth certificates and military records.

Future tables should have additive migrations, explicit company ownership, effective dates and indexed access patterns, DB constraints (not UI-only validation), version and archival/audit semantics. Do not quietly map synthetic demo fields to real payroll values.

For module contracts see [architecture](ARCHITECTURE.md), [workforce](WORKFORCE-ARCHITECTURE.md), [leave](LEAVE-WORKFLOW-CONTRACT.md) and [private records](EMPLOYEE-REPEATABLE-PRIVATE-RECORDS.md).
