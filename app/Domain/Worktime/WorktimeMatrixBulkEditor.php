<?php

namespace App\Domain\Worktime;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorktimeMatrixBulkEditor
{
    public function __construct(
        private readonly WorktimeMatrixAuthorization $access,
        private readonly HrAuditWriter $audit,
    ) {}

    /** Exactly the same evaluation is used for preview and apply under a tenant lock. */
    public function run(User $actor, string $company, array $input): array
    {
        $employeeIds = $input['employee_ids']
            ?? array_values(array_unique(array_column($input['cells'], 'employee_id')));
        $dates = $input['dates']
            ?? array_values(array_unique(array_column($input['cells'], 'date')));
        $selection = isset($input['cells'])
            ? array_fill_keys(array_map(
                static fn (array $cell): string => $cell['employee_id'].'|'.$cell['date'],
                $input['cells'],
            ), true) : null;
        if ($selection !== null) ksort($selection, SORT_STRING);
        sort($employeeIds, SORT_STRING);
        sort($dates, SORT_STRING);
        $from = $dates[0];
        $to = $dates[array_key_last($dates)];
        $operation = $input['operation'];
        $overwrite = (bool) ($input['overwrite_existing'] ?? false);
        $targetMinutes = $operation === 'set_worked' ? (int) $input['worked_minutes'] : null;
        $apply = (bool) $input['apply'];

        return DB::transaction(function () use ($actor, $company, $input, $employeeIds,
            $dates, $from, $to, $operation, $overwrite, $targetMinutes, $apply, $selection): array {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            // Authorization repeated INSIDE the lock, not only at the HTTP boundary.
            $this->access->assertWriteEmployees($actor, $company, $employeeIds, $from, $to);

            $periods = DB::table('worktime_periods')->where('company_id', $company)
                ->whereIn('month', array_values(array_unique(array_map(
                    static fn (string $d): string => substr($d, 0, 7), $dates,
                ))))->get(['month', 'status', 'revision'])->keyBy('month');

            $calendar = DB::table('production_calendar_days')->where('company_id', $company)
                ->whereIn('work_date', $dates)
                ->get(['work_date', 'revision', 'day_type', 'planned_minutes'])->keyBy('work_date');

            $assignments = DB::table('employee_assignments')->whereIn('employee_id', $employeeIds)
                ->where('valid_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
                ->get(['id', 'employee_id', 'department_id', 'valid_from', 'valid_to'])
                ->groupBy('employee_id');

            $plans = DB::table('employee_work_plans')->where('company_id', $company)
                ->whereIn('employee_id', $employeeIds)->whereIn('work_date', $dates)
                ->get(['employee_id', 'work_date', 'planned_minutes', 'revision', 'pattern_id'])
                ->keyBy(fn (object $x): string => $x->employee_id.'|'.$x->work_date);

            $schedules = DB::table('employee_work_schedules as a')
                ->join('work_schedules as s', 's.id', '=', 'a.work_schedule_id')
                ->where('s.company_id', $company)->whereIn('a.employee_id', $employeeIds)
                ->where('a.valid_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $from))
                ->get(['a.id', 'a.employee_id', 'a.valid_from', 'a.valid_to',
                    'a.revision', 's.schedule_type'])
                ->groupBy('employee_id');

            $leaves = DB::table('employee_leaves')->whereIn('employee_id', $employeeIds)
                ->whereNull('cancelled_at')->where('planned_started_on', '<=', $to)
                ->where('planned_ended_on', '>=', $from)
                ->orderBy('employee_id')->orderBy('id')
                ->get(['id', 'employee_id', 'planned_started_on', 'planned_ended_on', 'revision'])
                ->groupBy('employee_id');
            $absences = DB::table('employee_absences')->whereIn('employee_id', $employeeIds)
                ->where('status', '<>', 'cancelled')->where('started_on', '<=', $to)
                ->where('ended_on', '>=', $from)
                ->orderBy('employee_id')->orderBy('id')
                ->get(['id', 'employee_id', 'started_on', 'ended_on', 'revision'])
                ->groupBy('employee_id');
            $entries = DB::table('worktime_entries')->whereIn('employee_id', $employeeIds)
                ->whereIn('work_date', $dates)
                ->get(['id', 'employee_id', 'work_date', 'revision',
                    'planned_minutes', 'worked_minutes', 'absence_minutes', 'overtime_minutes'])
                ->keyBy(fn (object $x): string => $x->employee_id.'|'.$x->work_date);

            $counts = [
                'candidate_count' => 0, 'new_entries' => 0, 'updates' => 0,
                'skipped_recorded' => 0, 'skipped_leave' => 0, 'skipped_absence' => 0,
                'skipped_nonworking' => 0, 'skipped_future' => 0,
                'missing_calendar' => 0, 'outside_employment' => 0,
                'ambiguous_employment' => 0, 'unknown_shift' => 0,
                'locked_cells' => 0, 'existing_requires_overwrite' => 0,
                'protected_actual' => 0,
            ];
            $candidates = [];
            $signature = [];
            $today = now()->toDateString();
            foreach ($employeeIds as $employee) {
                foreach ($dates as $date) {
                    $key = $employee.'|'.$date;
                    if ($selection !== null && !isset($selection[$key])) continue;
                    $cal = $calendar->get($date);
                    $plan = $plans->get($key);
                    $entry = $entries->get($key);
                    $period = $periods->get(substr($date, 0, 7));
                    $employmentRows = ($assignments->get($employee) ?? collect())->filter(
                        fn (object $x): bool => $x->valid_from <= $date
                            && ($x->valid_to === null || $x->valid_to >= $date));
                    $scheduleRows = ($schedules->get($employee) ?? collect())->filter(
                        fn (object $x): bool => $x->valid_from <= $date
                            && ($x->valid_to === null || $x->valid_to >= $date));
                    $onLeave = ($leaves->get($employee) ?? collect())->filter(
                        fn (object $x): bool => $x->planned_started_on <= $date
                            && $x->planned_ended_on >= $date);
                    $onAbsence = ($absences->get($employee) ?? collect())->filter(
                        fn (object $x): bool => $x->started_on <= $date && $x->ended_on >= $date);
                    $planned = $plan?->planned_minutes ?? $cal?->planned_minutes;

                    // Hash all source rows (including skipped cells) so a newly
                    // added leave, assignment or an approved period invalidates
                    // a previously issued preview token.
                    $signature[] = [$employee, $date, $cal?->revision, $cal?->day_type,
                        $cal?->planned_minutes, $period?->status, $period?->revision,
                        $plan?->revision, $plan?->pattern_id, $planned,
                        $employmentRows->map(fn ($x) => [$x->id, $x->department_id, $x->valid_from, $x->valid_to])->values()->all(),
                        $scheduleRows->map(fn ($x) => [$x->id, $x->revision, $x->schedule_type, $x->valid_from, $x->valid_to])->values()->all(),
                        $onLeave->map(fn ($x) => [$x->id, $x->revision])->values()->all(),
                        $onAbsence->map(fn ($x) => [$x->id, $x->revision])->values()->all(),
                        $entry?->id, $entry?->revision, $entry?->worked_minutes,
                        $entry?->absence_minutes, $entry?->overtime_minutes];

                    if (in_array($period?->status, ['submitted', 'approved'], true)) {
                        $counts['locked_cells']++; continue;
                    }
                    if ($date > $today) {
                        $counts['skipped_future']++; continue;
                    }
                    if ($cal === null) {
                        $counts['missing_calendar']++; continue;
                    }
                    if ($employmentRows->count() === 0) {
                        $counts['outside_employment']++; continue;
                    }
                    if ($employmentRows->count() !== 1) {
                        $counts['ambiguous_employment']++; continue;
                    }
                    if ($onLeave->isNotEmpty()) {
                        $counts['skipped_leave']++; continue;
                    }
                    if ($onAbsence->isNotEmpty()) {
                        $counts['skipped_absence']++; continue;
                    }
                    if ($scheduleRows->count() > 1 || ($scheduleRows->count() === 1
                        && $scheduleRows->first()->schedule_type !== 'standard' && $plan === null)) {
                        $counts['unknown_shift']++; continue;
                    }
                    if ($planned === null || (int) $planned <= 0
                        || ($plan === null && !in_array($cal->day_type, ['workday', 'shortened'], true))) {
                        $counts['skipped_nonworking']++; continue;
                    }
                    if ($entry !== null && ($operation === 'fill_missing')) {
                        $counts['skipped_recorded']++; continue;
                    }
                    if ($entry !== null && !$overwrite) {
                        $counts['existing_requires_overwrite']++; continue;
                    }
                    if ($entry !== null && (((int) $entry->absence_minutes > 0)
                        || ((int) $entry->overtime_minutes > 0))) {
                        $counts['protected_actual']++; continue;
                    }
                    $worked = $operation === 'fill_missing' ? (int) $planned : $targetMinutes;
                    $counts['candidate_count']++;
                    $counts[$entry === null ? 'new_entries' : 'updates']++;
                    $candidates[] = [
                        'employee' => $employee, 'date' => $date, 'planned' => (int) $planned,
                        'worked' => $worked, 'entry' => $entry,
                    ];
                }
            }
            $blockers = $counts['locked_cells'] + $counts['missing_calendar']
                + $counts['ambiguous_employment'] + $counts['existing_requires_overwrite']
                + $counts['protected_actual'];
            // Future/nonworking/leave/absences are skip-only but are included in
            // the reviewed preview, never silently written as actual presence.
            $token = hash('sha256', json_encode([
                $company, $employeeIds, $dates, $selection === null ? null : array_keys($selection),
                $operation, $overwrite,
                $targetMinutes, $counts, $signature,
            ], JSON_THROW_ON_ERROR));

            if ($apply) {
                abort_unless($blockers === 0 && $counts['candidate_count'] > 0, 409,
                    'Resolve conflicts before applying the reviewed attendance changes.');
                abort_unless(isset($input['preview_token'])
                    && hash_equals($token, $input['preview_token']), 409,
                    'Attendance changed after preview; refresh the matrix and preview again.');
                $create = [];
                foreach ($candidates as $row) {
                    $values = [
                        'planned_minutes' => $row['planned'],
                        'worked_minutes' => $row['worked'], 'absence_minutes' => 0,
                        'overtime_minutes' => 0, 'updated_at' => now(),
                    ];
                    $existing = $row['entry'];
                    if ($existing === null) {
                        $create[] = [
                            'id' => (string) Str::uuid(), 'employee_id' => $row['employee'],
                            'work_date' => $row['date'], ...$values,
                            'revision' => 1, 'created_at' => now(),
                        ];
                    } else {
                        $updated = DB::table('worktime_entries')->where('id', $existing->id)
                            ->where('revision', $existing->revision)->update([
                                ...$values, 'revision' => (int) $existing->revision + 1,
                            ]);
                        abort_unless($updated === 1, 409, 'An existing attendance entry changed.');
                    }
                }
                foreach (array_chunk($create, 200) as $batch) {
                    DB::table('worktime_entries')->insert($batch);
                }
                $this->audit->record($actor, $company, HrAuditAction::WorktimeEntriesUpserted, null, [
                    'source' => 'matrix_bulk', 'operation' => $operation,
                    'from' => $from, 'to' => $to, 'employee_count' => count($employeeIds),
                    'created_count' => count($create), 'updated_count' => $counts['updates'],
                ]);
            }
            return [
                'data' => [...$counts, 'blocking_count' => $blockers],
                'meta' => [
                    'mode' => $apply ? 'applied' : 'preview',
                    'from' => $from, 'to' => $to,
                    'preview_token' => $token,
                    'can_apply' => $blockers === 0 && $counts['candidate_count'] > 0,
                ],
            ];
        }, 3);
    }
}
