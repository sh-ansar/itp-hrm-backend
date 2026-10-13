<?php

return [
    // Explicitly opt-in only after security and business-role approval.
    // Default is fail-closed on every environment, including production.
    'role_management_enabled' => (bool) env('HRM_ROLE_MANAGEMENT_ENABLED', false),

    // This first-party browser login is not released until HTTPS/origin review.
    'personnel_writes_enabled' => (bool) env('HRM_PERSONNEL_WRITES_ENABLED', false),

    'document_writes_enabled' => (bool) env('HRM_DOCUMENT_WRITES_ENABLED', false),

    // Worktime mutation endpoints are not released in the first read-only contract.
    'worktime_writes_enabled' => (bool) env('HRM_WORKTIME_WRITES_ENABLED', false),

    'browser_login_enabled' => (bool) env('HRM_BROWSER_LOGIN_ENABLED', false),
];
