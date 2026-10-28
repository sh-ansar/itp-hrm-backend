<?php

namespace App\Domain\Worktime;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BulkTimesheetFill
{
    public function __construct(private readonly HrAuditWriter $audit) {}

    /**
     * Fills only missing worktime rows. Existing worktime, approved leave,
     * absences, weekends, holidays and dates without a valid assignment are
     * never overwritten. Preview and apply use the same eligibility rules.
     */
    public function run(
        User $actor, string $company, string $month, array $employeeIds, array $dates, bool $apply,
    ): array {
        $dates = array_values($dates);
        sort($dates);
        $from = $dates[0];
        $to = $dates[array_key_last($dates)];

        return DB::transaction(function () use ($actor, $company, $month, $employeeIds, $dates, $from, $to, $apply): array {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $status = DB::table('worktime_periods')->where('company_id', $company)
                ->where('month', $month)->value('status');
            $locked = (bool) config('hrm.worktime_period_approvals_enabled', false)
                && in_array($status, ['submitted', 'approved'], true);
            abort_unless(!($apply && $locked), 409, 'This timesheet period is locked.');

            $owned = DB::table('employees')->where('company_id', $company)
                ->whereIn('id', $employeeIds)->pluck('id')->all();
            if (count($owned) !== count($employeeIds)) {
                throw ValidationException::withMessages([
                    'employee_ids' => 'One or more employees do not belong to the selected company.',
                ]);
            }

            $calendar = DB::table('production_calendar_days')->where('company_id', $company)
                ->whereIn('work_date', $dates)->get(['work_date', 'day_type', 'planned_minutes'])
                ->keyBy('work_date');
            if ($calendar->count() !== count($dates)) {
                throw ValidationException::withMessages([
                    'dates' => 'Complete the company production calendar for all selected dates first.',
                ]);
            }

            $assignments = DB::table('employee_assignments')->whereIn('employee_id', $employeeIds)
                ->where('valid_from', '<=', $to)
                ->where(function ($query) use ($from): void {
                    $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from);
                })->get(['employee_id', 'valid_from', 'valid_to'])->groupBy('employee_id');

            $leaves = DB::table('employee_leaves')->whereIn('employee_id', $employeeIds)
                ->whereNull('cancelled_at')->where('planned_started_on', '<=', $to)
                ->where('planned_ended_on', '>=', $from)
                ->get(['employee_id', 'planned_started_on', 'planned_ended_on'])->groupBy('employee_id');

            $absences = DB::table('employee_absences')->whereIn('employee_id', $employeeIds)
                ->where('status', '<>', 'cancelled')->where('started_on', '<=', $to)
                ->where('ended_on', '>=', $from)
                ->get(['employee_id', 'started_on', 'ended_on'])->groupBy('employee_id');

            $existing = [];
            foreach (DB::table('worktime_entries')->whereIn('employee_id', $employeeIds)
                ->whereIn('work_date', $dates)->get(['employee_id', 'work_date']) as $row) {
                $existing[$row->employee_id.'|'.$row->work_date] = true;
            }

            $counts = [
                'candidate_count' => 0,
                'already_recorded' => 0,
                'outside_employment' => 0,
                'approved_leave' => 0,
                'recorded_absence' => 0,
                'nonworking_date' => 0,
            ];
            $rows = [];
            foreach ($employeeIds as $employeeId) {
                foreach ($dates as $date) {
                    $day = $calendar->get($date);
                    $planned = (int) ($day->planned_minutes ?? 0);
                    if (!in_array($day->day_type, ['workday', 'shortened'], true) || $planned <= 0) {
                        $counts['nonworking_date']++;
                        continue;
                    }
                    $active = ($assignments->get($employeeId) ?? collect())->contains(
                        static fn (object $a): bool => $a->valid_from <= $date
                            && ($a->valid_to === null || $a->valid_to >= $date)
                    );
                    if (!$active) {
                        $counts['outside_employment']++;
                        continue;
                    }
                    $leave = ($leaves->get($employeeId) ?? collect())->contains(
                        static fn (object $a): bool => $a->planned_started_on <= $date
                            && $a->planned_ended_on >= $date
                    );
                    if ($leave) {
                        $counts['approved_leave']++;
                        continue;
                    }
                    $absence = ($absences->get($employeeId) ?? collect())->contains(
                        static fn (object $a): bool => $a->started_on <= $date
                            && $a->ended_on >= $date
                    );
                    if ($absence) {
                        $counts['recorded_absence']++;
                        continue;
                    }
                    if (isset($existing[$employeeId.'|'.$date])) {
                        $counts['already_recorded']++;
                        continue;
                    }
                    $counts['candidate_count']++;
                    if (!$apply) continue;
                    $now = now();
                    $rows[] = [
                        'id' => (string) Str::uuid(), 'employee_id' => $employeeId,
                        'work_date' => $date, 'planned_minutes' => $planned,
                        'worked_minutes' => $planned, 'absence_minutes' => 0,
                        'overtime_minutes' => 0, 'revision' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }

            if ($apply && $rows !== []) {
                foreach (array_chunk($rows, 150) as $batch) {
                    DB::table('worktime_entries')->insert($batch);
                }
                $this->audit->record($actor, $company, HrAuditAction::WorktimeEntriesUpserted, null, [
                    'source' => 'bulk_fill_missing', 'month' => $month, 'employee_ids' => $employeeIds,
                    'dates' => $dates, 'created_count' => count($rows),
                ]);
            }

            return [
                'data' => $counts,
                'meta' => [
                    'month' => $month,
                    'employee_count' => count($employeeIds),
                    'date_count' => count($dates),
                    'mode' => $apply ? 'applied' : 'preview',
                    'created_count' => count($rows),
                    'period_locked' => $locked,
                ],
            ];
        }, 3);
    }
}
