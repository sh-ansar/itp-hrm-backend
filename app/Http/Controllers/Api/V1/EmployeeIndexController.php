<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\EmployeeVisibility;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EmployeeIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $company,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        // Authorization must happen before query or audit of a successful read.
        $query = $visibility->visibleFor($request->user(), $company);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $today = now()->toDateString();
        $search = trim((string) ($validated['search'] ?? ''));

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(function (Builder $employeeSearch) use ($pattern): void {
                $employeeSearch
                    ->whereLike('employees.personnel_number', $pattern)
                    ->orWhereLike('employees.last_name', $pattern)
                    ->orWhereLike('employees.first_name', $pattern)
                    ->orWhereLike('employees.middle_name', $pattern);
            });
        }

        // Current assignment is projected through bounded scalar subqueries instead of
        // a join, so malformed legacy overlap cannot duplicate employees in the register.
        $query
            ->selectSub(function (Builder $sub) use ($today, $company): void {
                $sub->from('employee_assignments as current_a')
                    ->join('departments as current_d', 'current_d.id', '=', 'current_a.department_id')
                    ->select('current_d.name')
                    ->whereColumn('current_a.employee_id', 'employees.id')
                    ->where('current_d.company_id', $company)
                    ->where('current_a.valid_from', '<=', $today)
                    ->where(function (Builder $dates) use ($today): void {
                        $dates->whereNull('current_a.valid_to')->orWhere('current_a.valid_to', '>=', $today);
                    })
                    ->orderByDesc('current_a.valid_from')
                    ->orderBy('current_a.id')
                    ->limit(1);
            }, 'current_department_name')
            ->selectSub(function (Builder $sub) use ($today, $company): void {
                $sub->from('employee_assignments as current_a')
                    ->join('positions as current_p', 'current_p.id', '=', 'current_a.position_id')
                    ->select('current_p.name')
                    ->whereColumn('current_a.employee_id', 'employees.id')
                    ->where('current_p.company_id', $company)
                    ->where('current_a.valid_from', '<=', $today)
                    ->where(function (Builder $dates) use ($today): void {
                        $dates->whereNull('current_a.valid_to')->orWhere('current_a.valid_to', '>=', $today);
                    })
                    ->orderByDesc('current_a.valid_from')
                    ->orderBy('current_a.id')
                    ->limit(1);
            }, 'current_position_name')
            ->selectSub(function (Builder $sub) use ($today): void {
                $sub->from('employee_assignments as current_a')
                    ->select('current_a.valid_from')
                    ->whereColumn('current_a.employee_id', 'employees.id')
                    ->where('current_a.valid_from', '<=', $today)
                    ->where(function (Builder $dates) use ($today): void {
                        $dates->whereNull('current_a.valid_to')->orWhere('current_a.valid_to', '>=', $today);
                    })
                    ->orderByDesc('current_a.valid_from')
                    ->orderBy('current_a.id')
                    ->limit(1);
            }, 'current_assignment_valid_from');

        $page = $query
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('employees.id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        // Synchronous, fail-closed audit. Search text and employee identities are
        // intentionally excluded from audit metadata.
        $audit->record(
            $request->user(),
            $company,
            HrAuditAction::EmployeesListed,
            null,
            [
                'current_page' => $page->currentPage(),
                'returned_count' => count($page->items()),
                'search_applied' => $search !== '',
            ],
        );

        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'employee_writes_enabled' => in_array($role, ['company_admin', 'hr_specialist'], true)
                    && (bool) config('hrm.personnel_writes_enabled', false),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
