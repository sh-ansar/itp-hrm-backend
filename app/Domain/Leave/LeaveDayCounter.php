<?php

namespace App\Domain\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Calculates leave days using the company's explicit leave policy, never client input. */
final class LeaveDayCounter
{
    public function count(string $company, string $typeId, string $from, string $to): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);
        $span = (int) $start->diffInDays($end) + 1;

        if ($start->greaterThan($end) || $span < 1 || $span > 3660) {
            throw ValidationException::withMessages([
                'planned_ended_on' => 'Leave must be 1 to 3660 consecutive calendar days.',
            ]);
        }

        $type = DB::table('leave_types')
            ->where('company_id', $company)
            ->where('id', $typeId)
            ->first(['balance_method', 'category']);
        if ($type === null) {
            throw ValidationException::withMessages(['leave_type_id' => 'Leave type does not belong to this company.']);
        }

        if ($type->category === 'ordinary' && $span > 366) {
            throw ValidationException::withMessages(['planned_ended_on' => 'Ordinary leave may not exceed 366 days.']);
        }
        if ($type->category !== 'ordinary' && $type->balance_method !== 'calendar_days') {
            throw ValidationException::withMessages(['leave_type_id' => 'Protected leave requires calendar-day counting.']);
        }
        if ($type->balance_method === 'calendar_days') {
            return $span;
        }
        if ($type->balance_method !== 'working_days') {
            throw ValidationException::withMessages(['leave_type_id' => 'Leave type requires a supported balance policy.']);
        }

        $calendar = DB::table('production_calendar_days')
            ->where('company_id', $company)
            ->whereBetween('work_date', [$from, $to])
            ->get(['day_type']);
        if ($calendar->count() !== $span) {
            throw ValidationException::withMessages([
                'planned_started_on' => 'Complete the production calendar for every leave date before calculating working days.',
            ]);
        }

        $days = $calendar->filter(static fn (object $day): bool =>
            in_array($day->day_type, ['workday', 'shortened'], true))->count();
        if ($days < 1) {
            throw ValidationException::withMessages(['planned_started_on' => 'Working-day leave must contain at least one workday.']);
        }

        return $days;
    }
}
