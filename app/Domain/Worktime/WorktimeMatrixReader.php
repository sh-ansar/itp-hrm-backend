<?php

namespace App\Domain\Worktime;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class WorktimeMatrixReader
{
    public function __construct(
        private readonly WorktimeMatrixAuthorization $access,
        private readonly HrAuditWriter $audit,
        private readonly EmploymentPeriodLookup $employment,
    ) {}

    public function read(User $actor, string $company, array $input): array
    {
        $from = $input['from'];
        $to = $input['to'];
        $base = $this->access->visibleEmployees($actor, $company, $from, $to);

        $search = trim((string) ($input['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search).'%';
            $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $base->where(function (Builder $q) use ($operator, $like): void {
                foreach (['e.personnel_number', 'e.last_name', 'e.first_name', 'e.middle_name'] as $column) {
                    $q->orWhere($column, $operator, $like);
                }
            });
        }
        if (!empty($input['department_id'])) {
            $base->whereExists(function (Builder $q) use ($input, $to): void {
                $q->selectRaw('1')->from('employee_assignments as filter_assignment')
                    ->whereColumn('filter_assignment.employee_id', 'e.id')
                    ->where('filter_assignment.department_id', $input['department_id'])
                    ->where('filter_assignment.valid_from', '<=', $to)
                    ->where(function (Builder $dates) use ($to): void {
                        $dates->whereNull('filter_assignment.valid_to')
                            ->orWhere('filter_assignment.valid_to', '>=', $to);
                    });
            });
        }
        if (!empty($input['schedule_id'])) {
            $base->whereExists(function (Builder $q) use ($input, $to, $company): void {
                $q->selectRaw('1')->from('employee_work_schedules as filter_schedule')
                    ->join('work_schedules as filter_ws', 'filter_ws.id', '=', 'filter_schedule.work_schedule_id')
                    ->where('filter_ws.company_id', $company)
                    ->whereColumn('filter_schedule.employee_id', 'e.id')
                    ->where('filter_schedule.work_schedule_id', $input['schedule_id'])
                    ->where('filter_schedule.valid_from', '<=', $to)
                    ->where(function (Builder $dates) use ($to): void {
                        $dates->whereNull('filter_schedule.valid_to')
                            ->orWhere('filter_schedule.valid_to', '>=', $to);
                    });
            });
        }
        if (($input['status'] ?? 'all') !== 'all') {
            $queryMethod = $input['status'] === 'recorded' ? 'whereExists' : 'whereNotExists';
            $base->{$queryMethod}(function (Builder $q) use ($from, $to): void {
                $q->selectRaw('1')->from('worktime_entries as record_filter')
                    ->whereColumn('record_filter.employee_id', 'e.id')
                    ->whereBetween('record_filter.work_date', [$from, $to]);
            });
        }

        $departmentName = fn (): Builder => DB::table('employee_assignments as asof_a')
            ->join('departments as asof_d', 'asof_d.id', '=', 'asof_a.department_id')
            ->whereColumn('asof_a.employee_id', 'e.id')
            ->where('asof_d.company_id', $company)
            ->where('asof_a.valid_from', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('asof_a.valid_to')->orWhere('asof_a.valid_to', '>=', $to))
            ->orderByDesc('asof_a.valid_from')->orderBy('asof_a.id')
            ->select('asof_d.name')->limit(1);
        $departmentId = fn (): Builder => DB::table('employee_assignments as aid')
            ->whereColumn('aid.employee_id', 'e.id')
            ->where('aid.valid_from', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('aid.valid_to')->orWhere('aid.valid_to', '>=', $to))
            ->orderByDesc('aid.valid_from')->orderBy('aid.id')
            ->select('aid.department_id')->limit(1);
        $positionName = fn (): Builder => DB::table('employee_assignments as ap')
            ->join('positions as pos', 'pos.id', '=', 'ap.position_id')
            ->whereColumn('ap.employee_id', 'e.id')
            ->where('pos.company_id', $company)
            ->where('ap.valid_from', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('ap.valid_to')->orWhere('ap.valid_to', '>=', $to))
            ->orderByDesc('ap.valid_from')->orderBy('ap.id')
            ->select('pos.name')->limit(1);
        $scheduleName = fn (): Builder => DB::table('employee_work_schedules as sc')
            ->join('work_schedules as ws', 'ws.id', '=', 'sc.work_schedule_id')
            ->whereColumn('sc.employee_id', 'e.id')
            ->where('ws.company_id', $company)
            ->where('sc.valid_from', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('sc.valid_to')->orWhere('sc.valid_to', '>=', $to))
            ->orderByDesc('sc.valid_from')->orderBy('sc.id')
            ->select('ws.name')->limit(1);

        $base->select('e.id', 'e.personnel_number', 'e.last_name', 'e.first_name', 'e.middle_name')
            ->selectSub($departmentName(), 'department_name')
            ->selectSub($departmentId(), 'department_id')
            ->selectSub($positionName(), 'position_name')
            ->selectSub($scheduleName(), 'schedule_name');
        $groupBy = $input['group_by'] ?? 'department';
        if ($groupBy === 'department') $base->orderBy('department_name');
        if ($groupBy === 'schedule') $base->orderBy('schedule_name');
        $base->orderBy('e.last_name')->orderBy('e.first_name')->orderBy('e.id');

        $page = $base->paginate((int) ($input['per_page'] ?? 30), ['*'], 'page', (int) ($input['page'] ?? 1));
        $ids = array_map(static fn (object $row): string => $row->id, $page->items());
        $enforceEmployment = (bool) config('hrm.worktime_employment_guard_enabled', false);
        $employment = $enforceEmployment
            ? $this->employment->forEmployees($company, $ids, $from, $to)
            : collect();
        $calendar = DB::table('production_calendar_days')->where('company_id', $company)
            ->whereBetween('work_date', [$from, $to])
            ->get(['work_date', 'day_type', 'planned_minutes', 'label'])->keyBy('work_date');
        $months = [];
        for ($date = CarbonImmutable::parse($from); $date->lessThanOrEqualTo(CarbonImmutable::parse($to));
            $date = $date->addDay()) $months[$date->format('Y-m')] = true;
        $locked = DB::table('worktime_periods')->where('company_id', $company)
            ->whereIn('month', array_keys($months))->whereIn('status', ['submitted', 'approved'])
            ->pluck('month')->flip()->all();

        $dates = [];
        for ($date = CarbonImmutable::parse($from); $date->lessThanOrEqualTo(CarbonImmutable::parse($to));
            $date = $date->addDay()) {
            $key = $date->toDateString();
            $calendarRow = $calendar->get($key);
            $dates[] = [
                'date' => $key, 'day_type' => $calendarRow?->day_type ?? 'unconfigured',
                'label' => $calendarRow?->label,
                'locked' => isset($locked[$date->format('Y-m')]),
            ];
        }
        $entries = DB::table('worktime_entries')->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$from, $to])
            ->get(['employee_id', 'work_date', 'revision', 'worked_minutes', 'absence_minutes',
                'overtime_minutes', 'planned_minutes'])
            ->keyBy(fn (object $x): string => $x->employee_id.'|'.$x->work_date);
        $plans = DB::table('employee_work_plans')->where('company_id', $company)
            ->whereIn('employee_id', $ids)->whereBetween('work_date', [$from, $to])
            ->get(['employee_id', 'work_date', 'planned_minutes', 'shift_code'])
            ->keyBy(fn (object $x): string => $x->employee_id.'|'.$x->work_date);
        $leaves = DB::table('employee_leaves')->whereIn('employee_id', $ids)
            ->whereNull('cancelled_at')->where('planned_started_on', '<=', $to)
            ->where('planned_ended_on', '>=', $from)
            ->get(['employee_id', 'planned_started_on', 'planned_ended_on'])->groupBy('employee_id');
        $absences = DB::table('employee_absences')->whereIn('employee_id', $ids)
            ->where('status', '<>', 'cancelled')->where('started_on', '<=', $to)
            ->where('ended_on', '>=', $from)
            ->get(['employee_id', 'started_on', 'ended_on'])->groupBy('employee_id');
        $schedules = DB::table('employee_work_schedules as a')
            ->join('work_schedules as s', 's.id', '=', 'a.work_schedule_id')
            ->where('s.company_id', $company)->whereIn('a.employee_id', $ids)
            ->where('a.valid_from', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $from))
            ->get(['a.employee_id', 'a.valid_from', 'a.valid_to', 's.schedule_type'])
            ->groupBy('employee_id');

