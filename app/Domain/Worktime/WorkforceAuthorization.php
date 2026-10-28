<?php

namespace App\Domain\Worktime;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Explicit server-side company permissions. Vue must never be the authority.
 *
 * Existing role assignments are scoped to exactly one company. Calendar
 * edits affect everyone, so only a company_admin can make them.
 */
final class WorkforceAuthorization
{
    public function canManageLeaveRequests(User $actor, string $companyId): bool
    {
        return $this->companyRoleIs($actor, $companyId, ['company_admin', 'hr_specialist']);
    }

    public function canApproveLeaveRequests(User $actor, string $companyId): bool
    {
        return $this->companyRoleIs($actor, $companyId, config('hrm.leave_manager_approval_enabled', false)
            ? ['company_admin', 'hr_specialist'] : ['company_admin']);
    }

    public function canReverseApprovedLeave(User $actor, string $companyId): bool
    {
        return config('hrm.approved_leave_reversal_enabled', false)
            && $this->companyRoleIs($actor, $companyId, ['company_admin']);
    }

    public function canManageLeaveTypes(User $actor, string $companyId): bool
    {
        return $this->companyRoleIs($actor, $companyId, ['company_admin']);
    }

    public function canReviewLeaveRequests(User $actor, string $companyId): bool
    {
        return config('hrm.leave_manager_approval_enabled', false)
            && $this->companyRoleIs($actor, $companyId, ['department_manager']);
    }

    /** Protected medical/parental leave details are not a department-wide permission. */
    public function canReadProtectedLeaveDetails(User $actor, string $companyId): bool
    {
        return DB::table('company_user_roles')
            ->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())
            ->whereIn('role_code', ['company_admin', 'hr_specialist'])
            ->exists();
    }

    private function companyRoleIs(User $actor, string $companyId, array $allowed): bool
    {
        if (! config('hrm.leave_writes_enabled', false)) {
            return false;
        }

        return DB::table('company_user_roles')
            ->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())
            ->whereIn('role_code', $allowed)
            ->exists();
    }

    public function canEditTimesheet(User $actor, string $companyId): bool
    {
        if (! config('hrm.worktime_writes_enabled', false)) {
            return false;
        }

        return DB::table('company_user_roles')
            ->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())
            ->whereIn('role_code', ['company_admin', 'hr_specialist'])
            ->exists();
    }

    public function canEditEmployeeTimesheet(User $actor, string $companyId, string $employeeId): bool
    {
        if ($this->canEditTimesheet($actor, $companyId)) return true;
        if (!config('hrm.worktime_writes_enabled', false)
            || !config('hrm.worktime_manager_entry_enabled', false)) return false;

        $role = DB::table('company_user_roles')
            ->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())
            ->value('role_code');
        if ($role !== 'department_manager') return false;

        $today = now()->toDateString();
        return DB::table('employees as employee')
            ->where('employee.company_id', $companyId)
            ->where('employee.id', $employeeId)
            ->whereExists(function ($query) use ($today, $companyId, $actor): void {
                $query->selectRaw('1')
                    ->from('employee_assignments as assignment')
                    ->join('departments as department', 'department.id', '=', 'assignment.department_id')
                    ->join('department_access_scopes as scope', function ($join) use ($companyId, $actor): void {
                        $join->on('scope.department_id', '=', 'assignment.department_id')
                            ->where('scope.company_id', $companyId)
                            ->where('scope.user_id', $actor->getAuthIdentifier());
                    })
                    ->whereColumn('assignment.employee_id', 'employee.id')
                    ->where('department.company_id', $companyId)
                    ->where('assignment.valid_from', '<=', $today)
                    ->where(function ($dates) use ($today): void {
                        $dates->whereNull('assignment.valid_to')->orWhere('assignment.valid_to', '>=', $today);
                    });
            })->exists();
    }

    public function canEditCalendar(User $actor, string $companyId): bool
    {
        if (! config('hrm.worktime_writes_enabled', false)) {
            return false;
        }

        return DB::table('company_user_roles')
            ->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())
            ->where('role_code', 'company_admin')
            ->exists();
    }
}
