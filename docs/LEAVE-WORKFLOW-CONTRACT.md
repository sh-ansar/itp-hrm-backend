# Leave planning and approval contract (B69)

## Purpose and lifecycle
The existing employee_leaves table is an **approved schedule / actual-period projection**.
It must not be used to store an unapproved request. The new leave_requests table is
the authoritative approval workflow, with its own company, employee, leave policy,
dates, revision, creator, approver, rationale and *separate* decision comment.

State machine:
    draft --submit--> submitted --approve--> approved
                               \--reject--> rejected
    draft --cancel--> cancelled
    submitted --cancel--> cancelled

Rejected/cancelled/approved requests are not editable. Create a new request to resubmit.
There is no automatic "complete" transition: actual attendance is recorded separately.
An approved leave cannot be cancelled or edited by this API. A formal reversal/
adjustment flow with audit and legal/HR approvals is a future module.

## Database invariants
- leave_requests.company_id UUID, employee_id UUID, leave_type_id UUID;
  tenant-bound composite FKs prevent cross-company employees or leave types.
- Unique request ID, immutable creation actor, revision integer for optimistic locking;
  published_leave_id points to at most one employee_leaves row.
- The existing employee_leaves data is untouched; no destructive migration or backfill.
- Company-row lock serializes decisions and guards overlapping date checks.
- Overlaps with active draft/submitted/approved requests and approved employee_leaves
  are rejected before mutation. All mutations write a safe hr_audit_events event
  inside the same DB transaction. Employee names, comments or PII are never logged.

## Role contracts (all writes flag OFF by default)
- company_admin: add company leave types, create/edit/submit a plan, approve/reject
  plans from a *different* author. Can view company plans.
- hr_specialist: create/edit/submit/cancel draft/pending plans, view company plans;
  never approve/reject.
- department_manager: scoped GET /leaves but no planning/approval.
- employee: no company-level read/write access in this phase; self-service
  employee leave requests require distinct identity mapping and permission model.
- Unknown or cross-company actor -> 403, disabled features -> 404.

Enable only after formal role approval and staging Sanctum/TLS and backup acceptance:
HRM_LEAVE_WRITES_ENABLED=true.

## API v1 – typed fields
- GET /companies/{company}/leave-types
  data [{id, code, name, annual_days, balance_method, revision}], meta.can_manage_leave_types
- POST /companies/{company}/leave-types (company_admin)
  {code, name, annual_days:null|int, balance_method:"calendar_days"|"working_days"}
- GET /companies/{company}/leave-requests?status=draft&page=1 (HR/admin)
  data has id, employee_id, employee names, leave_type_id/name, planned dates,
  planned_days, status, revision, note, decision_comment, created_by_user_id,
  published_leave_id. meta: current_page, last_page, total, can_approve_leave_requests
- POST /companies/{company}/leave-requests (HR/admin)
  {employee_id, leave_type_id, planned_started_on, planned_ended_on, note:null|string}
  Response 201 {data:{id,status:"draft",revision:1,planned_days,...}}
- PUT /companies/{company}/leave-requests/{uuid} (HR/admin, draft only)
  same create fields + revision; complete replacement; 409 on stale/moved state
- POST /companies/{company}/leave-requests/{uuid}/submit
  {revision}
- POST /companies/{company}/leave-requests/{uuid}/decision (admin, not author)
  {revision, decision:"approved"|"rejected", comment:null|string}
  Rejection requires a comment; approval inserts exactly one employee_leaves row.
- POST /companies/{company}/leave-requests/{uuid}/cancel
  {revision}, allowed only for draft/submitted.

HTTP: 201 created, 200 read/mutation, 401 anonymous, 403 forbidden,
404 disabled/nonexistent in tenant, 409 stale or invalid transition,
422 invalid data, duplicate leave period, missing required working calendar.

No client-calculated leave day count is accepted.
- calendar_days => inclusive date span, up to 366 days.
- working_days => count of company calendar workday/shortened days, only if
  the company calendar has an explicit entry for every date in the period;
  otherwise 422 and no writes. Holiday import or legal national exception
  rules are not guessed. Actual leave dates remain empty until confirmed.

## Handover / future slices
1. Define jurisdiction-specific entitlement accrual ledger per company, with
   versioned policies and carry-forward rules; never edit one mutable "balance".
2. Rejection appeal and resubmission lineage; approved leave cancellation requires
   a reversal event and dependent attendance/timesheet corrections.
3. Assign approver by department/supervisor and delegation effective dates;
   current four-eyes approval is only the initial company-admin policy.
4. Employee self-service only after server-authorized employee-user mapping.
5. Expose audit/history in protected UI with company/role and retention review.
6. Integration tests run on SQLite and PostgreSQL, browser workflow tests in a
   dedicated staging environment; enforce contract types in Vue client.

This contract intentionally keeps frontend demo data separate from durable
backend records and does not activate any production write feature flag.
