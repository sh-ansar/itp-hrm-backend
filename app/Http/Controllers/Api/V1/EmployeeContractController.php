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

final class EmployeeContractController extends Controller
{
    public function index(
        Request $request,
        string $company,
        string $employee,
        EmployeeVisibility $visibility,
    ): JsonResponse {
        $this->authorizeExtended($request, $company, $employee, $visibility);

        $rows = DB::table('employee_contracts')
            ->where('company_id', $company)
            ->where('employee_id', $employee)
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get([
                'id', 'contract_number', 'contract_type', 'employment_type',
                'signed_on', 'valid_from', 'valid_to', 'probation_until',
                'basis_reference', 'revision',
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'truncated' => $rows->count() === 100,
                'writes_enabled' => (bool) config('hrm.personnel_writes_enabled', false),
            ],
        ]);
    }

    public function store(
        Request $request,
        string $company,
        string $employee,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company, $employee, $visibility);
        $rules = $this->rules($company);
        $this->rejectUnexpected($request, array_keys($rules));
        $data = $request->validate($rules);
        $this->validateSemantics($data);
        $id = (string) Str::uuid();

        try {
            DB::transaction(function () use ($request, $company, $employee, $id, $data, $audit): void {
                DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
                $this->assertNoOverlap($employee, $data['valid_from'], $data['valid_to'] ?? null);

                DB::table('employee_contracts')->insert([
                    'id' => $id,
                    'company_id' => $company,
                    'employee_id' => $employee,
                    ...$this->fields($data),
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $audit->record($request->user(), $company, HrAuditAction::EmployeeContractCreated, null, [
                    'employee_id' => $employee,
                    'contract_id' => $id,
                    'revision' => 1,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $this->rethrowUniqueNumber($exception);
        }

        return response()->json(['data' => [
            'id' => $id,
            ...$this->fields($data),
            'revision' => 1,
        ]], 201);
    }

    public function update(
        Request $request,
        string $company,
        string $employee,
        string $contract,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company, $employee, $visibility);
        $rules = $this->rules($company, $contract);
        $this->rejectUnexpected($request, array_merge(array_keys($rules), ['revision']));
        $data = $request->validate([
            ...$rules,
            'revision' => ['required', 'integer', 'between:1,2147483646'],
        ]);
        $this->validateSemantics($data);
        $expected = (int) $data['revision'];
        unset($data['revision']);
        $fields = $this->fields($data);

        try {
            $next = DB::transaction(function () use (
                $request, $company, $employee, $contract, $expected, $fields, $audit,
            ): int {
                DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
                $current = DB::table('employee_contracts')
                    ->where('id', $contract)
                    ->where('company_id', $company)
                    ->where('employee_id', $employee)
                    ->lockForUpdate()
                    ->first(['revision']);
                abort_if($current === null, 404);
                abort_unless((int) $current->revision === $expected, 409,
                    'The contract was changed by another user. Reload before editing.');

                $this->assertNoOverlap(
                    $employee,
                    $fields['valid_from'],
                    $fields['valid_to'],
                    $contract,
                );

                $updated = DB::table('employee_contracts')
                    ->where('id', $contract)
                    ->where('company_id', $company)
                    ->where('employee_id', $employee)
                    ->where('revision', $expected)
                    ->update([
                        ...$fields,
                        'revision' => $expected + 1,
                        'updated_at' => now(),
                    ]);
                abort_unless($updated === 1, 409,
                    'The contract was changed by another user. Reload before editing.');

                $audit->record($request->user(), $company, HrAuditAction::EmployeeContractUpdated, null, [
                    'employee_id' => $employee,
                    'contract_id' => $contract,
                    'revision' => $expected + 1,
                ]);

                return $expected + 1;
            }, 3);
        } catch (QueryException $exception) {
            $this->rethrowUniqueNumber($exception);
        }

        return response()->json(['data' => [
            'id' => $contract,
            ...$fields,
            'revision' => $next,
        ]]);
    }

    private function rules(string $company, ?string $contract = null): array
    {
        $number = Rule::unique('employee_contracts', 'contract_number')
            ->where(fn ($query) => $query->where('company_id', $company));
        if ($contract !== null) {
            $number->ignore($contract, 'id');
        }

        return [
            'contract_number' => ['required', 'string', 'max:128', $number],
            'contract_type' => ['required', Rule::in([
                'indefinite', 'fixed_term', 'temporary', 'seasonal', 'other',
            ])],
            'employment_type' => ['present', 'nullable', Rule::in([
                'full_time', 'part_time', 'secondary', 'remote', 'other',
            ])],
            'signed_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'probation_until' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'basis_reference' => ['present', 'nullable', 'string', 'max:255'],
        ];
    }

    private function fields(array $data): array
    {
        return [
            'contract_number' => $data['contract_number'],
            'contract_type' => $data['contract_type'],
            'employment_type' => $data['employment_type'] ?? null,
            'signed_on' => $data['signed_on'] ?? null,
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'probation_until' => $data['probation_until'] ?? null,
            'basis_reference' => $data['basis_reference'] ?? null,
        ];
    }

    private function validateSemantics(array $data): void
    {
        $type = $data['contract_type'];
        $validTo = $data['valid_to'] ?? null;

        if ($type === 'indefinite' && $validTo !== null) {
            throw ValidationException::withMessages([
                'valid_to' => 'An indefinite contract must not have an end date.',
            ]);
        }
        if (in_array($type, ['fixed_term', 'temporary', 'seasonal'], true) && $validTo === null) {
            throw ValidationException::withMessages([
                'valid_to' => 'This contract type requires an end date.',
            ]);
        }
        if (($data['probation_until'] ?? null) !== null
            && $validTo !== null
            && $data['probation_until'] > $validTo) {
            throw ValidationException::withMessages([
                'probation_until' => 'Probation cannot end after the contract.',
            ]);
        }
    }

    private function assertNoOverlap(
        string $employee,
        string $from,
        ?string $to,
        ?string $excluding = null,
    ): void {
        $query = DB::table('employee_contracts')
            ->where('employee_id', $employee)
            ->when($excluding !== null, fn ($q) => $q->where('id', '<>', $excluding))
            ->where(function ($q) use ($to): void {
                if ($to === null) {
                    $q->whereRaw('1 = 1');
                } else {
                    $q->where('valid_from', '<=', $to);
                }
            })
            ->where(function ($q) use ($from): void {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from);
            });

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'valid_from' => 'Employment contract periods must not overlap.',
            ]);
        }
    }

    private function authorizeExtended(
        Request $request,
        string $company,
        string $employee,
        EmployeeVisibility $visibility,
    ): void {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
        $visibility->visibleFor($request->user(), $company)
            ->where('employees.id', $employee)->firstOrFail();
    }

    private function authorizeWrite(
        Request $request,
        string $company,
        string $employee,
        EmployeeVisibility $visibility,
    ): void {
        abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);
        $this->authorizeExtended($request, $company, $employee, $visibility);
    }

    private function rejectUnexpected(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['contract' => 'Unexpected contract fields.']);
        }
    }

    private function rethrowUniqueNumber(QueryException $exception): never
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (in_array($state, ['23000', '23505'], true)) {
            throw ValidationException::withMessages([
                'contract_number' => 'Contract number must be unique within the company.',
            ]);
        }

        throw $exception;
    }
}
