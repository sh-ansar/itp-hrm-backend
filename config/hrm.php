<?php

return [
    // Explicitly opt-in only after security and business-role approval.
    // Default is fail-closed on every environment, including production.
    'role_management_enabled' => (bool) env('HRM_ROLE_MANAGEMENT_ENABLED', false),
];
