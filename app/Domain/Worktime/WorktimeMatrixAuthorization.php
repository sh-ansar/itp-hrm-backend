<?php

namespace App\Domain\Worktime;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Worktime grid permissions are distinct from personnel/financial permissions.
 * A manager's multi-day view is limited to employees whose one and only
 * assignment covers the whole range in a delegated department (or child).
 * Employees transferred midrange can be viewed in smaller periods, not leaked.
 */
final class WorktimeMatrixAuthorization
{
    public function role(User $actor, string $company): ?string
    {
        return DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $actor->getAuthIdentifier())->value('role_code');
    }

    public function canRead(User $actor, string $company): bool
    {
        if (!config('hrm.worktime_matrix_enabled', false)) return false;
        return in_array($this->role($actor, $company), [
            'company_admin', 'hr_specialist', 'department_manager',
        ], true);
    }

    public function canWrite(User $actor, string $company): bool
    {
        if (!config('hrm.worktime_matrix_enabled', false)
            || !config('hrm.worktime_writes_enabled', false)) return false;
        // Shift planning for managers is separate from factual attendance input.
        return in_array($this->role($actor, $company), ['company_admin', 'hr_specialist'], true);
    }

    public function visibleEmployees(User $actor, string $company, string $from, string $to): Builder
    {
        abort_unless($this->canRead($actor, $company), 403);
        $query = DB::table('employees as e')->where('e.company_id', $company);
        if ($this->role($actor, $company) !== 'department_manager') return $query;

        $departments = $this->departmentScope($actor, $company);
        if ($departments === []) return $query->whereRaw('1 = 0');

        // Require a single unambiguous assignment covering the entire interval.
        // Fail closed on overlapping assignments, gaps or out-of-team transfers.
        return $query->whereExists(function (Builder $sub) use ($departments, $from, $to): void {
            $sub->selectRaw('1')->from('employee_assignments as team_a')
                ->whereColumn('team_a.employee_id', 'e.id')
                ->whereIn('team_a.department_id', $departments)
                ->where('team_a.valid_from', '<=', $from)
                ->where(function (Builder $q) use ($to): void {
                    $q->whereNull('team_a.valid_to')->orWhere('team_a.valid_to', '>=', $to);
                })
                ->whereNotExists(function (Builder $other) use ($from, $to): void {
                    $other->selectRaw('1')->from('employee_assignments as conflicting_a')
                        ->whereColumn('conflicting_a.employee_id', 'e.id')
                        ->whereColumn('conflicting_a.id', '<>', 'team_a.id')
                        ->where('conflicting_a.valid_from', '<=', $to)
                        ->where(function (Builder $q) use ($from): void {
                            $q->whereNull('conflicting_a.valid_to')
                                ->orWhere('conflicting_a.valid_to', '>=', $from);
                        });
                });
        });
    }

    public function assertWriteEmployees(
        User $actor, string $company, array $employeeIds, string $from, string $to,
    ): void {
        abort_unless($this->canWrite($actor, $company), 403);
        abort_unless(count($employeeIds) === $this->visibleEmployees($actor, $company, $from, $to)
            ->whereIn('e.id', $employeeIds)->count(), 403,
            'One or more employees are outside your delegated time range.');
    }

    private function departmentScope(User $actor, string $company): array
    {
        $roots = DB::table('department_access_scopes')
            ->where('company_id', $company)
            ->where('user_id', $actor->getAuthIdentifier())
            ->pluck('department_id')->all();
        if ($roots === []) return [];
        $allowed = array_fill_keys($roots, true);
        $tree = DB::table('departments')->where('company_id', $company)
            ->get(['id', 'parent_id']);
        for ($pass = 0; $pass <= $tree->count(); $pass++) {
            $changed = false;
            foreach ($tree as $node) {
                if (isset($allowed[$node->id]) || !$node->parent_id
                    || !isset($allowed[$node->parent_id])) continue;
                $allowed[$node->id] = true;
                $changed = true;
            }
            if (!$changed) break;
        }
        return array_keys($allowed);
    }
}
