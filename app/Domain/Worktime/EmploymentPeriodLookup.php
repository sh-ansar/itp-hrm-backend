<?php

namespace App\Domain\Worktime;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolve immutable as-of employment ranges once per read/write batch.
 * A termination date is the inclusive last working day; a transfer closes
 * the previous assignment on the preceding date.
 */
final class EmploymentPeriodLookup
{
    /** @param array<int, string> $employeeIds
     *  @return Collection<string, Collection<int, object>>
     */
    public function forEmployees(string $companyId, array $employeeIds, string $from, string $to): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return DB::table('employee_assignments as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->where('e.company_id', $companyId)
            ->whereIn('a.employee_id', $employeeIds)
            ->where('a.valid_from', '<=', $to)
            ->where(static function ($query) use ($from): void {
                $query->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $from);
            })
            ->orderBy('a.valid_from')
            ->get(['a.employee_id', 'a.valid_from', 'a.valid_to'])
            ->groupBy('employee_id');
    }

    public static function isEmployed(Collection $periods, string $employeeId, string $day): bool
    {
        return ($periods->get($employeeId) ?? collect())->contains(
            static fn (object $period): bool => (string) $period->valid_from <= $day
                && ($period->valid_to === null || (string) $period->valid_to >= $day)
        );
    }
}
