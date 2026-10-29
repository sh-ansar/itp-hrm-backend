<?php

namespace App\Domain\Worktime;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates version-pinned, per-employee daily PLANS. Never writes worktime_entries.
 * Preview and apply run the identical evaluation inside a serialized tenant lock.
 */
final class ScheduleCyclePlanner
{
    public function __construct(
        private readonly WorkforceAuthorization $access,
        private readonly HrAuditWriter $audit,
    ) {}

    public function run(User $actor, string $company, string $schedule, array $input): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $input['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $input['to']);
        if (!$from || !$to || $to->lessThan($from) || $from->lessThan(CarbonImmutable::today())
            || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['from' => 'Plan future dates, up to 366 consecutive days.']);
        }
        $ids = $input['employee_ids'];
        sort($ids, SORT_STRING);
        abort_unless($this->access->canPlanEmployees(
            $actor, $company, $ids, $from->toDateString(), $to->toDateString(),
        ), 403);
        $offset = (int) ($input['cycle_offset'] ?? 0);
        $replace = (bool) ($input['replace_future_plans'] ?? false);
        $apply = (bool) $input['apply'];

        return DB::transaction(function () use (
            $actor, $company, $schedule, $input, $from, $to, $ids, $offset, $replace, $apply,
        ): array {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            abort_unless($this->access->canPlanEmployees(
                $actor, $company, $ids, $from->toDateString(), $to->toDateString(),
            ), 403);
            $template = DB::table('work_schedules')->where('company_id', $company)
                ->where('id', $schedule)->lockForUpdate()->firstOrFail();
            abort_unless((int) $template->revision === (int) $input['schedule_revision'], 409,
                'The schedule has changed since it was selected.');

            $pattern = DB::table('work_schedule_patterns')->where('work_schedule_id', $schedule)
                ->orderByDesc('version')->first();
            abort_unless($pattern !== null, 409, 'Configure a schedule cycle before assigning it.');
            $days = json_decode($pattern->days, true, 512, JSON_THROW_ON_ERROR);
            abort_unless($offset >= 0 && $offset < count($days)
                && ($pattern->calendar_policy !== 'company' || $offset === 0), 422,
                'The cycle offset must fit the selected schedule.');

            $companyEmployees = DB::table('employees')->where('company_id', $company)
                ->whereIn('id', $ids)->pluck('id')->all();
            if (count($companyEmployees) !== count($ids)) {
                throw ValidationException::withMessages(['employee_ids' => 'Employees must belong to the selected company.']);
            }

            $dates = [];
            for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $dates[] = $day->toDateString();
            }
            $calendar = DB::table('production_calendar_days')->where('company_id', $company)
                ->whereBetween('work_date', [$input['from'], $input['to']])
                ->get(['work_date', 'day_type', 'planned_minutes', 'revision'])->keyBy('work_date');

            $employment = DB::table('employee_assignments')->whereIn('employee_id', $ids)
                ->where('valid_from', '<=', $input['to'])
                ->where(function ($query) use ($input): void {
                    $query->whereNull('valid_to')->orWhere('valid_to', '>=', $input['from']);
                })
                ->get(['id', 'employee_id', 'department_id', 'valid_from', 'valid_to'])->groupBy('employee_id');

            $leaves = DB::table('employee_leaves')->whereIn('employee_id', $ids)
                ->whereNull('cancelled_at')->where('planned_started_on', '<=', $input['to'])
                ->where('planned_ended_on', '>=', $input['from'])
                ->orderBy('employee_id')->orderBy('id')
                ->get(['id', 'employee_id', 'planned_started_on', 'planned_ended_on'])
                ->groupBy('employee_id');
            $absences = DB::table('employee_absences')->whereIn('employee_id', $ids)
                ->where('status', '<>', 'cancelled')->where('started_on', '<=', $input['to'])
                ->where('ended_on', '>=', $input['from'])
                ->orderBy('employee_id')->orderBy('id')
                ->get(['id', 'employee_id', 'started_on', 'ended_on'])
                ->groupBy('employee_id');

            $oldPlans = DB::table('employee_work_plans')->where('company_id', $company)
                ->whereIn('employee_id', $ids)->whereBetween('work_date', [$input['from'], $input['to']])
                ->get(['id', 'employee_id', 'work_date', 'revision', 'planned_minutes', 'pattern_id'])
                ->keyBy(fn (object $row): string => $row->employee_id.'|'.$row->work_date);

            $recorded = DB::table('worktime_entries')->whereIn('employee_id', $ids)
                ->whereBetween('work_date', [$input['from'], $input['to']])
                ->where(function ($query): void {
                    $query->whereNotNull('worked_minutes')->orWhereNotNull('absence_minutes')
                        ->orWhereNotNull('overtime_minutes');
                })->get(['employee_id', 'work_date'])
                ->keyBy(fn (object $row): string => $row->employee_id.'|'.$row->work_date);
            $months = array_values(array_unique(array_map(
                static fn (string $date): string => substr($date, 0, 7), $dates,
            )));
            $lockedMonths = DB::table('worktime_periods')->where('company_id', $company)
                ->whereIn('month', $months)->whereIn('status', ['submitted', 'approved'])
                ->pluck('month')->flip()->all();

            $oldAssignments = DB::table('employee_work_schedules')
                ->whereIn('employee_id', $ids)->where('valid_from', '<=', $input['to'])
                ->where(function ($query) use ($input): void {
                    $query->whereNull('valid_to')->orWhere('valid_to', '>=', $input['from']);
                })
                ->orderBy('employee_id')->orderBy('valid_from')
                ->get(['id', 'employee_id', 'work_schedule_id', 'pattern_id', 'cycle_offset',
                    'cycle_anchor_on', 'valid_from', 'valid_to', 'revision'])->groupBy('employee_id');

            $counts = [
                'employee_count' => count($ids), 'date_count' => count($dates),
                'planned_workdays' => 0, 'planned_rest_days' => 0, 'planned_minutes' => 0,
                'missing_calendar' => 0, 'outside_employment' => 0,
                'locked_days' => 0, 'recorded_actual' => 0,
                'overlapping_leave' => 0, 'overlapping_absence' => 0,
                'existing_plans' => 0, 'existing_assignments' => 0, 'new_days' => 0,
            ];
            $candidates = [];
            $signature = [];
            $sample = [];
            $cycleLen = count($days);
            foreach ($ids as $employeeId) {
                if (($oldAssignments->get($employeeId) ?? collect())->isNotEmpty()) {
                    $counts['existing_assignments']++;
                }
                $rows = $employment->get($employeeId) ?? collect();
                foreach ($dates as $index => $date) {
                    $calendarDay = $calendar->get($date);
                    if ($calendarDay === null) {
                        $counts['missing_calendar']++;
                        continue;
                    }
                    $active = $rows->filter(fn (object $row): bool =>
                        $row->valid_from <= $date && ($row->valid_to === null || $row->valid_to >= $date));
                    if ($active->count() !== 1) {
                        $counts['outside_employment']++;
                        continue;
                    }
                    $onLeave = ($leaves->get($employeeId) ?? collect())->contains(
                        fn (object $row): bool => $row->planned_started_on <= $date
                            && $row->planned_ended_on >= $date);
                    if ($onLeave) {
                        $counts['overlapping_leave']++;
                        continue;
                    }
                    $onAbsence = ($absences->get($employeeId) ?? collect())->contains(
                        fn (object $row): bool => $row->started_on <= $date
                            && $row->ended_on >= $date);
                    if ($onAbsence) {
                        $counts['overlapping_absence']++;
                        continue;
                    }
                    if (isset($lockedMonths[substr($date, 0, 7)])) {
                        $counts['locked_days']++;
                        continue;
                    }
                    $key = $employeeId.'|'.$date;
                    if ($recorded->has($key)) {
                        $counts['recorded_actual']++;
                        continue;
                    }
                    $currentPlan = $oldPlans->get($key);
                    if ($currentPlan !== null) $counts['existing_plans']++;
                    if ($pattern->calendar_policy === 'company') {
                        // Company-calendar cycles start with Monday and respect holidays.
                        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso - 1;
                        $templateDay = $days[$weekday];
                        $minutes = (int) $templateDay['minutes'];
                        if ($calendarDay->day_type === 'holiday') {
                            $minutes = 0;
                        } elseif ($calendarDay->day_type === 'shortened') {
                            $minutes = min($minutes, (int) ($calendarDay->planned_minutes ?? $minutes));
                        }
                    } else {
                        // Rotations ignore weekend/holiday OFF overrides: the shift
                        // can still be planned. Payroll classification is separate.
                        $templateDay = $days[($index + $offset) % $cycleLen];
                        $minutes = (int) $templateDay['minutes'];
                    }
                    $kind = $minutes > 0 ? 'work' : 'rest';
                    $counts[$minutes > 0 ? 'planned_workdays' : 'planned_rest_days']++;
                    $counts['planned_minutes'] += $minutes;
                    if (!$currentPlan) $counts['new_days']++;
                    $candidate = [
                        'employee_id' => $employeeId, 'work_date' => $date,
                        'planned_minutes' => $minutes, 'day_kind' => $kind,
                        'shift_code' => $minutes > 0 ? ($templateDay['shift_code'] ?? null) : null,
                        'existing' => $currentPlan,
                    ];
                    $candidates[$employeeId][] = $candidate;
                    if (count($sample) < 14) $sample[] = [
                        'employee_id' => $employeeId, 'date' => $date,
                        'planned_minutes' => $minutes, 'day_kind' => $kind,
                    ];
                    $signature[] = [$employeeId, $date, $minutes, $candidate['shift_code'],
                        $calendarDay->revision, $active->first()->id,
                        $active->first()->department_id,
                        $active->first()->valid_from, $active->first()->valid_to,
                        $currentPlan?->id, $currentPlan?->revision, $currentPlan?->planned_minutes];
                }
            }
            $assignmentSignature = $oldAssignments->flatMap(fn ($list) => $list->map(
                fn (object $row): array => [$row->id, $row->work_schedule_id, $row->pattern_id,
                    $row->valid_from, $row->valid_to, $row->revision],
            ))->values()->all();
            $absenceSignature = [
                $leaves->flatMap(fn ($list) => $list->map(
                    fn (object $row): array => [$row->id, $row->planned_started_on, $row->planned_ended_on],
                ))->values()->all(),
                $absences->flatMap(fn ($list) => $list->map(
                    fn (object $row): array => [$row->id, $row->started_on, $row->ended_on],
                ))->values()->all(),
            ];
            $previewToken = hash('sha256', json_encode([
                $company, $schedule, $template->revision, $pattern->id, $input['from'], $input['to'],
                $ids, $offset, $replace, $counts, $signature, $assignmentSignature, $absenceSignature,
            ], JSON_THROW_ON_ERROR));

            $blockers = $counts['missing_calendar'] + $counts['outside_employment']
                + $counts['locked_days'] + $counts['recorded_actual']
                + $counts['overlapping_leave'] + $counts['overlapping_absence']
                + (!$replace ? $counts['existing_plans'] + $counts['existing_assignments'] : 0);
            if ($apply) {
                abort_unless($blockers === 0, 409, 'Resolve planning conflicts before saving.');
                abort_unless(hash_equals($previewToken, (string) ($input['preview_token'] ?? '')), 409,
                    'Preview is stale; run a new preview.');
                foreach ($ids as $employeeId) {
                    $assignmentId = (string) Str::uuid();
                    $this->replaceAssignmentInterval(
                        $employeeId, $schedule, $pattern->id, $input['from'], $input['to'],
                        $offset, $assignmentId, $oldAssignments->get($employeeId) ?? collect(),
                    );
                    $inserts = [];
                    foreach ($candidates[$employeeId] ?? [] as $candidate) {
                        $existing = $candidate['existing'];
                        $values = [
                            'schedule_assignment_id' => $assignmentId,
                            'pattern_id' => $pattern->id,
                            'planned_minutes' => $candidate['planned_minutes'],
                            'day_kind' => $candidate['day_kind'],
                            'shift_code' => $candidate['shift_code'],
                            'source' => 'cycle', 'updated_at' => now(),
                        ];
                        if ($existing === null) {
                            $inserts[] = [
                                'id' => (string) Str::uuid(), 'company_id' => $company,
                                'employee_id' => $employeeId, 'work_date' => $candidate['work_date'],
                                ...$values, 'revision' => 1, 'created_at' => now(),
                            ];
                        } else {
                            $updated = DB::table('employee_work_plans')->where('id', $existing->id)
                                ->where('revision', $existing->revision)
                                ->update([...$values, 'revision' => (int) $existing->revision + 1]);
                            abort_unless($updated === 1, 409, 'A planned day changed during the update.');
                        }
                    }
                    foreach (array_chunk($inserts, 250) as $chunk) {
                        DB::table('employee_work_plans')->insert($chunk);
                    }
                    $this->cleanupAssignments($employeeId);
                }
                $this->audit->record($actor, $company, HrAuditAction::WorkScheduleAssigned, null, [
                    'source' => 'cycle_bulk_plan', 'schedule_id' => $schedule,
                    'pattern_id' => $pattern->id, 'from' => $input['from'], 'to' => $input['to'],
                    'employee_count' => count($ids), 'new_days' => $counts['new_days'],
                    'replaced_days' => $counts['existing_plans'],
                ]);
            }

            return [
                'data' => [...$counts, 'blocking_count' => $blockers, 'sample' => $sample],
                'meta' => [
                    'mode' => $apply ? 'applied' : 'preview', 'preview_token' => $previewToken,
                    'pattern_version' => (int) $pattern->version, 'schedule_revision' => (int) $template->revision,
                    'can_apply' => $blockers === 0,
                ],
            ];
        }, 3);
    }

    /**
     * Split overlapping history into [old left] [new interval] [old right].
     * Preserves the old anchor on the right so rotations never restart.
     */
    private function replaceAssignmentInterval(
        string $employee, string $schedule, string $pattern, string $from, string $to,
        int $offset, string $newId, \Illuminate\Support\Collection $overlaps,
    ): void {
        $leftEnd = CarbonImmutable::parse($from)->subDay()->toDateString();
        $rightStart = CarbonImmutable::parse($to)->addDay()->toDateString();
        $delete = [];
        foreach ($overlaps as $row) {
            $keepLeft = $row->valid_from < $from;
            $keepRight = $row->valid_to === null || $row->valid_to > $to;
            if ($keepLeft) {
                DB::table('employee_work_schedules')->where('id', $row->id)->update([
                    'valid_to' => $leftEnd, 'revision' => (int) $row->revision + 1, 'updated_at' => now(),
                ]);
            }
            if ($keepRight) {
                $rightId = (string) Str::uuid();
                DB::table('employee_work_schedules')->insert([
                    'id' => $rightId, 'employee_id' => $employee,
                    'work_schedule_id' => $row->work_schedule_id, 'pattern_id' => $row->pattern_id,
                    'cycle_offset' => $row->cycle_offset, 'cycle_anchor_on' => $row->cycle_anchor_on,
                    'valid_from' => $rightStart, 'valid_to' => $row->valid_to,
                    'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('employee_work_plans')->where('schedule_assignment_id', $row->id)
                    ->where('work_date', '>=', $rightStart)->update([
                        'schedule_assignment_id' => $rightId, 'updated_at' => now(),
                    ]);
            }
            if (!$keepLeft) $delete[] = $row->id;
        }
        DB::table('employee_work_schedules')->insert([
            'id' => $newId, 'employee_id' => $employee, 'work_schedule_id' => $schedule,
            'pattern_id' => $pattern, 'cycle_offset' => $offset, 'cycle_anchor_on' => $from,
            'valid_from' => $from, 'valid_to' => $to, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Defer deletion until after the caller rewrites any existing plan rows
        // that reference this interval; caller invokes cleanup after upsert.
        // Original assignments are left intact momentarily to keep FK integrity.
        // They are marked for cleanup by the caller once all days have new links.
        $this->pendingDeletes[$employee] = $delete;
    }

    /** @var array<string,list<string>> */
    private array $pendingDeletes = [];

    private function cleanupAssignments(string $employee): void
    {
        foreach ($this->pendingDeletes[$employee] ?? [] as $id) {
            abort_if(DB::table('employee_work_plans')->where('schedule_assignment_id', $id)->exists(),
                409, 'An old planned day remains linked to the replaced assignment.');
            DB::table('employee_work_schedules')->where('id', $id)->delete();
        }
        unset($this->pendingDeletes[$employee]);
    }
}
