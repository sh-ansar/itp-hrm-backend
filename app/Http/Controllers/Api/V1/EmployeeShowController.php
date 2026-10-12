<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\EmployeeVisibility;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EmployeeShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $company,
        string $employee,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        $row = $visibility->visibleFor($request->user(), $company)
            ->where('employees.id', $employee)
            ->firstOrFail();

        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        $assignments = DB::table('employee_assignments as a')
            ->join('departments as d', 'd.id', '=', 'a.department_id')
            ->join('positions as p', 'p.id', '=', 'a.position_id')
            ->where('a.employee_id', $employee)
            ->where('d.company_id', $company)
            ->where('p.company_id', $company);

        // No lateral access to history of an employee's previously restricted department.
        if ($role === 'department_manager') {
            $assignments->whereIn('a.department_id', DB::table('department_access_scopes')
                ->select('department_id')
                ->where('company_id', $company)
                ->where('user_id', $request->user()->getAuthIdentifier()));
        }

        $history = $assignments
            ->select('a.id', 'd.name as department_name', 'p.name as position_name', 'a.valid_from', 'a.valid_to')
            ->orderByDesc('a.valid_from')
            ->orderBy('a.id')
            ->limit(100)
            ->get();

        $audit->record($request->user(), $company, HrAuditAction::EmployeesListed, null, [
            'detail_view' => true,
            'returned_count' => 1,
        ]);

        return response()->json([
            'data' => $row,
            'assignments' => $history,
            'meta' => ['assignments_limit' => 100, 'assignments_truncated' => $history->count() === 100],
        ]);
    }
}
