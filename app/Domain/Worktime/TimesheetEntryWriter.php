<?php

namespace App\Domain\Worktime;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TimesheetEntryWriter
{
    public function __construct(private readonly HrAuditWriter $audit) {}

    public function upsert(User $actor, string $companyId, string $employeeId, string $month, array $days): array
    {
        return DB::transaction(function () use ($actor, $companyId, $employeeId, $month, $days): array {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->firstOrFail();

            // Recorded approvals are immutable, even if the rollout flag is disabled later.
            $status = DB::table('worktime_periods')->where('company_id', $companyId)
                ->where('month', $month)->value('status');
            abort_unless($status === null || $status === 'draft', 409,
                'The timesheet period is submitted or approved and cannot be edited.');

            $employeeExists = DB::table('employees')
                ->where('id', $employeeId)
                ->where('company_id', $companyId)
                ->exists();
            abort_unless($employeeExists, 404);

            $dates = array_column($days, 'date');
            foreach ($days as $index => $day) {
                // Planned future days belong to the calendar, not actual attendance.
                // Allow a null/zero-only correction so incorrect legacy future values
                // can be cleared, but never permit future worked/absence/overtime.
                if ($day['date'] > now()->toDateString()
                    && ((int) ($day['worked_minutes'] ?? 0) > 0
                        || (int) ($day['absence_minutes'] ?? 0) > 0
                        || (int) ($day['overtime_minutes'] ?? 0) > 0)) {
                    throw ValidationException::withMessages([
                        "days.{$index}.date" => 'Future dates cannot contain actual timesheet values.',
                    ]);
                }
            }
            $calendar = DB::table('production_calendar_days')
                ->where('company_id', $companyId)
                ->whereIn('work_date', $dates)
                ->get(['work_date', 'planned_minutes', 'day_type'])
                ->keyBy('work_date');

            if ($calendar->count() !== count($dates)) {
                throw ValidationException::withMessages([
                    'days' => 'Complete the production calendar for every edited timesheet date first.',
                ]);
            }

            $plannedDays = DB::table('employee_work_plans')->where('company_id', $companyId)
                ->where('employee_id', $employeeId)->whereIn('work_date', $dates)
                ->get(['work_date', 'planned_minutes'])->keyBy('work_date');

            $existing = DB::table('worktime_entries')
                ->where('employee_id', $employeeId)
                ->whereIn('work_date', $dates)
                ->get()
                ->keyBy('work_date');

            foreach ($days as $index => $day) {
                $current = $existing->get($day['date']);
                $expected = $day['revision'];
                if (($current === null && $expected !== null)
                    || ($current !== null && ($expected === null || (int) $current->revision !== $expected))) {
                    abort(409, 'Timesheet changed since it was loaded. Refresh before saving.');
                }

                $calendarDay = $calendar->get($day['date']);
                $planned = $plannedDays->get($day['date'])?->planned_minutes
                    ?? ($calendarDay->planned_minutes !== null ? (int) $calendarDay->planned_minutes : 0);
                $absence = (int) ($day['absence_minutes'] ?? 0);
                if ($absence > $planned && $planned > 0) {
                    throw ValidationException::withMessages([
                        "days.{$index}.absence_minutes" => 'Absence minutes cannot exceed planned minutes.',
                    ]);
                }
                if ($planned === 0 && $absence > 0) {
                    throw ValidationException::withMessages([
                        "days.{$index}.absence_minutes" => 'Absence cannot be recorded on a non-working day.',
                    ]);
                }
            }

            $created = 0;
            $updated = 0;
            foreach ($days as $day) {
                $current = $existing->get($day['date']);
                $calendarDay = $calendar->get($day['date']);
                $values = [
                    'planned_minutes' => $plannedDays->get($day['date'])?->planned_minutes
                        ?? ($calendarDay->planned_minutes !== null ? (int) $calendarDay->planned_minutes : 0),
                    'worked_minutes' => $day['worked_minutes'],
                    'absence_minutes' => $day['absence_minutes'],
                    'overtime_minutes' => $day['overtime_minutes'],
                    'updated_at' => now(),
                ];

                if ($current === null) {
                    DB::table('worktime_entries')->insert([
                        'id' => (string) Str::uuid(),
                        'employee_id' => $employeeId,
                        'work_date' => $day['date'],
                        ...$values,
                        'revision' => 1,
                        'created_at' => now(),
                    ]);
                    $created++;
                    continue;
                }

                $count = DB::table('worktime_entries')
                    ->where('id', $current->id)
                    ->where('revision', (int) $current->revision)
                    ->update([...$values, 'revision' => (int) $current->revision + 1]);
                abort_unless($count === 1, 409, 'Timesheet was modified by another user.');
                $updated++;
            }

            $this->audit->record($actor, $companyId, HrAuditAction::WorktimeEntriesUpserted, null, [
                'employee_id' => $employeeId,
                'month' => $month,
                'dates' => $dates,
                'created_count' => $created,
                'updated_count' => $updated,
            ]);

            return [
                'data' => $this->days($companyId, $employeeId, $month),
                'meta' => ['month' => $month, 'created_count' => $created, 'updated_count' => $updated],
            ];
        }, 3);
    }

    public function days(string $companyId, string $employeeId, string $month): array
    {
        $employeeExists = DB::table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $companyId)
            ->exists();
        abort_unless($employeeExists, 404);

        $from = $month.'-01';
        $to = date('Y-m-t', strtotime($from));

        $entries = DB::table('worktime_entries')
            ->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$from, $to])
            ->get()
            ->keyBy('work_date');

        $plannedDays = DB::table('employee_work_plans')->where('company_id', $companyId)
            ->where('employee_id', $employeeId)->whereBetween('work_date', [$from, $to])
            ->get(['work_date', 'planned_minutes'])->keyBy('work_date');

        return DB::table('production_calendar_days')
            ->where('company_id', $companyId)
            ->whereBetween('work_date', [$from, $to])
            ->select('work_date', 'day_type', 'planned_minutes', 'label')
            ->orderBy('work_date')
            ->get()
            ->map(static function (object $calendar) use ($entries, $plannedDays): array {
                $entry = $entries->get($calendar->work_date);
                return [
                    'date' => $calendar->work_date,
                    'day_type' => $calendar->day_type,
                    'label' => $calendar->label,
                    'planned_minutes' => $plannedDays->get($calendar->work_date)?->planned_minutes
                        ?? ($calendar->planned_minutes !== null ? (int) $calendar->planned_minutes : 0),
                    'worked_minutes' => $entry?->worked_minutes !== null ? (int) $entry->worked_minutes : null,
                    'absence_minutes' => $entry?->absence_minutes !== null ? (int) $entry->absence_minutes : null,
                    'overtime_minutes' => $entry?->overtime_minutes !== null ? (int) $entry->overtime_minutes : null,
                    'revision' => $entry !== null ? (int) $entry->revision : null,
                ];
            })
            ->all();
    }
}
