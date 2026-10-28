<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DepartmentRecordController extends Controller
{
    public function store(Request $request, string $company, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['name', 'parent_id', 'manager_employee_id']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid'],
            'manager_employee_id' => ['nullable', 'uuid'],
        ]);

        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $company, $data, $id, $audit): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $this->assertParentBelongsToCompany($company, $data['parent_id'] ?? null);
            $this->assertActiveManagerBelongsToCompany($company, $data['manager_employee_id'] ?? null);

            DB::table('departments')->insert([
                'id' => $id,
                'company_id' => $company,
                'parent_id' => $data['parent_id'] ?? null,
                'manager_employee_id' => $data['manager_employee_id'] ?? null,
                'name' => $data['name'],
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::DepartmentCreated, null, [
                'department_id' => $id,
                'revision' => 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $id,
            'parent_id' => $data['parent_id'] ?? null,
            'manager_employee_id' => $data['manager_employee_id'] ?? null,
            'name' => $data['name'],
            'revision' => 1,
        ]], 201);
    }

    public function update(
        Request $request,
        string $company,
        string $department,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['name', 'parent_id', 'manager_employee_id', 'revision']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid'],
            'manager_employee_id' => ['sometimes', 'nullable', 'uuid'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        $expectedRevision = (int) $data['revision'];

        $manager = null;
        DB::transaction(function () use (
            $request,
            $company,
            $department,
            $data,
            $expectedRevision,
            $audit,
            &$manager,
        ): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();

            $current = DB::table('departments')
                ->where('id', $department)
                ->where('company_id', $company)
                ->lockForUpdate()
                ->first(['revision', 'manager_employee_id']);
            abort_if($current === null, 404);

            if ((int) $current->revision !== $expectedRevision) {
                abort(409, 'The department was changed by another user. Reload before editing.');
            }

            $parent = $data['parent_id'] ?? null;
            if ($parent === $department) {
                throw ValidationException::withMessages(['parent_id' => 'A department cannot be its own parent.']);
            }

            $this->assertParentBelongsToCompany($company, $parent);
            $this->assertNoCycle($company, $department, $parent);
            $manager = array_key_exists('manager_employee_id', $data)
                ? $data['manager_employee_id'] : $current->manager_employee_id;
            if (array_key_exists('manager_employee_id', $data)) {
                $this->assertActiveManagerBelongsToCompany($company, $manager);
            }

            $updated = DB::table('departments')
                ->where('id', $department)
                ->where('company_id', $company)
                ->where('revision', $expectedRevision)
                ->update([
                    'name' => $data['name'],
                    'parent_id' => $parent,
                    'manager_employee_id' => $manager,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                abort(409, 'The department was changed by another user. Reload before editing.');
            }

            $audit->record($request->user(), $company, HrAuditAction::DepartmentUpdated, null, [
                'department_id' => $department,
                'manager_employee_id_before' => $current->manager_employee_id,
                'manager_employee_id_after' => $manager,
                'revision' => $expectedRevision + 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $department,
            'parent_id' => $data['parent_id'] ?? null,
            'manager_employee_id' => $manager,
            'name' => $data['name'],
            'revision' => $expectedRevision + 1,
        ]]);
    }

    public function destroy(
        Request $request,
        string $company,
        string $department,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['revision']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        $expectedRevision = (int) $data['revision'];

        DB::transaction(function () use (
            $request,
            $company,
            $department,
            $expectedRevision,
            $audit,
        ): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();

            $current = DB::table('departments')
                ->where('id', $department)
                ->where('company_id', $company)
                ->lockForUpdate()
                ->first(['revision']);
            abort_if($current === null, 404);

            if ((int) $current->revision !== $expectedRevision) {
                abort(409, 'The department was changed by another user. Reload before deleting.');
            }

            if (DB::table('departments')->where('parent_id', $department)->exists()) {
                throw ValidationException::withMessages(['department' => 'Move or remove child departments first.']);
            }
            if (DB::table('employee_assignments')->where('department_id', $department)->exists()) {
                throw ValidationException::withMessages(['department' => 'Department is used in employee assignment history.']);
            }
            if (DB::table('staffing_plan_lines')->where('department_id', $department)->exists()) {
                throw ValidationException::withMessages(['department' => 'Department is used in staffing plans.']);
            }

            $deleted = DB::table('departments')
                ->where('id', $department)
                ->where('company_id', $company)
                ->where('revision', $expectedRevision)
                ->delete();

            if ($deleted !== 1) {
                abort(409, 'The department was changed by another user. Reload before deleting.');
            }

            $audit->record($request->user(), $company, HrAuditAction::DepartmentDeleted, null, [
                'department_id' => $department,
                'revision' => $expectedRevision,
            ]);
        });

        return response()->json(['data' => ['id' => $department]]);
    }

    private function assertActiveManagerBelongsToCompany(string $company, ?string $employee): void
    {
        if ($employee === null) return;
        $today = now()->toDateString();
        $active = DB::table('employees as e')
            ->where('e.id', $employee)->where('e.company_id', $company)
            ->whereExists(function ($query) use ($today): void {
                $query->selectRaw('1')->from('employee_assignments as assignment')
                    ->whereColumn('assignment.employee_id', 'e.id')
                    ->where('assignment.valid_from', '<=', $today)
                    ->where(function ($dates) use ($today): void {
                        $dates->whereNull('assignment.valid_to')
                            ->orWhere('assignment.valid_to', '>=', $today);
                    });
            })->exists();
        if (!$active) {
            throw ValidationException::withMessages([
                'manager_employee_id' => 'Select an active employee of this company as the department manager.',
            ]);
        }
    }

    private function assertNoCycle(string $company, string $department, ?string $parent): void
    {
        $cursor = $parent;
        $visited = [];

        while ($cursor !== null) {
            if ($cursor === $department) {
                throw ValidationException::withMessages(['parent_id' => 'Department hierarchy cannot contain a cycle.']);
            }
            if (isset($visited[$cursor])) {
                throw ValidationException::withMessages(['parent_id' => 'Existing hierarchy already contains a cycle.']);
            }
            $visited[$cursor] = true;

            $row = DB::table('departments')
                ->where('id', $cursor)
                ->where('company_id', $company)
                ->first(['parent_id']);
            if ($row === null) {
                throw ValidationException::withMessages(['parent_id' => 'Parent department must belong to the selected company.']);
            }
            $cursor = $row->parent_id;
        }
    }

    private function assertParentBelongsToCompany(string $company, ?string $parent): void
    {
        if ($parent === null) {
            return;
        }

        if (!DB::table('departments')->where('id', $parent)->where('company_id', $company)->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Parent department must belong to the selected company.']);
        }
    }

    private function rejectUnexpected(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected department fields.']);
        }
    }

    private function authorizeWrite(Request $request, string $company): void
    {
        abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);

        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
    }
}
