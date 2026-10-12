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
            ->select(
                'a.id',
                'a.department_id',
                'a.position_id',
                'd.name as department_name',
                'p.name as position_name',
                'a.valid_from',
                'a.valid_to',
                'a.basis_reference',
                'a.revision',
            )
            ->orderByDesc('a.valid_from')
            ->orderBy('a.id')
            ->limit(100)
            ->get();

        $extended = in_array($role, ['company_admin', 'hr_specialist'], true);
        $personnelWritesEnabled = $extended && (bool) config('hrm.personnel_writes_enabled', false);

        // A manager may view the basic card but not protected personnel development history.
        $experience = $extended ? DB::table('employee_experience')->where('employee_id', $employee)
            ->select('id','experience_type','started_on','ended_on','basis_reference','revision')
            ->orderByDesc('started_on')->limit(100)->get() : collect();
        $reserve = $extended ? DB::table('employee_reserve')->where('employee_id', $employee)
            ->select('id','target_position','reserve_level','reserve_type','joined_year','left_year','notes','revision')
            ->orderByDesc('joined_year')->limit(100)->get() : collect();
        $training = $extended ? DB::table('employee_training')->where('employee_id', $employee)
            ->select('id','training_type','training_mode','topic','provider','city','started_on','ended_on','document_reference','revision')
            ->orderByDesc('started_on')->limit(100)->get() : collect();

        $audit->record($request->user(), $company, HrAuditAction::EmployeesListed, null, [
            'detail_view' => true,
            'returned_count' => 1,
        ]);

        return response()->json([
            'data' => $row,
            'assignments' => $history,
            'experience' => $experience,
            'reserve' => $reserve,
            'training' => $training,
            'meta' => [
                'assignments_limit' => 100,
                'assignments_truncated' => $history->count() === 100,
                'extended_sections_visible' => $extended,
                'profile_writes_enabled' => $personnelWritesEnabled,
                'assignment_writes_enabled' => $personnelWritesEnabled,
            ],
        ]);
    }
}