        $today = now()->toDateString();
        $canWrite = $this->access->canWrite($actor, $company);
        $rows = [];
        foreach ($page->items() as $employee) {
            $summary = ['planned_minutes' => 0, 'worked_minutes' => 0, 'recorded_days' => 0,
                'missing_days' => 0];
            $hasWorked = false;
            $dayItems = [];
            foreach ($dates as $date) {
                $when = $date['date'];
                $key = $employee->id.'|'.$when;
                $entry = $entries->get($key);
                $plan = $plans->get($key);
                $cal = $calendar->get($when);
                $employed = ! $enforceEmployment
                    || EmploymentPeriodLookup::isEmployed($employment, $employee->id, $when);
                $planned = $employed ? ($plan?->planned_minutes ?? ($cal !== null
                    && in_array($cal->day_type, ['workday', 'shortened'], true)
                        ? $cal->planned_minutes : ($cal !== null ? 0 : null))) : 0;
                $onLeave = ($leaves->get($employee->id) ?? collect())->contains(
                    static fn (object $x): bool => $x->planned_started_on <= $when
                        && $x->planned_ended_on >= $when);
                $onAbsence = ($absences->get($employee->id) ?? collect())->contains(
                    static fn (object $x): bool => $x->started_on <= $when && $x->ended_on >= $when);
                $running = ($schedules->get($employee->id) ?? collect())->filter(
                    static fn (object $x): bool => $x->valid_from <= $when
                        && ($x->valid_to === null || $x->valid_to >= $when));
                $unverifiedShift = $plan === null && ($running->count() > 1
                    || ($running->count() === 1 && $running->first()->schedule_type !== 'standard'));
                $recorded = $entry !== null;
                $state = ! $employed ? 'outside_employment' : ($cal === null ? 'unconfigured'
                    : ($onLeave ? 'leave'
                    : ($onAbsence ? 'absence'
                    : ($recorded ? 'recorded'
                    : ($unverifiedShift ? 'unknown_shift'
                    : (($planned ?? 0) <= 0 ? 'rest'
                    : ($when > $today ? 'future' : 'missing')))))));
                if ($planned !== null) $summary['planned_minutes'] += (int) $planned;
                if ($entry?->worked_minutes !== null) {
                    $summary['worked_minutes'] += (int) $entry->worked_minutes;
                    $hasWorked = true;
                }
                if ($recorded) $summary['recorded_days']++;
                if ($state === 'missing') $summary['missing_days']++;
                $dayItems[] = [
                    'date' => $when, 'planned_minutes' => $planned !== null ? (int) $planned : null,
                    'worked_minutes' => $entry?->worked_minutes !== null ? (int) $entry->worked_minutes : null,
                    'absence_minutes' => $entry?->absence_minutes !== null ? (int) $entry->absence_minutes : null,
                    'overtime_minutes' => $entry?->overtime_minutes !== null ? (int) $entry->overtime_minutes : null,
                    'revision' => $entry?->revision !== null ? (int) $entry->revision : null,
                    'shift_code' => $employed ? $plan?->shift_code : null,
                    'state' => $state,
                    'locked' => $date['locked'],
                    'editable' => $canWrite && $employed && !$date['locked'] && $when <= $today && $cal !== null
                        && !$onLeave && !$onAbsence && !$unverifiedShift && $planned !== null
                        && (int) $planned > 0
                        && ($plan !== null || in_array($cal->day_type, ['workday', 'shortened'], true)),
                ];
            }
            $rows[] = [
                'employee_id' => $employee->id, 'personnel_number' => $employee->personnel_number,
                'full_name' => trim(implode(' ', array_filter([$employee->last_name,
                    $employee->first_name, $employee->middle_name]))),
                'department_name' => $employee->department_name,
                'department_id' => $employee->department_id,
                'position_name' => $employee->position_name,
                'schedule_name' => $employee->schedule_name,
                'summary' => [
                    ...$summary,
                    'worked_minutes' => $hasWorked ? $summary['worked_minutes'] : null,
                ],
                'days' => $dayItems,
            ];
        }
        $visibleEmployeeIds = $this->access->visibleEmployees($actor, $company, $from, $to)
            ->select('e.id');
        $departmentOptions = DB::query()->fromSub($visibleEmployeeIds, 'visible_emp')
            ->join('employee_assignments as option_a', 'option_a.employee_id', '=', 'visible_emp.id')
            ->join('departments as option_d', 'option_d.id', '=', 'option_a.department_id')
            ->where('option_d.company_id', $company)
            ->where('option_a.valid_from', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('option_a.valid_to')->orWhere('option_a.valid_to', '>=', $to))
            ->distinct()->orderBy('option_d.name')->get(['option_d.id', 'option_d.name']);
        $scheduleOptions = DB::query()->fromSub(
            $this->access->visibleEmployees($actor, $company, $from, $to)->select('e.id'), 'visible_emp',
        )->join('employee_work_schedules as option_s', 'option_s.employee_id', '=', 'visible_emp.id')
            ->join('work_schedules as option_ws', 'option_ws.id', '=', 'option_s.work_schedule_id')
            ->where('option_ws.company_id', $company)
            ->where('option_s.valid_from', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('option_s.valid_to')->orWhere('option_s.valid_to', '>=', $to))
            ->distinct()->orderBy('option_ws.name')->get(['option_ws.id', 'option_ws.name']);

        $this->audit->record($actor, $company, HrAuditAction::WorktimeRecordsListed, null, [
            'source' => 'matrix', 'from' => $from, 'to' => $to, 'page' => $page->currentPage(),
            'returned_count' => count($rows),
        ]);
        return [
            'data' => ['dates' => $dates, 'rows' => $rows],
            'meta' => [
                'from' => $from, 'to' => $to, 'group_by' => $groupBy,
                'can_edit' => $canWrite,
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
                'calendar_complete' => count($calendar) === count($dates),
                'manager_scope_policy' => 'single_assignment_full_period',
                'filters' => ['departments' => $departmentOptions, 'schedules' => $scheduleOptions],
            ],
        ];
    }
}