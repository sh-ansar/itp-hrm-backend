<?php

return [
    // Explicitly opt-in only after security and business-role approval.
    // Default is fail-closed on every environment, including production.
    'role_management_enabled' => (bool) env('HRM_ROLE_MANAGEMENT_ENABLED', false),

    // This first-party browser login is not released until HTTPS/origin review.
    'browser_login_enabled' => (bool) env('HRM_BROWSER_LOGIN_ENABLED', false),
];
