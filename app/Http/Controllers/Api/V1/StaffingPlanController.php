<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffingPlanController extends Controller
{
    public function index(Request $request, string $company): JsonResponse
    {
        $this->authorizeRead($request, $company);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = DB::table('staffing_plans')
            ->where('company_id', $company)
            ->select('id', 'name', 'valid_from', 'valid_to', 'status', 'revision')
            ->orderByDesc('valid_from')
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'staffing_writes_enabled' => $this->canWrite($request, $company),
                'clone_supported' => true,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $company, string $plan): JsonResponse
    {
        $this->authorizeRead($request, $company);

        $validated = $request->validate([
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $asOf = $validated['as_of'] ?? now()->toDateString();

        $header = DB::table('staffing_plans')
            ->where('id', $plan)
            ->where('company_id', $company)
            ->first(['id', 'name', 'valid_from', 'valid_to', 'status', 'revision']);
        abort_if($header === null, 404);

        // Resolve occupancy for the entire scoped company in one query.
        // Per-line counting previously caused N+1 queries for large staffing plans.
        $occupancy = DB::table('employee_assignments as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->where('e.company_id', $company)
            ->where('a.valid_from', '<=', $asOf)
            ->where(function ($dates) use ($asOf): void {
                $dates->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $asOf);
            })
            ->select('a.department_id', 'a.position_id')
            ->selectRaw('COUNT(*) as occupied_count')
            ->groupBy('a.department_id', 'a.position_id')
            ->get()
            ->keyBy(static fn (object $row): string => $row->department_id.'|'.$row->position_id);

        $lines = DB::table('staffing_plan_lines as l')
            ->join('departments as d', 'd.id', '=', 'l.department_id')
            ->join('positions as p', 'p.id', '=', 'l.position_id')
            ->where('l.staffing_plan_id', $plan)
            ->where('d.company_id', $company)
            ->where('p.company_id', $company)
            ->select(
                'l.id',
                'l.department_id',
                'l.position_id',
                'd.name as department_name',
                'p.name as position_name',
                'l.planned_units',
                'l.base_salary',
                'l.currency',
                'l.notes',
                'l.revision',
            )
            ->orderBy('d.name')
            ->orderBy('p.name')
            ->orderBy('l.id')
            ->get()
            ->map(function (object $line) use ($occupancy): array {
                $key = $line->department_id.'|'.$line->position_id;
                $occupied = (int) ($occupancy->get($key)->occupied_count ?? 0);

                $planned = (float) $line->planned_units;
                $vacant = max($planned - $occupied, 0);
                $overstaffed = max($occupied - $planned, 0);

                return [
                    'id' => $line->id,
                    'department_id' => $line->department_id,
                    'position_id' => $line->position_id,
                    'department_name' => $line->department_name,
                    'position_name' => $line->position_name,
                    'planned_units' => $planned,
                    'occupied_units' => $occupied,
                    'vacant_units' => $vacant,
                    'overstaffed_units' => $overstaffed,
                    'base_salary' => $line->base_salary !== null ? (float) $line->base_salary : null,
                    'currency' => $line->currency,
                    'notes' => $line->notes,
                    'revision' => (int) $line->revision,
                ];
            });

        return response()->json([
            'data' => $header,
            'lines' => $lines,
            'meta' => [
                'as_of' => $asOf,
                'staffing_writes_enabled' => $this->canWrite($request, $company) && $header->status === 'draft',
            ],
        ]);
    }

    public function store(Request $request, string $company, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['name', 'valid_from', 'valid_to', 'source_plan_id', 'source_plan_revision']);

        $data = $request->validate(array_merge($this->planRules(), [
            'source_plan_id' => ['nullable', 'uuid'],
            'source_plan_revision' => ['required_with:source_plan_id', 'integer', 'min:1'],
        ]));
        $id = (string) Str::uuid();

        $copiedLines = 0;
        DB::transaction(function () use ($request, $company, $data, $id, $audit, &$copiedLines): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();

            $sourceId = $data['source_plan_id'] ?? null;
            $sourceLines = collect();
            if ($sourceId !== null) {
                $source = DB::table('staffing_plans')->where('id', $sourceId)
                    ->where('company_id', $company)->lockForUpdate()->first(['id', 'revision']);
                if ($source === null) {
                    throw ValidationException::withMessages([
                        'source_plan_id' => 'Source staffing plan is unavailable for this company.',
                    ]);
                }
                abort_unless((int) $source->revision === (int) $data['source_plan_revision'], 409,
                    'Source staffing plan changed; refresh before copying.');
                $sourceLines = DB::table('staffing_plan_lines')->where('staffing_plan_id', $sourceId)
                    ->get(['department_id', 'position_id', 'planned_units', 'base_salary', 'currency', 'notes']);
            }

            DB::table('staffing_plans')->insert([
                'id' => $id,
                'company_id' => $company,
                'name' => $data['name'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'status' => 'draft',
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // One transaction protects against partial copy and concurrent source edits.
            $inserted = [];
            $now = now();
            foreach ($sourceLines as $line) {
                $inserted[] = [
                    'id' => (string) Str::uuid(),
                    'staffing_plan_id' => $id,
                    'department_id' => $line->department_id,
                    'position_id' => $line->position_id,
                    'planned_units' => $line->planned_units,
                    'base_salary' => $line->base_salary,
                    'currency' => $line->currency,
                    'notes' => $line->notes,
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($inserted, 150) as $chunk) {
                DB::table('staffing_plan_lines')->insert($chunk);
            }
            $copiedLines = count($inserted);
            $audit->record($request->user(), $company, HrAuditAction::StaffingPlanCreated, null, [
                'staffing_plan_id' => $id,
                'revision' => 1,
                'source_plan_id' => $sourceId,
                'copied_lines' => $copiedLines,
            ]);
        });

        return response()->json(['data' => [
            'id' => $id,
            'name' => $data['name'],
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'status' => 'draft',
            'revision' => 1,
            'copied_lines' => $copiedLines,
        ]], 201);
    }

    public function update(
        Request $request,
        string $company,
        string $plan,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['name', 'valid_from', 'valid_to', 'revision']);

        $data = $request->validate(array_merge($this->planRules(), [
            'revision' => ['required', 'integer', 'min:1'],
        ]));
        $expectedRevision = (int) $data['revision'];

        DB::transaction(function () use ($request, $company, $plan, $data, $expectedRevision, $audit): void {
            $current = DB::table('staffing_plans')
                ->where('id', $plan)
                ->where('company_id', $company)
                ->lockForUpdate()
                ->first(['status', 'revision']);
            abort_if($current === null, 404);

            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['plan' => 'Only a draft staffing plan can be edited.']);
            }
            if ((int) $current->revision !== $expectedRevision) {
                abort(409, 'The staffing plan was changed by another user. Reload before editing.');
            }

            $updated = DB::table('staffing_plans')
                ->where('id', $plan)
                ->where('company_id', $company)
                ->where('revision', $expectedRevision)
                ->update([
                    'name' => $data['name'],
                    'valid_from' => $data['valid_from'],
                    'valid_to' => $data['valid_to'] ?? null,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                abort(409, 'The staffing plan was changed by another user. Reload before editing.');
            }

            $audit->record($request->user(), $company, HrAuditAction::StaffingPlanUpdated, null, [
                'staffing_plan_id' => $plan,
                'revision' => $expectedRevision + 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $plan,
            'name' => $data['name'],
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'status' => 'draft',
            'revision' => $expectedRevision + 1,
        ]]);
    }

    public function storeLine(
        Request $request,
        string $company,
        string $plan,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['department_id', 'position_id', 'planned_units', 'base_salary', 'currency', 'notes']);
        $data = $request->validate($this->lineRules());
        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $company, $plan, $data, $id, $audit): void {
            $this->lockEditablePlan($company, $plan);
            $this->assertDirectoryOwnership($company, $data['department_id'], $data['position_id']);

            $duplicate = DB::table('staffing_plan_lines')
                ->where('staffing_plan_id', $plan)
                ->where('department_id', $data['department_id'])
                ->where('position_id', $data['position_id'])
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'position_id' => 'This department and position already exist in the staffing plan.',
                ]);
            }

            DB::table('staffing_plan_lines')->insert([
                'id' => $id,
                'staffing_plan_id' => $plan,
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'],
                'planned_units' => $data['planned_units'],
                'base_salary' => $data['base_salary'] ?? null,
                'currency' => $data['currency'] ?? null,
                'notes' => $data['notes'] ?? null,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::StaffingPlanLineCreated, null, [
                'staffing_plan_id' => $plan,
                'staffing_plan_line_id' => $id,
                'revision' => 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $id,
            'staffing_plan_id' => $plan,
            'revision' => 1,
        ]], 201);
    }

    public function updateLine(
        Request $request,
        string $company,
        string $plan,
        string $line,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['department_id', 'position_id', 'planned_units', 'base_salary', 'currency', 'notes', 'revision']);
        $data = $request->validate(array_merge($this->lineRules(), [
            'revision' => ['required', 'integer', 'min:1'],
        ]));
        $expectedRevision = (int) $data['revision'];

        DB::transaction(function () use ($request, $company, $plan, $line, $data, $expectedRevision, $audit): void {
            $this->lockEditablePlan($company, $plan);
            $this->assertDirectoryOwnership($company, $data['department_id'], $data['position_id']);

            $current = DB::table('staffing_plan_lines')
                ->where('id', $line)
                ->where('staffing_plan_id', $plan)
                ->lockForUpdate()
                ->first(['revision']);
            abort_if($current === null, 404);
            if ((int) $current->revision !== $expectedRevision) {
                abort(409, 'The staffing line was changed by another user. Reload before editing.');
            }

            $duplicate = DB::table('staffing_plan_lines')
                ->where('staffing_plan_id', $plan)
                ->where('department_id', $data['department_id'])
                ->where('position_id', $data['position_id'])
                ->where('id', '!=', $line)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'position_id' => 'This department and position already exist in the staffing plan.',
                ]);
            }

            $updated = DB::table('staffing_plan_lines')
                ->where('id', $line)
                ->where('staffing_plan_id', $plan)
                ->where('revision', $expectedRevision)
                ->update([
                    'department_id' => $data['department_id'],
                    'position_id' => $data['position_id'],
                    'planned_units' => $data['planned_units'],
                    'base_salary' => $data['base_salary'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                abort(409, 'The staffing line was changed by another user. Reload before editing.');
            }

            $audit->record($request->user(), $company, HrAuditAction::StaffingPlanLineUpdated, null, [
                'staffing_plan_id' => $plan,
                'staffing_plan_line_id' => $line,
                'revision' => $expectedRevision + 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $line,
            'revision' => $expectedRevision + 1,
        ]]);
    }

    public function approve(
        Request $request,
        string $company,
        string $plan,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['revision']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        $expectedRevision = (int) $data['revision'];

        DB::transaction(function () use ($request, $company, $plan, $expectedRevision, $audit): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();

            $current = DB::table('staffing_plans')
                ->where('id', $plan)
                ->where('company_id', $company)
                ->lockForUpdate()
                ->first(['valid_from', 'valid_to', 'status', 'revision']);
            abort_if($current === null, 404);

            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['plan' => 'Only a draft staffing plan can be approved.']);
            }
            if ((int) $current->revision !== $expectedRevision) {
                abort(409, 'The staffing plan was changed by another user. Reload before approval.');
            }
            if (!DB::table('staffing_plan_lines')->where('staffing_plan_id', $plan)->exists()) {
                throw ValidationException::withMessages(['plan' => 'Add at least one staffing line before approval.']);
            }

            $overlap = DB::table('staffing_plans')
                ->where('company_id', $company)
                ->where('status', 'approved')
                ->where('id', '!=', $plan)
                ->where('valid_from', '<=', $current->valid_to ?? '9999-12-31')
                ->where(function ($dates) use ($current): void {
                    $dates->whereNull('valid_to')->orWhere('valid_to', '>=', $current->valid_from);
                })
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'valid_from' => 'An approved staffing plan already covers part of this period.',
                ]);
            }

            $updated = DB::table('staffing_plans')
                ->where('id', $plan)
                ->where('company_id', $company)
                ->where('revision', $expectedRevision)
                ->update([
                    'status' => 'approved',
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                abort(409, 'The staffing plan was changed by another user. Reload before approval.');
            }

            $audit->record($request->user(), $company, HrAuditAction::StaffingPlanApproved, null, [
                'staffing_plan_id' => $plan,
                'revision' => $expectedRevision + 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $plan,
            'status' => 'approved',
            'revision' => $expectedRevision + 1,
        ]]);
    }

    private function planRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }

    private function lineRules(): array
    {
        return [
            'department_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'planned_units' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'base_salary' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(['KZT', 'USD', 'EUR', 'GEL', 'RUB'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function lockEditablePlan(string $company, string $plan): object
    {
        $header = DB::table('staffing_plans')
            ->where('id', $plan)
            ->where('company_id', $company)
            ->lockForUpdate()
            ->first(['id', 'status']);
        abort_if($header === null, 404);
        if ($header->status !== 'draft') {
            throw ValidationException::withMessages(['plan' => 'Approved staffing plans are immutable. Create a new version.']);
        }

        return $header;
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

    private function rejectUnexpected(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected staffing fields.']);
        }
    }

    private function authorizeRead(Request $request, string $company): void
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
    }

    private function authorizeWrite(Request $request, string $company): void
    {
        abort_unless($this->canWrite($request, $company), 404);
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
}
