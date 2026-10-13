<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\EmployeeVisibility;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EmployeeProfileRecordController extends Controller
{
    private const TABLES = [
        'experience' => 'employee_experience',
        'reserve' => 'employee_reserve',
        'training' => 'employee_training',
    ];

    public function store(Request $request, string $company, string $employee, string $section, EmployeeVisibility $visibility, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company, $employee, $section, $visibility);
        $data = $request->validate($this->rulesFor($section));
        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $company, $employee, $section, $data, $id, $audit): void {
            DB::table(self::TABLES[$section])->insert(array_merge($data, [
                'id' => $id,
                'employee_id' => $employee,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $audit->record($request->user(), $company, HrAuditAction::PersonnelProfileRecordCreated, null, [
                'employee_id' => $employee, 'section' => $section, 'record_id' => $id,
            ]);
        });

        return response()->json(['data' => ['id' => $id, 'section' => $section, 'revision' => 1]], 201);
    }

    public function update(Request $request, string $company, string $employee, string $section, string $record, EmployeeVisibility $visibility, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company, $employee, $section, $visibility);
        $rules = $this->rulesFor($section);

        // PUT replaces one record's editable fields, never its owner, identity or audit history.
        if (array_diff(array_keys($request->all()), array_merge(array_keys($rules), ['revision'])) !== []) {
            throw ValidationException::withMessages(['record' => 'Unexpected profile fields.']);
        }

        $data = $request->validate(array_merge($rules, [
            'revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ]));
        $expected = (int) $data['revision'];
        unset($data['revision']);
        // PUT semantics: omitting an optional field clears it, rather than silently retaining old data.
        $fields = [];
        foreach (array_keys($rules) as $field) {
            $fields[$field] = $data[$field] ?? null;
        }

        $nextRevision = DB::transaction(function () use ($request, $company, $employee, $section, $record, $fields, $expected, $audit): int {
            $table = self::TABLES[$section];
            $existing = DB::table($table)->where('id', $record)
                ->where('employee_id', $employee)->first(['revision']);
            abort_if($existing === null, 404);

            if ((int) $existing->revision !== $expected) {
                abort(409, 'The record was changed by another user. Reload before editing.');
            }

            $count = DB::table($table)->where('id', $record)
                ->where('employee_id', $employee)->where('revision', $expected)
                ->update(array_merge($fields, [
                    'revision' => $expected + 1,
                    'updated_at' => now(),
                ]));
            if ($count !== 1) {
                abort(409, 'The record was changed by another user. Reload before editing.');
            }

            // Both data and its non-sensitive audit event commit together or not at all.
            $audit->record($request->user(), $company, HrAuditAction::PersonnelProfileRecordUpdated, null, [
                'employee_id' => $employee, 'section' => $section, 'record_id' => $record, 'revision' => $expected + 1,
            ]);
            return $expected + 1;
        });

        return response()->json(['data' => [
            'id' => $record, 'section' => $section, 'revision' => $nextRevision,
        ]]);
    }

    private function authorizeWrite(Request $request, string $company, string $employee, string $section, EmployeeVisibility $visibility): void
    {
        abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);
        abort_unless(isset(self::TABLES[$section]), 404);

        $role = DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
        $visibility->visibleFor($request->user(), $company)->where('employees.id', $employee)->firstOrFail();
    }

    private function rulesFor(string $section): array
    {
        return match ($section) {
            'experience' => [
                'experience_type' => ['required', 'string', 'max:80'],
                'started_on' => ['required', 'date_format:Y-m-d'],
                'ended_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:started_on'],
                'basis_reference' => ['nullable', 'string', 'max:255'],
            ],
            'reserve' => [
                'target_position' => ['required', 'string', 'max:255'],
                'reserve_level' => ['required', 'string', 'max:80'],
                'reserve_type' => ['required', 'string', 'max:80'],
                'joined_year' => ['required', 'integer', 'between:1900,2100'],
                'left_year' => ['nullable', 'integer', 'between:1900,2100', 'gte:joined_year'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'training' => [
                'training_type' => ['required', 'string', 'max:80'],
                'training_mode' => ['required', Rule::in(['in_person', 'remote', 'on_the_job'])],
                'topic' => ['required', 'string', 'max:255'],
                'provider' => ['nullable', 'string', 'max:255'],
                'city' => ['nullable', 'string', 'max:255'],
                'started_on' => ['required', 'date_format:Y-m-d'],
                'ended_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:started_on'],
                'document_reference' => ['nullable', 'string', 'max:255'],
            ],
        };
    }
}
