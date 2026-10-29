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

    /**
     * Readable template catalogue for HR and for explicitly enabled team planners.
     * Never grants the manager access to another employee's private data.
     */
    public function canReadPlanningTemplates(User $actor, string $companyId): bool
    {
        if (!config('hrm.worktime_writes_enabled', false)
            || !config('hrm.worktime_planning_enabled', false)) return false;

        $role = DB::table('company_user_roles')->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())->value('role_code');
        return in_array($role, ['company_admin', 'hr_specialist'], true)
            || ($role === 'department_manager' && config('hrm.worktime_manager_planning_enabled', false));
    }

    /**
     * The manager may plan only employees who stay in an explicitly scoped
     * department (or its descendants) throughout the requested future period.
     * Future transfers out of scope are denied, not silently rescheduled.
     */
    public function canPlanEmployees(User $actor, string $companyId, array $employeeIds, string $from, string $to): bool
    {
        if (!config('hrm.worktime_writes_enabled', false)
            || !config('hrm.worktime_planning_enabled', false)) return false;
        if ($this->canEditTimesheet($actor, $companyId)) return true;
        if (!config('hrm.worktime_manager_planning_enabled', false)) return false;

        $role = DB::table('company_user_roles')->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())->value('role_code');
        if ($role !== 'department_manager') return false;

        $scope = DB::table('department_access_scopes')->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())->pluck('department_id')->all();
        if (!$scope || !$employeeIds) return false;

        $tree = DB::table('departments')->where('company_id', $companyId)
            ->get(['id', 'parent_id']);
        $allowed = array_fill_keys($scope, true);
        for ($i = 0; $i < $tree->count() + 1; $i++) {
            $changed = false;
            foreach ($tree as $department) {
                if (isset($allowed[$department->id])
                    || !$department->parent_id || !isset($allowed[$department->parent_id])) continue;
                $allowed[$department->id] = true;
                $changed = true;
            }
            if (!$changed) break;
        }

        $assignments = DB::table('employee_assignments')->whereIn('employee_id', $employeeIds)
            ->where('valid_from', '<=', $to)
            ->where(function ($q) use ($from): void {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from);
            })
            ->get(['employee_id', 'department_id', 'valid_from', 'valid_to'])->groupBy('employee_id');

        foreach ($employeeIds as $employeeId) {
            $rows = $assignments->get($employeeId) ?? collect();
            // Check all day boundaries; this also catches intervals without a
            // valid assignment and overlapping cross-department appointments.
            $day = \Carbon\CarbonImmutable::parse($from);
            $end = \Carbon\CarbonImmutable::parse($to);
            while ($day->lessThanOrEqualTo($end)) {
                $date = $day->toDateString();
                $active = $rows->filter(fn (object $a): bool =>
                    $a->valid_from <= $date && ($a->valid_to === null || $a->valid_to >= $date));
                if ($active->count() !== 1 || !isset($allowed[$active->first()->department_id])) return false;
                $day = $day->addDay();
            }
        }
        return true;
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
