<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\AssignmentPeriodPolicy;
use App\Domain\Personnel\EmployeeVisibility;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class EmployeeAssignmentController extends Controller
{
    public function store(
        Request $request,
        string $company,
        string $employee,
        EmployeeVisibility $visibility,
        AssignmentPeriodPolicy $periodPolicy,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company, $employee, $visibility);

        $data = $request->validate([
            'department_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'basis_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $company, $employee, $data, $id, $periodPolicy, $audit): void {
            // Serialize assignment writers for one employee on PostgreSQL. This makes
            // the read-check-insert sequence safe from concurrent overlapping writes.
            $lockedEmployee = DB::table('employees')
                ->where('id', $employee)
                ->where('company_id', $company)
                ->lockForUpdate()
                ->first(['id']);
            abort_if($lockedEmployee === null, 404);

            $departmentExists = DB::table('departments')
                ->where('id', $data['department_id'])
                ->where('company_id', $company)
                ->exists();
            $positionExists = DB::table('positions')
                ->where('id', $data['position_id'])
                ->where('company_id', $company)
                ->exists();

            if (!$departmentExists || !$positionExists) {
                throw ValidationException::withMessages([
                    'assignment' => 'Department and position must belong to the selected company.',
                ]);
            }

            $existing = DB::table('employee_assignments')
                ->where('employee_id', $employee)
                ->orderBy('valid_from')
                ->lockForUpdate()
                ->get(['valid_from', 'valid_to'])
                ->map(static fn ($row) => [
                    'valid_from' => (string) $row->valid_from,
                    'valid_to' => $row->valid_to === null ? null : (string) $row->valid_to,
                ])
                ->all();

            try {
                $periodPolicy->assertNoOverlap(
                    $data['valid_from'],
                    $data['valid_to'] ?? null,
                    $existing,
                );
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['valid_from' => $e->getMessage()]);
            }

            DB::table('employee_assignments')->insert([
                'id' => $id,
                'employee_id' => $employee,
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'basis_reference' => $data['basis_reference'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::PersonnelAssignmentCreated, null, [
                'employee_id' => $employee,
                'assignment_id' => $id,
            ]);
        });

        return response()->json(['data' => [
            'id' => $id,
            'employee_id' => $employee,
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
        ]], 201);
    }

    private function authorizeWrite(
        Request $request,
        string $company,
        string $employee,
        EmployeeVisibility $visibility,
    ): void {
        abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);

        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
        $visibility->visibleFor($request->user(), $company)
            ->where('employees.id', $employee)
            ->firstOrFail();
    }
}
