<?php

return [
    'workflow_notifications_enabled' => (bool) env('HRM_WORKFLOW_NOTIFICATIONS_ENABLED', false),
    // Explicitly opt-in only after security and business-role approval.
    // Default is fail-closed on every environment, including production.
    'role_management_enabled' => (bool) env('HRM_ROLE_MANAGEMENT_ENABLED', false),

    // This first-party browser login is not released until HTTPS/origin review.
    'personnel_writes_enabled' => (bool) env('HRM_PERSONNEL_WRITES_ENABLED', false),

    'document_writes_enabled' => (bool) env('HRM_DOCUMENT_WRITES_ENABLED', false),
    // Internal order approval (not legal issuance/EDS). Separate rollout gate.
    'order_approvals_enabled' => (bool) env('HRM_ORDER_APPROVALS_ENABLED', false),

    // Uploads always go to the private MinIO disk. Old local blobs are accessible
    // only during an explicitly approved migration window, never by default.
    'legacy_document_reads_enabled' => (bool) env('HRM_LEGACY_DOCUMENT_READS_ENABLED', false),
    'document_storage_migration_enabled' => (bool) env('HRM_DOCUMENT_STORAGE_MIGRATION_ENABLED', false),

    // Worktime mutation endpoints are not released in the first read-only contract.
    'worktime_writes_enabled' => (bool) env('HRM_WORKTIME_WRITES_ENABLED', false),
    // Separate gate for transactional, create-only bulk attendance operations.
    'worktime_bulk_fill_enabled' => (bool) env('HRM_WORKTIME_BULK_FILL_ENABLED', false),
    // Future schedule patterns + year-long day plans. Disabled until isolated QA.
    'worktime_planning_enabled' => (bool) env('HRM_WORKTIME_PLANNING_ENABLED', false),
    // Employee x date matrix, safe cross-month previews. Rollout separately.
    'worktime_matrix_enabled' => (bool) env('HRM_WORKTIME_MATRIX_ENABLED', false),
    // Managers can plan only staff in their active department scope and descendants.
    'worktime_manager_planning_enabled' => (bool) env('HRM_WORKTIME_MANAGER_PLANNING_ENABLED', false),
    // Legacy environment key retained for compatibility; factual attendance is HR/admin-only.
    'worktime_manager_entry_enabled' => (bool) env('HRM_WORKTIME_MANAGER_ENTRY_ENABLED', false),
    // Legacy approval routes stay disabled for direct HR entry (default OFF).
    'worktime_period_approvals_enabled' => (bool) env('HRM_WORKTIME_PERIOD_APPROVALS_ENABLED', false),

    // Leave mutations are not released with the first read-only leave contract.
    'leave_writes_enabled' => (bool) env('HRM_LEAVE_WRITES_ENABLED', false),
    'approved_leave_reversal_enabled' => (bool) env('HRM_APPROVED_LEAVE_REVERSAL_ENABLED', false),

    // Opt-in staged review; legacy approvals remain unchanged until rollout.
    'leave_manager_approval_enabled' => (bool) env('HRM_LEAVE_MANAGER_APPROVAL_ENABLED', false),
    // Opt-in after every department has exactly one authorized reviewer.
    'leave_manager_route_snapshot_enabled' => (bool) env('HRM_LEAVE_MANAGER_ROUTE_SNAPSHOT_ENABLED', false),

    // Separate opt-in for explicit employee-year grant/adjust/carry operations.
    'leave_balance_writes_enabled' => (bool) env('HRM_LEAVE_BALANCE_WRITES_ENABLED', false),

    // Enforce quotas for every ordinary leave approval only after all companies
    // have initialized their employee-year periods and business owners sign off.
    'leave_balance_enforcement_enabled' => (bool) env('HRM_LEAVE_BALANCE_ENFORCEMENT_ENABLED', false),

    // Private supporting PDFs for medical, maternity and childcare leave.
    // Existing requests stay compatible until explicit rollout and policy sign-off.
    'protected_leave_evidence_enabled' => (bool) env('HRM_PROTECTED_LEAVE_EVIDENCE_ENABLED', false),
    'protected_leave_evidence_required' => (bool) env('HRM_PROTECTED_LEAVE_EVIDENCE_REQUIRED', false),
    // Separate medical-document gate: enabling sick leave evidence never changes maternity policy.
    'medical_leave_evidence_required' => (bool) env('HRM_MEDICAL_LEAVE_EVIDENCE_REQUIRED', false),

    // Financially sensitive proposals remain disabled until explicit deployment approval.
    'bonus_writes_enabled' => (bool) env('HRM_BONUS_WRITES_ENABLED', false),

    // Company-scoped reference/catalogue editor; fail closed by default.
    'directory_writes_enabled' => (bool) env('HRM_DIRECTORY_WRITES_ENABLED', false),

    'browser_login_enabled' => (bool) env('HRM_BROWSER_LOGIN_ENABLED', false),

    // Opt-in only after SMTP transport and public reset-link tests succeed.
    'password_reset_enabled' => (bool) env('HRM_PASSWORD_RESET_ENABLED', false),
    'frontend_url' => env('HRM_FRONTEND_URL', 'https://platform.itpmining.kz/hrm'),

    // Use a distinct XSRF cookie when HRM shares a hostname with other apps.
    // Existing independent environments retain the Laravel default.
    'xsrf_cookie_name' => env('HRM_XSRF_COOKIE_NAME', 'XSRF-TOKEN'),

    // Explicit one-time bootstrap; never enabled automatically on production.
    'company_bootstrap_enabled' => (bool) env('HRM_BOOTSTRAP_COMPANIES_ENABLED', false),
    'admin_bootstrap_enabled' => (bool) env('HRM_BOOTSTRAP_ADMIN_ENABLED', false),
];
