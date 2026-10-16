<?php

namespace App\Domain\Identity;

/**
 * Non-authoritative *UI projection* of Laravel's current server access policy.
 *
 * Never use this class as the only guard for a write. Mutating controllers
 * still validate company membership and feature flags themselves.
 *
 * Keep this projection in sync with authorization exercised by:
 * EmployeeVisibility, StaffingPlanController, PersonnelActionController,
 * PersonnelDocumentController, LeaveReadController, WorktimeReadController.
 */
final class CompanyCapabilities
{
    /** @return array<string, bool> */
    public function forRole(CompanyRole $role): array
    {
        $admin = $role === CompanyRole::CompanyAdmin;
        $hr = $role === CompanyRole::HrSpecialist;
        $manager = $role === CompanyRole::DepartmentManager;
        $staff = $admin || $hr;
        $limitedStaff = $staff || $manager;

        return [
            // Only routes with confirmed backend read contracts are visible
            // in connected mode. The reports UI remains demo-only.
            'overview.view' => $limitedStaff,
            'employees.view' => $limitedStaff,
            'employees.extended_view' => $staff,
            'organization.view' => $limitedStaff,
            'staffing.view' => $staff,
            'personnel_actions.view' => $staff,
            'worktime.view' => $limitedStaff,
            'leaves.view' => $limitedStaff,
            'documents.view' => $staff,
            'directories.view' => $staff,
            'development.view' => $staff,
            'reports.view' => false,
            'settings.view' => true,
            // Role matrix is still prototype-only; no production UI grant.
            'roles.interface' => $admin && (bool) config('hrm.role_management_enabled', false),

            // Server-side write contracts; flags default OFF. Read these to
            // show relevant UI, but ALWAYS re-authorize the request on backend.
            'employees.write' => $staff && (bool) config('hrm.personnel_writes_enabled', false),
            'staffing.write' => $staff && (bool) config('hrm.personnel_writes_enabled', false),
            'documents.write' => $staff && (bool) config('hrm.document_writes_enabled', false),
            'worktime.calendar.edit' => $admin && (bool) config('hrm.worktime_writes_enabled', false),
            'leave.requests.manage' => $staff && (bool) config('hrm.leave_writes_enabled', false),
            'leave.requests.approve' => $admin && (bool) config('hrm.leave_writes_enabled', false),
            'leave.types.manage' => $admin && (bool) config('hrm.leave_writes_enabled', false),
            'roles.assign' => $admin && (bool) config('hrm.role_management_enabled', false),
        ];
    }
}
