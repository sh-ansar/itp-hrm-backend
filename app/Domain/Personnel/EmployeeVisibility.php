<?php

namespace App\Domain\Personnel;

use App\Domain\Identity\CompanyRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EmployeeVisibility
{
    /**
     * Returns only employees the caller can read. No default or global bypass.
     */
    public function visibleFor(User $user, string $companyId): Builder
    {
        $storedRole = DB::table('company_user_roles')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('company_id', $companyId)
            ->value('role_code');

        $role = is_string($storedRole) ? CompanyRole::tryFrom($storedRole) : null;

        if (!in_array($role, [
            CompanyRole::CompanyAdmin,
            CompanyRole::HrSpecialist,
            CompanyRole::DepartmentManager,
        ], true)) {
            throw new AuthorizationException('No permission to read employees in this company.');
        }

        $query = DB::table('employees')
            ->select([
                'employees.id',
                'employees.personnel_number',
                'employees.last_name',
                'employees.first_name',
                'employees.middle_name',
                'employees.revision',
            ])
            ->where('employees.company_id', $companyId);

        if ($role === CompanyRole::DepartmentManager) {
            $today = now()->toDateString();
            // Parent department grants cover its children, not unrelated teams.
            $scope = DB::table('department_access_scopes')->where('company_id', $companyId)
                ->where('user_id', $user->getAuthIdentifier())->pluck('department_id')->all();
            $allowed = array_fill_keys($scope, true);
            $departments = DB::table('departments')->where('company_id', $companyId)
                ->get(['id', 'parent_id']);
            for ($pass = 0; $pass <= $departments->count(); $pass++) {
                $changed = false;
                foreach ($departments as $department) {
                    if (isset($allowed[$department->id])
                        || !$department->parent_id || !isset($allowed[$department->parent_id])) continue;
                    $allowed[$department->id] = true;
                    $changed = true;
                }
                if (!$changed) break;
            }

            $query->whereExists(function (Builder $sub) use ($companyId, $today, $allowed): void {
                $sub->selectRaw('1')
                    ->from('employee_assignments as a')
                    ->join('departments as d', 'd.id', '=', 'a.department_id')
                    ->whereColumn('a.employee_id', 'employees.id')
                    ->where('d.company_id', $companyId)
                    ->where('a.valid_from', '<=', $today)
                    ->where(function (Builder $dates) use ($today): void {
                        $dates->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $today);
                    })
                    ->whereIn('a.department_id', array_keys($allowed));
            });
        }

        return $query;
    }
}
