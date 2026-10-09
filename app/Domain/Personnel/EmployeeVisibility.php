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
            ])
            ->where('employees.company_id', $companyId);

        if ($role === CompanyRole::DepartmentManager) {
            $today = now()->toDateString();

            $query->whereExists(function (Builder $sub) use ($user, $companyId, $today): void {
                $sub->selectRaw('1')
                    ->from('employee_assignments as a')
                    ->join('departments as d', 'd.id', '=', 'a.department_id')
                    ->whereColumn('a.employee_id', 'employees.id')
                    ->where('d.company_id', $companyId)
                    ->where('a.valid_from', '<=', $today)
                    ->where(function (Builder $dates) use ($today): void {
                        $dates->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $today);
                    })
                    ->whereIn('a.department_id', function (Builder $scopes) use ($user, $companyId): void {
                        $scopes->select('department_id')
                            ->from('department_access_scopes')
                            ->where('user_id', $user->getAuthIdentifier())
                            ->where('company_id', $companyId);
                    });
            });
        }

        return $query;
    }
}
