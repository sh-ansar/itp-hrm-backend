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

final class EmployeeProfileRecordController extends Controller
{
    private const TABLES = [
        'experience' => 'employee_experience',
        'reserve' => 'employee_reserve',
        'training' => 'employee_training',
    ];

    public function store(Request $request, string $company, string $employee, string $section, EmployeeVisibility $visibility, HrAuditWriter $audit): JsonResponse
    {
        abort_unless(config('hrm.personnel_writes_enabled', false), 404);
        abort_unless(isset(self::TABLES[$section]), 404);

        $role = DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
        $visibility->visibleFor($request->user(), $company)->where('employees.id', $employee)->firstOrFail();

        $fields = match ($section) {
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
        $data = $request->validate($fields);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $company, $employee, $section, $data, $id, $audit): void {
            DB::table(self::TABLES[$section])->insert(array_merge($data, [
                'id' => $id, 'employee_id' => $employee, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $audit->record($request->user(), $company, HrAuditAction::PersonnelProfileRecordCreated, null, [
                'section' => $section, 'record_id' => $id,
            ]);
        });

        return response()->json(['data' => ['id' => $id, 'section' => $section]], 201);
    }
}
