<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\AssignmentPeriodPolicy;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PersonnelActionController extends Controller
{
    public function index(Request $request, string $company): JsonResponse
    {
        $this->authorizeRead($request, $company);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::in(['hire', 'transfer', 'termination'])],
            'employee_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $query = DB::table('personnel_actions as pa')
            ->join('employees as e', 'e.id', '=', 'pa.employee_id')
            ->leftJoin('employee_assignments as sa', 'sa.id', '=', 'pa.source_assignment_id')
            ->leftJoin('departments as sd', 'sd.id', '=', 'sa.department_id')
            ->leftJoin('positions as sp', 'sp.id', '=', 'sa.position_id')
            ->leftJoin('employee_assignments as ta', 'ta.id', '=', 'pa.target_assignment_id')
            ->leftJoin('departments as td', 'td.id', '=', 'ta.department_id')
            ->leftJoin('positions as tp', 'tp.id', '=', 'ta.position_id')
            ->where('pa.company_id', $company)
            ->where('e.company_id', $company)
            ->select(
                'pa.id',
                'pa.employee_id',
                'pa.action_type',
                'pa.effective_on',
                'pa.basis_reference',
                'pa.notes',
                'pa.source_assignment_id',
                'pa.target_assignment_id',
                'e.personnel_number',
                'e.last_name',
                'e.first_name',
                'e.middle_name',
                'sd.name as source_department_name',
                'sp.name as source_position_name',
                'td.name as target_department_name',
                'tp.name as target_position_name',
                'pa.created_at',
            );

        if (!empty($validated['type'])) {
            $query->where('pa.action_type', $validated['type']);
        }
        if (!empty($validated['employee_id'])) {
            $query->where('pa.employee_id', $validated['employee_id']);
        }
        if (!empty($validated['from'])) {
            $query->where('pa.effective_on', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $query->where('pa.effective_on', '<=', $validated['to']);
        }

        $page = $query
            ->orderByDesc('pa.effective_on')
            ->orderByDesc('pa.created_at')
            ->orderBy('pa.id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'personnel_actions_writes_enabled' => $this->canWrite($request, $company),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function hire(
        Request $request,
        string $company,
        string $employee,
        AssignmentPeriodPolicy $periodPolicy,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company, $employee);
        $this->rejectUnexpected($request, [
            'department_id', 'position_id', 'effective_on', 'basis_reference', 'notes',
        ]);

        $data = $request->validate([
            'department_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'basis_reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $assignmentId = (string) Str::uuid();
        $actionId = (string) Str::uuid();

        DB::transaction(function () use (
            $request,
            $company,
            $employee,
            $data,
            $assignmentId,
            $actionId,
            $periodPolicy,
            $audit,
        ): void {
            $this->lockEmployee($company, $employee);
            $this->assertDirectoryOwnership($company, $data['department_id'], $data['position_id']);
            $this->assertNoOverlap($employee, null, [
                'valid_from' => $data['effective_on'],
                'valid_to' => null,
            ], $periodPolicy);

            DB::table('employee_assignments')->insert([
                'id' => $assignmentId,
                'employee_id' => $employee,
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'],
                'valid_from' => $data['effective_on'],
                'valid_to' => null,
                'basis_reference' => $data['basis_reference'],
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('personnel_actions')->insert([
                'id' => $actionId,
                'company_id' => $company,
                'employee_id' => $employee,
                'action_type' => 'hire',
                'effective_on' => $data['effective_on'],
                'basis_reference' => $data['basis_reference'],
                'notes' => $data['notes'] ?? null,
                'source_assignment_id' => null,
                'target_assignment_id' => $assignmentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::PersonnelActionCompleted, null, [
                'personnel_action_id' => $actionId,
                'personnel_action_type' => 'hire',
                'employee_id' => $employee,
                'target_assignment_id' => $assignmentId,
            ]);
        });

        return response()->json(['data' => [
            'id' => $actionId,
            'action_type' => 'hire',
            'employee_id' => $employee,
            'effective_on' => $data['effective_on'],
            'target_assignment_id' => $assignmentId,
        ]], 201);
    }

    public function transfer(
        Request $request,
        string $company,
        string $employee,
        AssignmentPeriodPolicy $periodPolicy,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company, $employee);
        $this->rejectUnexpected($request, [
            'source_assignment_id', 'source_assignment_revision', 'department_id', 'position_id',
            'effective_on', 'basis_reference', 'notes',
        ]);

        $data = $request->validate([
            'source_assignment_id' => ['required', 'uuid'],
            'source_assignment_revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'department_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'basis_reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $targetAssignmentId = (string) Str::uuid();
        $actionId = (string) Str::uuid();

        $result = DB::transaction(function () use (
            $request,
            $company,
            $employee,
            $data,
            $targetAssignmentId,
            $actionId,
            $periodPolicy,
            $audit,
        ): array {
            $this->lockEmployee($company, $employee);

            $source = DB::table('employee_assignments')
                ->where('id', $data['source_assignment_id'])
                ->where('employee_id', $employee)
                ->lockForUpdate()
                ->first(['valid_from', 'valid_to', 'revision']);
            abort_if($source === null, 404);

            if ((int) $source->revision !== (int) $data['source_assignment_revision']) {
                abort(409, 'The source assignment was changed by another user. Reload before transfer.');
            }
            if ($source->valid_to !== null) {
                throw ValidationException::withMessages([
                    'source_assignment_id' => 'Only an open assignment can be transferred.',
                ]);
            }

            $sourceStart = CarbonImmutable::createFromFormat('Y-m-d', (string) $source->valid_from);
            $targetStart = CarbonImmutable::createFromFormat('Y-m-d', $data['effective_on']);
            if ($targetStart->lessThanOrEqualTo($sourceStart)) {
                throw ValidationException::withMessages([
                    'effective_on' => 'Transfer date must be after the current assignment start date.',
                ]);
            }

            $this->assertDirectoryOwnership($company, $data['department_id'], $data['position_id']);
            $this->assertNoOverlap($employee, $data['source_assignment_id'], [
                'valid_from' => $data['effective_on'],
                'valid_to' => null,
            ], $periodPolicy);

            $closedOn = $targetStart->subDay()->toDateString();
            $updated = DB::table('employee_assignments')
                ->where('id', $data['source_assignment_id'])
                ->where('employee_id', $employee)
                ->where('revision', $data['source_assignment_revision'])
                ->update([
                    'valid_to' => $closedOn,
                    'revision' => (int) $data['source_assignment_revision'] + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                abort(409, 'The source assignment was changed by another user. Reload before transfer.');
            }

            DB::table('employee_assignments')->insert([
                'id' => $targetAssignmentId,
                'employee_id' => $employee,
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'],
                'valid_from' => $data['effective_on'],
                'valid_to' => null,
                'basis_reference' => $data['basis_reference'],
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('personnel_actions')->insert([
                'id' => $actionId,
                'company_id' => $company,
                'employee_id' => $employee,
                'action_type' => 'transfer',
                'effective_on' => $data['effective_on'],
                'basis_reference' => $data['basis_reference'],
                'notes' => $data['notes'] ?? null,
                'source_assignment_id' => $data['source_assignment_id'],
                'target_assignment_id' => $targetAssignmentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::PersonnelActionCompleted, null, [
                'personnel_action_id' => $actionId,
                'personnel_action_type' => 'transfer',
                'employee_id' => $employee,
                'source_assignment_id' => $data['source_assignment_id'],
                'target_assignment_id' => $targetAssignmentId,
            ]);

            return ['closed_on' => $closedOn];
        });

        return response()->json(['data' => [
            'id' => $actionId,
            'action_type' => 'transfer',
            'employee_id' => $employee,
            'effective_on' => $data['effective_on'],
            'source_assignment_id' => $data['source_assignment_id'],
            'source_assignment_closed_on' => $result['closed_on'],
            'target_assignment_id' => $targetAssignmentId,
        ]], 201);
    }

    public function terminate(
        Request $request,
        string $company,
        string $employee,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company, $employee);
        $this->rejectUnexpected($request, [
            'source_assignment_id', 'source_assignment_revision', 'effective_on', 'basis_reference', 'notes',
        ]);

        $data = $request->validate([
            'source_assignment_id' => ['required', 'uuid'],
            'source_assignment_revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'basis_reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $actionId = (string) Str::uuid();

        DB::transaction(function () use ($request, $company, $employee, $data, $actionId, $audit): void {
            $this->lockEmployee($company, $employee);

            $source = DB::table('employee_assignments')
                ->where('id', $data['source_assignment_id'])
                ->where('employee_id', $employee)
                ->lockForUpdate()
                ->first(['valid_from', 'valid_to', 'revision']);
            abort_if($source === null, 404);

            if ((int) $source->revision !== (int) $data['source_assignment_revision']) {
                abort(409, 'The source assignment was changed by another user. Reload before termination.');
            }
            if ($source->valid_to !== null) {
                throw ValidationException::withMessages([
                    'source_assignment_id' => 'Only an open assignment can be terminated.',
                ]);
            }
            if ($data['effective_on'] < (string) $source->valid_from) {
                throw ValidationException::withMessages([
                    'effective_on' => 'Termination date cannot be before the current assignment starts.',
                ]);
            }

            $updated = DB::table('employee_assignments')
                ->where('id', $data['source_assignment_id'])
                ->where('employee_id', $employee)
                ->where('revision', $data['source_assignment_revision'])
                ->update([
                    'valid_to' => $data['effective_on'],
                    'revision' => (int) $data['source_assignment_revision'] + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                abort(409, 'The source assignment was changed by another user. Reload before termination.');
            }

            DB::table('personnel_actions')->insert([
                'id' => $actionId,
                'company_id' => $company,
                'employee_id' => $employee,
                'action_type' => 'termination',
                'effective_on' => $data['effective_on'],
                'basis_reference' => $data['basis_reference'],
                'notes' => $data['notes'] ?? null,
                'source_assignment_id' => $data['source_assignment_id'],
                'target_assignment_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::PersonnelActionCompleted, null, [
                'personnel_action_id' => $actionId,
                'personnel_action_type' => 'termination',
                'employee_id' => $employee,
                'source_assignment_id' => $data['source_assignment_id'],
            ]);
        });

        return response()->json(['data' => [
            'id' => $actionId,
            'action_type' => 'termination',
            'employee_id' => $employee,
            'effective_on' => $data['effective_on'],
            'source_assignment_id' => $data['source_assignment_id'],
        ]], 201);
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
            throw ValidationException::withMessages(['effective_on' => $e->getMessage()]);
        }
    }

    private function assertDirectoryOwnership(string $company, string $department, string $position): void
    {
        $departmentExists = DB::table('departments')->where('id', $department)->where('company_id', $company)->exists();
        $positionExists = DB::table('positions')->where('id', $position)->where('company_id', $company)->exists();

        if (!$departmentExists || !$positionExists) {
            throw ValidationException::withMessages([
                'directory' => 'Department and position must belong to the selected company.',
            ]);
        }
    }

    private function lockEmployee(string $company, string $employee): void
    {
        $row = DB::table('employees')
            ->where('id', $employee)
            ->where('company_id', $company)
            ->lockForUpdate()
            ->first(['id']);
        abort_if($row === null, 404);
    }

    private function authorizeRead(Request $request, string $company): void
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
    }

    private function authorizeWrite(Request $request, string $company, string $employee): void
    {
        abort_unless($this->canWrite($request, $company), 404);

        $exists = DB::table('employees')
            ->where('id', $employee)
            ->where('company_id', $company)
            ->exists();
        abort_unless($exists, 404);
    }

    private function canWrite(Request $request, string $company): bool
    {
        if (!(bool) config('hrm.personnel_writes_enabled', false)) {
            return false;
        }

        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        return in_array($role, ['company_admin', 'hr_specialist'], true);
    }

    private function rejectUnexpected(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected personnel action fields.']);
        }
    }
}
