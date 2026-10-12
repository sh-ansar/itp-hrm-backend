<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\AssignmentPeriodPolicy;
use App\Domain\Personnel\EmployeeVisibility;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
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
        $data = $request->validate($this->rules());
        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $company, $employee, $data, $id, $periodPolicy, $audit): void {
            $this->lockEmployee($company, $employee);
            $this->assertDirectoryOwnership($company, $data['department_id'], $data['position_id']);
            $this->assertNoOverlap($employee, null, $data, $periodPolicy);

            DB::table('employee_assignments')->insert([
                'id' => $id,
                'employee_id' => $employee,
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'basis_reference' => $data['basis_reference'] ?? null,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::PersonnelAssignmentCreated, null, [
                'employee_id' => $employee,
                'assignment_id' => $id,
                'revision' => 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $id,
            'employee_id' => $employee,
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'revision' => 1,
        ]], 201);
    }

    public function update(
        Request $request,
        string $company,
        string $employee,
        string $assignment,
        EmployeeVisibility $visibility,
        AssignmentPeriodPolicy $periodPolicy,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company, $employee, $visibility);

        $rules = $this->rules();
        if (array_diff(array_keys($request->all()), array_merge(array_keys($rules), ['revision'])) !== []) {
            throw ValidationException::withMessages(['assignment' => 'Unexpected assignment fields.']);
        }

        $data = $request->validate(array_merge($rules, [
            'revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ]));
        $expectedRevision = (int) $data['revision'];
        unset($data['revision']);

        $nextRevision = DB::transaction(function () use (
            $request,
            $company,
            $employee,
            $assignment,
            $data,
            $expectedRevision,
            $periodPolicy,
            $audit,
        ): int {
            $this->lockEmployee($company, $employee);

            $existing = DB::table('employee_assignments')
                ->where('id', $assignment)
                ->where('employee_id', $employee)
                ->lockForUpdate()
                ->first(['revision']);
            abort_if($existing === null, 404);

            if ((int) $existing->revision !== $expectedRevision) {
                abort(409, 'The assignment was changed by another user. Reload before editing.');
            }

            $this->assertDirectoryOwnership($company, $data['department_id'], $data['position_id']);
            $this->assertNoOverlap($employee, $assignment, $data, $periodPolicy);

            $count = DB::table('employee_assignments')
                ->where('id', $assignment)
                ->where('employee_id', $employee)
                ->where('revision', $expectedRevision)
                ->update([
                    'department_id' => $data['department_id'],
                    'position_id' => $data['position_id'],
                    'valid_from' => $data['valid_from'],
                    'valid_to' => $data['valid_to'] ?? null,
                    'basis_reference' => $data['basis_reference'] ?? null,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            if ($count !== 1) {
                abort(409, 'The assignment was changed by another user. Reload before editing.');
            }

            $audit->record($request->user(), $company, HrAuditAction::PersonnelAssignmentUpdated, null, [
                'employee_id' => $employee,
                'assignment_id' => $assignment,
                'revision' => $expectedRevision + 1,
            ]);

            return $expectedRevision + 1;
        });

        return response()->json(['data' => [
            'id' => $assignment,
            'employee_id' => $employee,
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'revision' => $nextRevision,
        ]]);
    }

    public function transition(
        Request $request,
        string $company,
        string $employee,
        string $assignment,
        EmployeeVisibility $visibility,
        AssignmentPeriodPolicy $periodPolicy,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company, $employee, $visibility);

        $rules = $this->rules();
        if (array_diff(array_keys($request->all()), array_merge(array_keys($rules), ['revision'])) !== []) {
            throw ValidationException::withMessages(['assignment' => 'Unexpected transition fields.']);
        }

        $data = $request->validate(array_merge($rules, [
            'revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ]));
        $expectedRevision = (int) $data['revision'];
        unset($data['revision']);
        $nextId = (string) Str::uuid();

        $result = DB::transaction(function () use (
            $request,
            $company,
            $employee,
            $assignment,
            $data,
            $expectedRevision,
            $nextId,
            $periodPolicy,
            $audit,
        ): array {
            $this->lockEmployee($company, $employee);

            $current = DB::table('employee_assignments')
                ->where('id', $assignment)
                ->where('employee_id', $employee)
                ->lockForUpdate()
                ->first(['valid_from', 'valid_to', 'revision']);
            abort_if($current === null, 404);

            if ((int) $current->revision !== $expectedRevision) {
                abort(409, 'The assignment was changed by another user. Reload before transitioning.');
            }
            if ($current->valid_to !== null) {
                throw ValidationException::withMessages([
                    'assignment' => 'Only an open assignment can be transitioned.',
                ]);
            }

            $currentStart = CarbonImmutable::createFromFormat('Y-m-d', (string) $current->valid_from);
            $nextStart = CarbonImmutable::createFromFormat('Y-m-d', $data['valid_from']);
            if ($nextStart->lessThanOrEqualTo($currentStart)) {
                throw ValidationException::withMessages([
                    'valid_from' => 'The new assignment must start after the current assignment.',
                ]);
            }

            $this->assertDirectoryOwnership($company, $data['department_id'], $data['position_id']);
            $this->assertNoOverlap($employee, $assignment, $data, $periodPolicy);

            $closedOn = $nextStart->subDay()->toDateString();
            $updated = DB::table('employee_assignments')
                ->where('id', $assignment)
                ->where('employee_id', $employee)
                ->where('revision', $expectedRevision)
                ->update([
                    'valid_to' => $closedOn,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                abort(409, 'The assignment was changed by another user. Reload before transitioning.');
            }

            DB::table('employee_assignments')->insert([
                'id' => $nextId,
                'employee_id' => $employee,
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'basis_reference' => $data['basis_reference'] ?? null,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::PersonnelAssignmentTransitioned, null, [
                'employee_id' => $employee,
                'closed_assignment_id' => $assignment,
                'closed_assignment_revision' => $expectedRevision + 1,
                'new_assignment_id' => $nextId,
            ]);

            return ['closed_on' => $closedOn, 'closed_revision' => $expectedRevision + 1];
        });

        return response()->json(['data' => [
            'closed_assignment' => [
                'id' => $assignment,
                'valid_to' => $result['closed_on'],
                'revision' => $result['closed_revision'],
            ],
            'new_assignment' => [
                'id' => $nextId,
                'employee_id' => $employee,
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'revision' => 1,
            ],
        ]], 201);
    }

    private function rules(): array
    {
        return [
            'department_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'basis_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function lockEmployee(string $company, string $employee): void
    {
        $lockedEmployee = DB::table('employees')
            ->where('id', $employee)
            ->where('company_id', $company)
            ->lockForUpdate()
            ->first(['id']);

        abort_if($lockedEmployee === null, 404);
    }

    private function assertDirectoryOwnership(string $company, string $department, string $position): void
    {
        $departmentExists = DB::table('departments')
            ->where('id', $department)
            ->where('company_id', $company)
            ->exists();
        $positionExists = DB::table('positions')
            ->where('id', $position)
            ->where('company_id', $company)
            ->exists();

        if (!$departmentExists || !$positionExists) {
            throw ValidationException::withMessages([
                'assignment' => 'Department and position must belong to the selected company.',
            ]);
        }
    }

    private function assertNoOverlap(
        string $employee,
        ?string $exceptAssignment,
        array $data,
        AssignmentPeriodPolicy $periodPolicy,
    ): void {
        $existing = DB::table('employee_assignments')
            ->where('employee_id', $employee)
            ->when($exceptAssignment !== null, fn ($query) => $query->where('id', '!=', $exceptAssignment))
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
