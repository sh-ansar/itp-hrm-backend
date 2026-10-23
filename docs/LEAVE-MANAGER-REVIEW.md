# Staged manager and HR leave review

The additive migration `2026_10_22_000001_add_leave_manager_review.php` records an independent manager recommendation. `HRM_LEAVE_MANAGER_APPROVAL_ENABLED` defaults to **false**. All existing admin-only workflows retain their behavior while it is false. Deploy the migration before code reads the new columns; never modify an old migration.

With both leave writes and staged review enabled:

1. The explicitly linked employee creates, edits and submits through the existing own-data endpoints.
2. `department_manager` reads `/leave-requests` only for employees in their **current active assignment** and explicit `department_access_scopes`. Drafts and cancelled requests are excluded. Type identity/name/category, employee notes, HR comments and private evidence metadata are null in this scheduling projection.
3. `POST /leave-requests/{id}/manager-decision` accepts `{revision, decision: approved|rejected, comment?}`. Rejection requires a comment. Request revision advances once. A positive recommendation leaves status `submitted`; a rejection sets `rejected`. No confirmed leave or balance deduction is created here.
4. A different HR specialist or company administrator makes the final `/decision`. A positive manager recommendation is mandatory. Existing self-subject/self-author, private evidence, overlap, entitlement and revision guards remain enforced. Approval publishes the confirmed period once and the balance service derives booked days from it.

The manager cannot edit, cancel, finalize or access private supporting files. Neither HR nor admin can impersonate the manager endpoint. A manager who later becomes HR cannot finalize a request they reviewed. A missing scoped manager does not cause an automatic approval or fallback; configure department scopes before activation. Rejected requests require a new request; the existing rejected-state behavior is retained.

## Read contract additions

Request fields: `manager_recommendation`, `manager_reviewed_by_user_id`, `manager_reviewed_at`, `manager_decision_comment`. Own workspace exposes recommendation/date/comment for the current employee. Manager writes return only safe scheduling identity/status/revision/recommendation, never the private request serialization.

List metadata: `manager_approval_required`, `can_review_leave_requests`, `can_manage_leave_requests`. Existing `can_approve_leave_requests` includes HR only when staged review is enabled. `leave.requests.review` is a new company capability. Frontend must handle the manager's null type name with a generic translated label. Leave-type creation remains admin-only, independent of final HR decision rights.

Audit actions `leave.request_manager_approved` and `leave.request_manager_rejected` commit in the same transaction as the review; final approval retains `leave.request_approved`. Do not confuse the security audit with a notification system.

## Verification and rollout

`LeaveManagerReviewTest` verifies the three-role HTTP flow, balance unchanged after manager review, final deduction, both audit actions, scope revocation/transfer, cross-company denial, confidentiality, stale/repeated decisions, self-review, forged metadata and legacy mode. Existing workflow, evidence and balance suites remain required. PostgreSQL CI and the paired real-browser E2E must pass before merging or activating this feature.

This change does not implement email/in-app notifications, automatic statutory accrual, approved-leave reversal, payroll, or a general approval engine. Production configuration and data remain untouched.
