<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkSchedulePatternController extends Controller
{
    /** Lightweight, non-financial catalogue for department planners. */
    public function index(Request $request, string $company, WorkforceAuthorization $access): JsonResponse
    {
        abort_unless((bool) config('hrm.worktime_planning_enabled', false), 404);
        abort_unless($access->canReadPlanningTemplates($request->user(), $company), 403);
        $rows = DB::table('work_schedules')->where('company_id', $company)
            ->orderBy('name')->get(['id', 'name', 'code', 'schedule_type', 'revision']);
        return response()->json(['data' => $rows, 'meta' => [
            'can_edit_patterns' => $access->canEditTimesheet($request->user(), $company),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $company, string $schedule, WorkforceAuthorization $access): JsonResponse
    {
        abort_unless((bool) config('hrm.worktime_planning_enabled', false), 404);
        abort_unless($access->canReadPlanningTemplates($request->user(), $company), 403);
        $header = DB::table('work_schedules')->where('company_id', $company)->where('id', $schedule)->firstOrFail();
        $pattern = DB::table('work_schedule_patterns')->where('work_schedule_id', $schedule)
            ->orderByDesc('version')->first();

        return response()->json([
            'data' => $pattern ? $this->serialize($pattern) : null,
            'meta' => [
                'schedule_revision' => (int) $header->revision,
                'can_edit' => $access->canEditTimesheet($request->user(), $company),
            ],
        ]);
    }

    public function update(
        Request $request, string $company, string $schedule,
        WorkforceAuthorization $access, HrAuditWriter $audit,
    ): JsonResponse {
        abort_unless((bool) config('hrm.worktime_planning_enabled', false), 404);
        // A manager can assign a reviewed company template, not modify global rules.
        abort_unless($access->canEditTimesheet($request->user(), $company), 403);

        $input = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'calendar_policy' => ['required', Rule::in(['company', 'cycle'])],
            'days' => ['required', 'array', 'min:1', 'max:60'],
            'days.*.minutes' => ['required', 'integer', 'between:0,1440'],
            'days.*.shift_code' => ['present', 'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if (array_diff(array_keys($request->all()), ['revision', 'calendar_policy', 'days']) !== []) {
            throw ValidationException::withMessages(['pattern' => 'Unexpected pattern fields.']);
        }
        foreach ($input['days'] as $day) {
            if (array_diff(array_keys($day), ['minutes', 'shift_code']) !== []) {
                throw ValidationException::withMessages(['days' => 'Unexpected cycle fields.']);
            }
        }
        if ($input['calendar_policy'] === 'company' && count($input['days']) !== 7) {
            throw ValidationException::withMessages(['days' => 'A company-calendar template has exactly 7 days (Monday first).']);
        }
        if (!collect($input['days'])->contains(fn (array $day): bool => (int) $day['minutes'] > 0)) {
            throw ValidationException::withMessages(['days' => 'The cycle must contain at least one working day.']);
        }

        $patternId = DB::transaction(function () use ($request, $company, $schedule, $input, $audit): string {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $header = DB::table('work_schedules')->where('company_id', $company)
                ->where('id', $schedule)->lockForUpdate()->firstOrFail();
            abort_unless((int) $header->revision === (int) $input['revision'], 409,
                'Schedule changed; refresh before saving the cycle.');
            $version = (int) $header->revision + 1;
            $id = (string) Str::uuid();
            DB::table('work_schedule_patterns')->insert([
                'id' => $id,
                'company_id' => $company,
                'work_schedule_id' => $schedule,
                'version' => $version,
                'calendar_policy' => $input['calendar_policy'],
                'days' => json_encode($input['days'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('work_schedules')->where('id', $schedule)->where('revision', $header->revision)
                ->update(['revision' => $version, 'updated_at' => now()]);
            $audit->record($request->user(), $company, HrAuditAction::WorkScheduleUpdated, null, [
                'schedule_id' => $schedule, 'revision' => $version, 'change' => 'pattern',
            ]);
            return $id;
        }, 3);

        return response()->json(['data' => $this->serialize(
            DB::table('work_schedule_patterns')->where('id', $patternId)->firstOrFail(),
        )]);
    }

    private function serialize(object $row): array
    {
        return [
            'id' => $row->id,
            'schedule_id' => $row->work_schedule_id,
            'version' => (int) $row->version,
            'calendar_policy' => $row->calendar_policy,
            'days' => json_decode($row->days, true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
