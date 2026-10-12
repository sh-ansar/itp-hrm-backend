<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\EmployeeVisibility;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EmployeeRecordController extends Controller
{
    public function store(
        Request $request,
        string $company,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeCompanyWrite($request, $company);

        $data = $request->validate($this->rules($company));
        $id = (string) Str::uuid();

        try {
            DB::transaction(function () use ($request, $company, $data, $id, $audit): void {
                DB::table('employees')->insert([
                    'id' => $id,
                    'company_id' => $company,
                    'personnel_number' => $data['personnel_number'],
                    'last_name' => $data['last_name'],
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $audit->record($request->user(), $company, HrAuditAction::PersonnelEmployeeCreated, null, [
                    'employee_id' => $id,
                    'revision' => 1,
                ]);
            });
        } catch (QueryException $e) {
            $this->rethrowUniquePersonnelNumber($e);
        }

        return response()->json(['data' => [
            'id' => $id,
            'personnel_number' => $data['personnel_number'],
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'revision' => 1,
        ]], 201);
    }

    public function update(
        Request $request,
        string $company,
        string $employee,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeCompanyWrite($request, $company);
        $visibility->visibleFor($request->user(), $company)
            ->where('employees.id', $employee)
            ->firstOrFail();

        $rules = $this->rules($company, $employee);
        if (array_diff(array_keys($request->all()), array_merge(array_keys($rules), ['revision'])) !== []) {
            throw ValidationException::withMessages(['employee' => 'Unexpected employee fields.']);
        }

        $data = $request->validate(array_merge($rules, [
            'revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ]));
        $expectedRevision = (int) $data['revision'];
        unset($data['revision']);

        try {
            $nextRevision = DB::transaction(function () use (
                $request,
                $company,
                $employee,
                $data,
                $expectedRevision,
                $audit,
            ): int {
                $current = DB::table('employees')
                    ->where('id', $employee)
                    ->where('company_id', $company)
                    ->lockForUpdate()
                    ->first(['revision']);
                abort_if($current === null, 404);

                if ((int) $current->revision !== $expectedRevision) {
                    abort(409, 'The employee record was changed by another user. Reload before editing.');
                }

                $updated = DB::table('employees')
                    ->where('id', $employee)
                    ->where('company_id', $company)
                    ->where('revision', $expectedRevision)
                    ->update([
                        'personnel_number' => $data['personnel_number'],
                        'last_name' => $data['last_name'],
                        'first_name' => $data['first_name'],
                        'middle_name' => $data['middle_name'] ?? null,
                        'revision' => $expectedRevision + 1,
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    abort(409, 'The employee record was changed by another user. Reload before editing.');
                }

                $audit->record($request->user(), $company, HrAuditAction::PersonnelEmployeeUpdated, null, [
                    'employee_id' => $employee,
                    'revision' => $expectedRevision + 1,
                ]);

                return $expectedRevision + 1;
            });
        } catch (QueryException $e) {
            $this->rethrowUniquePersonnelNumber($e);
        }

        return response()->json(['data' => [
            'id' => $employee,
            'personnel_number' => $data['personnel_number'],
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'revision' => $nextRevision,
        ]]);
    }

    private function rules(string $company, ?string $employee = null): array
    {
        $personnelNumber = Rule::unique('employees', 'personnel_number')
            ->where(fn ($query) => $query->where('company_id', $company));
        if ($employee !== null) {
            $personnelNumber->ignore($employee, 'id');
        }

        return [
            'personnel_number' => ['required', 'string', 'max:100', $personnelNumber],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function authorizeCompanyWrite(Request $request, string $company): void
    {
        abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);

        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
    }

    private function rethrowUniquePersonnelNumber(QueryException $e): never
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        if (in_array($sqlState, ['23000', '23505'], true)) {
            throw ValidationException::withMessages([
                'personnel_number' => 'Personnel number must be unique within the company.',
            ]);
        }

        throw $e;
    }
}
