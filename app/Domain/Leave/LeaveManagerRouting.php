<?php

namespace App\Domain\Leave;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resolves one and only one active reviewer for an employee's current department.
 * No parent-department inheritance and no fallback to unrelated company managers.
 * Used only behind the leave_manager_route_snapshot_enabled rollout gate.
 */
final class LeaveManagerRouting
{
    public function resolve(string $company, string $employee, User $requester): array
    {
        $today = now()->toDateString();
        $assignments = DB::table('employee_assignments as a')
            ->join('employees as e', function ($join) use ($company): void {
                $join->on('e.id', '=', 'a.employee_id')->where('e.company_id', $company);
            })
            ->where('a.employee_id', $employee)
            ->where('a.valid_from', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $today);
            })
            ->limit(2)->get(['a.department_id']);

        if ($assignments->count() !== 1) {
            throw ValidationException::withMessages([
                'manager' => 'Exactly one active department assignment is required before submitting leave.',
            ]);
        }

        $department = (string) $assignments[0]->department_id;
        $reviewers = DB::table('department_access_scopes as scope')
            ->join('company_user_roles as role', function ($join): void {
                $join->on('role.company_id', '=', 'scope.company_id')
                    ->on('role.user_id', '=', 'scope.user_id');
            })
            ->join('users as reviewer', 'reviewer.id', '=', 'scope.user_id')
            ->where('scope.company_id', $company)
            ->where('scope.department_id', $department)
            ->where('role.role_code', 'department_manager')
            ->where('reviewer.account_status', 'active')
            ->distinct()->pluck('reviewer.id');

        // When a department head has been explicitly selected, an approval
        // must reach that same employee's linked, scoped manager account.
        // Unassigned legacy departments retain single-scope behavior.
        $head = DB::table('departments')->where('id', $department)
            ->where('company_id', $company)->value('manager_employee_id');
        if ($head !== null) {
            $linked = DB::table('employee_user_links')->where('company_id', $company)
                ->where('employee_id', $head)->pluck('user_id')->map(fn ($id) => (int) $id);
            $reviewers = $reviewers->filter(fn ($id) => $linked->contains((int) $id))->values();
        }

        if ($reviewers->count() !== 1) {
            throw ValidationException::withMessages([
                'manager' => 'Assign exactly one active manager to this department before submitting leave.',
            ]);
        }

        $reviewerId = (int) $reviewers[0];
        if ($reviewerId === (int) $requester->getAuthIdentifier()
            || DB::table('employee_user_links')->where('company_id', $company)
                ->where('employee_id', $employee)->where('user_id', $reviewerId)->exists()) {
            throw ValidationException::withMessages([
                'manager' => 'The employee cannot be their own leave reviewer.',
            ]);
        }

        return ['user_id' => $reviewerId, 'department_id' => $department];
    }
}
