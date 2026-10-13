<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\EmployeeVisibility;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorktimeReadController extends Controller
{
    public function timesheet(
        Request $request,
        string $company,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        [$from, $to] = $this->monthBounds($validated['month']);
        $visible = $visibility->visibleFor($request->user(), $company);

        $query = DB::query()
            ->fromSub($visible, 've')
            ->leftJoin('worktime_entries as w', function (JoinClause $join) use ($from, $to): void {
                $join->on('w.employee_id', '=', 've.id')
                    ->whereBetween('w.work_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->select([
                've.id as employee_id',
                've.personnel_number',
                've.last_name',
                've.first_name',
                've.middle_name',
            ])
            ->selectSub($this->departmentNameSubquery($company, $to->toDateString()), 'department_name')
            ->selectSub($this->positionNameSubquery($company, $to->toDateString()), 'position_name')
            ->selectSub($this->scheduleNameSubquery($company, $to->toDateString()), 'schedule_name')
            ->selectRaw('COUNT(w.id) as entry_count')
            ->selectRaw('SUM(w.planned_minutes) as planned_minutes')
            ->selectRaw('SUM(w.worked_minutes) as worked_minutes')
            ->selectRaw('SUM(w.absence_minutes) as absence_minutes')
            ->selectRaw('SUM(w.overtime_minutes) as overtime_minutes')
            ->groupBy([
                've.id',
                've.personnel_number',
                've.last_name',
                've.first_name',
                've.middle_name',
            ])
            ->orderBy('ve.last_name')
            ->orderBy('ve.first_name')
            ->orderBy('ve.id');

        $page = $query->paginate((int) ($validated['per_page'] ?? 50));
        $page->getCollection()->transform(fn (object $row): array => $this->timesheetRow($row));

        $summaryRaw = DB::table('worktime_entries as w')
            ->joinSub(
                $visibility->visibleFor($request->user(), $company),
                'sv',
                'sv.id',
                '=',
                'w.employee_id',
            )
            ->whereBetween('w.work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(DISTINCT w.employee_id) as employees_with_entries')
            ->selectRaw('SUM(w.planned_minutes) as planned_minutes')
            ->selectRaw('SUM(w.worked_minutes) as worked_minutes')
            ->selectRaw('SUM(w.absence_minutes) as absence_minutes')
            ->selectRaw('SUM(w.overtime_minutes) as overtime_minutes')
            ->first();

        $employeesWithEntries = (int) ($summaryRaw->employees_with_entries ?? 0);
        $summary = [
            'employees_with_entries' => $employeesWithEntries,
            'planned_minutes' => $employeesWithEntries > 0 ? $this->nullableInt($summaryRaw->planned_minutes) : null,
            'worked_minutes' => $employeesWithEntries > 0 ? $this->nullableInt($summaryRaw->worked_minutes) : null,
            'absence_minutes' => $employeesWithEntries > 0 ? $this->nullableInt($summaryRaw->absence_minutes) : null,
            'overtime_minutes' => $employeesWithEntries > 0 ? $this->nullableInt($summaryRaw->overtime_minutes) : null,
        ];

        $this->auditRead($audit, $request, $company, 'timesheet', $validated['month'], count($page->items()));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'month' => $validated['month'],
                'summary' => $summary,
                'worktime_writes_enabled' => (bool) config('hrm.worktime_writes_enabled', false),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function schedules(
        Request $request,
        string $company,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $asOf = (string) ($validated['as_of'] ?? now()->toDateString());

        $rows = DB::query()
            ->fromSub($visibility->visibleFor($request->user(), $company), 've')
            ->join('employee_work_schedules as ews', 'ews.employee_id', '=', 've.id')
            ->join('work_schedules as ws', function (JoinClause $join) use ($company): void {
                $join->on('ws.id', '=', 'ews.work_schedule_id')
                    ->where('ws.company_id', '=', $company);
            })
            ->where('ews.valid_from', '<=', $asOf)
            ->where(function (Builder $dates) use ($asOf): void {
                $dates->whereNull('ews.valid_to')->orWhere('ews.valid_to', '>=', $asOf);
            })
            ->select(
                'ws.id',
                'ws.code',
                'ws.name',
                'ws.schedule_type',
                'ws.work_time_label',
                'ws.weekly_minutes',
            )
            ->selectRaw('COUNT(DISTINCT ve.id) as employees')
            ->groupBy(
                'ws.id',
                'ws.code',
                'ws.name',
                'ws.schedule_type',
                'ws.work_time_label',
                'ws.weekly_minutes',
            )
            ->orderBy('ws.name')
            ->orderBy('ws.id')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'schedule_type' => $row->schedule_type,
                'work_time_label' => $row->work_time_label,
                'weekly_minutes' => $row->weekly_minutes !== null ? (int) $row->weekly_minutes : null,
                'employees' => (int) $row->employees,
            ])
            ->values();

        $this->auditRead($audit, $request, $company, 'schedules', $asOf, $rows->count());

        return response()->json([
            'data' => $rows,
            'meta' => [
                'as_of' => $asOf,
                'total' => $rows->count(),
                'worktime_writes_enabled' => (bool) config('hrm.worktime_writes_enabled', false),
            ],
        ]);
    }

    public function calendar(
        Request $request,
        string $company,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);
        [$from, $to] = $this->monthBounds($validated['month']);

        // EmployeeVisibility performs the same company-role authorization used by
        // employee-bound worktime reads. Calendar content is company scoped.
        $visibility->visibleFor($request->user(), $company);

        $rows = DB::table('production_calendar_days')
            ->where('company_id', $company)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->select('id', 'work_date', 'day_type', 'planned_minutes', 'label', 'revision')
            ->orderBy('work_date')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => $row->id,
                'date' => $row->work_date,
                'day_type' => $row->day_type,
                'planned_minutes' => $row->planned_minutes !== null ? (int) $row->planned_minutes : null,
                'label' => $row->label,
                'revision' => (int) $row->revision,
            ])
            ->values();

        $this->auditRead($audit, $request, $company, 'calendar', $validated['month'], $rows->count());

        return response()->json([
            'data' => $rows,
            'meta' => [
                'month' => $validated['month'],
                'recorded_days' => $rows->count(),
                'expected_days' => $from->daysInMonth,
                'calendar_complete' => $rows->count() === $from->daysInMonth,
                'worktime_writes_enabled' => (bool) config('hrm.worktime_writes_enabled', false),
            ],
        ]);
    }

    public function absences(
        Request $request,
        string $company,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        [$from, $to] = $this->monthBounds($validated['month']);

        $query = DB::table('employee_absences as a')
            ->joinSub(
                $visibility->visibleFor($request->user(), $company),
                've',
                've.id',
                '=',
                'a.employee_id',
            )
            ->where('a.started_on', '<=', $to->toDateString())
            ->where('a.ended_on', '>=', $from->toDateString())
            ->select(
                'a.id',
                'a.employee_id',
                'a.absence_type',
                'a.reason',
                'a.started_on',
                'a.ended_on',
                'a.absence_minutes',
                'a.status',
                'a.revision',
                've.personnel_number',
                've.last_name',
                've.first_name',
                've.middle_name',
            )
            ->selectSub($this->absenceDepartmentSubquery($company), 'department_name')
            ->orderBy('a.started_on')
            ->orderBy('ve.last_name')
            ->orderBy('ve.first_name')
            ->orderBy('a.id');

        $page = $query->paginate((int) ($validated['per_page'] ?? 50));
        $page->getCollection()->transform(static function (object $row): array {
            $started = CarbonImmutable::parse($row->started_on);
            $ended = CarbonImmutable::parse($row->ended_on);

            return [
                'id' => $row->id,
                'employee_id' => $row->employee_id,
                'personnel_number' => $row->personnel_number,
                'last_name' => $row->last_name,
                'first_name' => $row->first_name,
                'middle_name' => $row->middle_name,
                'department_name' => $row->department_name,
                'absence_type' => $row->absence_type,
                'reason' => $row->reason,
                'started_on' => $row->started_on,
                'ended_on' => $row->ended_on,
                'days' => $started->diffInDays($ended) + 1,
                'absence_minutes' => $row->absence_minutes !== null ? (int) $row->absence_minutes : null,
                'status' => $row->status,
                'revision' => (int) $row->revision,
            ];
        });

        $this->auditRead($audit, $request, $company, 'absences', $validated['month'], count($page->items()));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'month' => $validated['month'],
                'worktime_writes_enabled' => (bool) config('hrm.worktime_writes_enabled', false),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    private function monthBounds(string $month): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();

        return [$from, $from->endOfMonth()];
    }

    private function departmentNameSubquery(string $company, string $asOf): Builder
    {
        return DB::table('employee_assignments as current_a')
            ->join('departments as current_d', 'current_d.id', '=', 'current_a.department_id')
            ->select('current_d.name')
            ->whereColumn('current_a.employee_id', 've.id')
            ->where('current_d.company_id', $company)
            ->where('current_a.valid_from', '<=', $asOf)
            ->where(function (Builder $dates) use ($asOf): void {
                $dates->whereNull('current_a.valid_to')->orWhere('current_a.valid_to', '>=', $asOf);
            })
            ->orderByDesc('current_a.valid_from')
            ->orderBy('current_a.id')
            ->limit(1);
    }

    private function positionNameSubquery(string $company, string $asOf): Builder
    {
        return DB::table('employee_assignments as current_a')
            ->join('positions as current_p', 'current_p.id', '=', 'current_a.position_id')
            ->select('current_p.name')
            ->whereColumn('current_a.employee_id', 've.id')
            ->where('current_p.company_id', $company)
            ->where('current_a.valid_from', '<=', $asOf)
            ->where(function (Builder $dates) use ($asOf): void {
                $dates->whereNull('current_a.valid_to')->orWhere('current_a.valid_to', '>=', $asOf);
            })
            ->orderByDesc('current_a.valid_from')
            ->orderBy('current_a.id')
            ->limit(1);
    }

    private function scheduleNameSubquery(string $company, string $asOf): Builder
    {
        return DB::table('employee_work_schedules as current_s')
            ->join('work_schedules as current_ws', 'current_ws.id', '=', 'current_s.work_schedule_id')
            ->select('current_ws.name')
            ->whereColumn('current_s.employee_id', 've.id')
            ->where('current_ws.company_id', $company)
            ->where('current_s.valid_from', '<=', $asOf)
            ->where(function (Builder $dates) use ($asOf): void {
                $dates->whereNull('current_s.valid_to')->orWhere('current_s.valid_to', '>=', $asOf);
            })
            ->orderByDesc('current_s.valid_from')
            ->orderBy('current_s.id')
            ->limit(1);
    }

    private function absenceDepartmentSubquery(string $company): Builder
    {
        return DB::table('employee_assignments as absence_a')
            ->join('departments as absence_d', 'absence_d.id', '=', 'absence_a.department_id')
            ->select('absence_d.name')
            ->whereColumn('absence_a.employee_id', 'a.employee_id')
            ->where('absence_d.company_id', $company)
            ->whereColumn('absence_a.valid_from', '<=', 'a.started_on')
            ->where(function (Builder $dates): void {
                $dates->whereNull('absence_a.valid_to')->orWhereColumn('absence_a.valid_to', '>=', 'a.started_on');
            })
            ->orderByDesc('absence_a.valid_from')
            ->orderBy('absence_a.id')
            ->limit(1);
    }

    private function timesheetRow(object $row): array
    {
        $entries = (int) $row->entry_count;
        $planned = $entries > 0 ? $this->nullableInt($row->planned_minutes) : null;
        $worked = $entries > 0 ? $this->nullableInt($row->worked_minutes) : null;
        $absence = $entries > 0 ? $this->nullableInt($row->absence_minutes) : null;
        $overtime = $entries > 0 ? $this->nullableInt($row->overtime_minutes) : null;

        return [
            'employee_id' => $row->employee_id,
            'personnel_number' => $row->personnel_number,
            'last_name' => $row->last_name,
            'first_name' => $row->first_name,
            'middle_name' => $row->middle_name,
            'department_name' => $row->department_name,
            'position_name' => $row->position_name,
            'schedule_name' => $row->schedule_name,
            'days_recorded' => $entries,
            'planned_minutes' => $planned,
            'worked_minutes' => $worked,
            'absence_minutes' => $absence,
            'overtime_minutes' => $overtime,
            'status' => $entries === 0
                ? 'missing'
                : (((int) ($absence ?? 0) > 0 || (int) ($overtime ?? 0) > 0) ? 'attention' : 'complete'),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function auditRead(
        HrAuditWriter $audit,
        Request $request,
        string $company,
        string $section,
        string $period,
        int $returnedCount,
    ): void {
        $audit->record($request->user(), $company, HrAuditAction::WorktimeRecordsListed, null, [
            'section' => $section,
            'period' => $period,
            'returned_count' => $returnedCount,
        ]);
    }
}
