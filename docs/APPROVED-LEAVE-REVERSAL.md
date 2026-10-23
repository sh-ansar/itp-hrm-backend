# Future approved leave reversal

`POST /api/v1/companies/{company}/leave-requests/{request}/reverse-approved`

Body: `revision` (integer 1–2147483646), `reason` (trimmed 5–500 characters).
Requires both `HRM_LEAVE_WRITES_ENABLED` and `HRM_APPROVED_LEAVE_REVERSAL_ENABLED`, plus company administrator membership. A linked subject cannot reverse their own leave.

Only future, approved, published bookings with no actual attendance, worktime entries, locked monthly periods or closed entitlement years can be reversed. The company row serializes reversals with the existing approval and balance writers; stale/repeated operations return 409.

The booking is retained with cancellation metadata. The request becomes cancelled with a new revision. Its published booking ID, dates and approval history are retained. The existing audit action `leave.approved_request_reversed` is written in the same transaction; no reason text or employee private values are copied into audit JSON.

Read projections, balances and overlap checks exclude cancelled bookings regardless of feature activation, so disabling the flag cannot resurrect a cancellation. HR/admin request history includes `reversed_at` and `reversal_reason`; manager projections redact both fields.

`GET /leave-requests` exposes `meta.can_reverse_approved_leave`; clients must fail closed when absent. Server remains authoritative for every restriction. UI must refresh schedule and balances after success.

Migration 2026_10_23_000002 is additive. Back up/rehearse before release; do not roll back this schema after cancellations without a reviewed history-preserving recovery plan. Existing statutory and historical attendance correction rules are not inferred by this operation.
