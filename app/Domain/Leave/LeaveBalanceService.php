<?php

namespace App\Domain\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee/year balances, based ONLY on explicit approved grants and booked
 * employee_leaves. Protected medical/parental leave is never a quota.
 *
 * No monthly statutory accrual, employment-date assumption or auto carryover.
 * Planned/approved periods reserve days; actual attendance is a separate flow.
 */
final class LeaveBalanceService
{
    public function forEmployee(string $company, string $employee, int $year): array
    {
        $this->assertEmployee($company, $employee);
        $types = DB::table('leave_types')
            ->where('company_id', $company)->where('category', 'ordinary')
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'annual_days', 'balance_method']);
        $periods = DB::table('leave_balance_periods')
            ->where('company_id', $company)->where('employee_id', $employee)
            ->where('year', $year)->get()->keyBy('leave_type_id');

        return $types->map(fn (object $type): array => $this->snapshot(
            $company, $employee, $type, $year, $periods->get($type->id),
        ))->all();
    }

    public function snapshot(string $company, string $employee, object $type, int $year, ?object $period = null): array
    {
        $this->checkYear($year);
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $bookedPeriods = DB::table('employee_leaves')->whereNull('cancelled_at')
            ->where('employee_id', $employee)->where('leave_type_id', $type->id)
            ->where(function ($range) use ($from, $to): void {
                $range->where(function ($planned) use ($from, $to): void {
                    $planned->where('planned_started_on', '<=', $to)
                        ->where('planned_ended_on', '>=', $from);
                })->orWhere(function ($actual) use ($from, $to): void {
                    $actual->whereNotNull('actual_started_on')
                        ->where('actual_started_on', '<=', $to)
                        ->where('actual_ended_on', '>=', $from);
                });
            })
            ->get(['planned_started_on', 'planned_ended_on', 'planned_days',
                'actual_started_on', 'actual_ended_on', 'actual_days']);
        $pendingPeriods = DB::table('leave_requests')
            ->where('company_id', $company)->where('employee_id', $employee)
            ->where('leave_type_id', $type->id)->where('status', 'submitted')
            ->where('planned_started_on', '<=', $to)->where('planned_ended_on', '>=', $from)
            ->get(['planned_started_on', 'planned_ended_on', 'planned_days']);

        $booked = $this->totalInYear($company, (string) $type->balance_method, $year, $bookedPeriods);
        $pending = $this->totalInYear($company, (string) $type->balance_method, $year, $pendingPeriods);
        $configured = $period !== null;
        $closed = $configured && $period->closed_at !== null;
        $policyValid = in_array($type->balance_method, ['calendar_days', 'working_days'], true);
        // Approved plan remains in booked_days. If verified actual absence
        // exceeds it, also reserve those excess days before new approvals.
        // Missing or future actuals never become an invented completed fact.
        $actualOverPlan = $policyValid ? 0 : null;
        if ($policyValid) {
            $today = now()->toDateString();
            foreach ($bookedPeriods as $record) {
                if ($record->actual_started_on === null || $record->actual_ended_on === null
                    || $record->actual_days === null || $record->actual_ended_on > $today) continue;
                $plannedSlice = $this->sliceDays($company, (string) $type->balance_method, $year,
                    (string) $record->planned_started_on, (string) $record->planned_ended_on,
                    (int) $record->planned_days);
                $actualSlice = $this->sliceDays($company, (string) $type->balance_method, $year,
                    (string) $record->actual_started_on, (string) $record->actual_ended_on,
                    (int) $record->actual_days);
                if ($plannedSlice === null || $actualSlice === null) {
                    $actualOverPlan = null;
                    break;
                }
                $actualOverPlan += max(0, $actualSlice - $plannedSlice);
            }
        }
        // "Available" means newly bookable days. A closed year cannot be
        // booked again, even though its historical closing snapshot stays auditable.
        $available = $closed ? 0 : ($configured && $policyValid && $booked !== null
            && $pending !== null && $actualOverPlan !== null
            ? (int) $period->granted_days + (int) $period->carried_days
                + (int) $period->adjustment_days - $booked - $actualOverPlan
            : null);
        $carriedOut = $closed ? (int) (DB::table('leave_balance_periods')
            ->where('carry_from_period_id', $period->id)->value('carried_days') ?? 0) : null;

        return [
            'id' => $period?->id,
            'employee_id' => $employee,
            'leave_type_id' => $type->id,
            'leave_type_name' => $type->name,
            'year' => $year,
            'reference_annual_days' => $type->annual_days !== null ? (int) $type->annual_days : null,
            'balance_method' => $type->balance_method,
            'granted_days' => $configured ? (int) $period->granted_days : null,
            'carried_days' => $configured ? (int) $period->carried_days : null,
            'adjustment_days' => $configured ? (int) $period->adjustment_days : null,
            'booked_days' => $booked,
            'actual_over_plan_days' => $actualOverPlan,
            'pending_days' => $pending,
            'available_days' => $available,
            // Snapshot at the moment of closure (not new spendable days).
            'closing_available_days' => $closed ? (int) $period->closing_available_days : null,
            'carried_out_days' => $carriedOut,
            'state' => ! $configured ? 'unconfigured'
                : ($closed ? 'closed'
                    : (! $policyValid ? 'policy_missing'
                        : ($booked === null || $pending === null || $actualOverPlan === null ? 'calendar_incomplete' : 'open'))),
            'revision' => $configured ? (int) $period->revision : null,
            'carry_from_period_id' => $period?->carry_from_period_id,
            'closed_at' => $period?->closed_at,
        ];
    }

    /**
     * Actual use is deliberately separate from approved reservations. A missing
     * actual record on an elapsed booking is UNKNOWN, never a fabricated zero.
     * The entitlement ledger retains its existing booking/approval semantics.
     */
    public function actualUsage(string $company, string $employee, object $type, int $year, array $balance): array
    {
        $this->checkYear($year);
        $first = sprintf('%04d-01-01', $year);
        $last = sprintf('%04d-12-31', $year);
        $today = now()->toDateString();
        $records = DB::table('employee_leaves')
            ->where('employee_id', $employee)->where('leave_type_id', $type->id)
            ->whereNull('cancelled_at')
            ->where(function ($q) use ($first, $last): void {
                $q->where(function ($period) use ($first, $last): void {
                    $period->where('planned_started_on', '<=', $last)
                        ->where('planned_ended_on', '>=', $first);
                })->orWhere(function ($period) use ($first, $last): void {
                    $period->whereNotNull('actual_started_on')->where('actual_started_on', '<=', $last)
                        ->where('actual_ended_on', '>=', $first);
                });
            })->get(['planned_started_on','planned_ended_on',
                'actual_started_on','actual_ended_on','actual_days','actual_review_state']);
        $actual = 0;
        $unverified = 0;
        foreach ($records as $record) {
            if ($record->actual_review_state === 'not_taken') continue;
            if ($record->actual_started_on === null || $record->actual_ended_on === null
                || $record->actual_days === null) {
                if ($record->planned_started_on <= $today && $record->planned_ended_on <= $today) {
                    $unverified++;
                }
                continue;
            }
            // A future actual date is not verified usage.
            if ($record->actual_ended_on > $today) {
                $unverified++;
                continue;
            }
            $days = $this->sliceDays($company, (string) $type->balance_method, $year,
                $record->actual_started_on, $record->actual_ended_on, (int) $record->actual_days);
            if ($days === null) {
                $unverified++;
                continue;
            }
            $actual += $days;
        }
        $entitlement = $balance['granted_days'] === null ? null
            : $balance['granted_days'] + $balance['carried_days'] + $balance['adjustment_days'];
        return [
            'actual_verified_days' => $unverified === 0 ? $actual : null,
            'actual_unverified_records' => $unverified,
            'unspent_entitlement_days' => ($entitlement !== null && $unverified === 0)
                ? $entitlement - $actual : null,
        ];
    }

    /**
     * Approved bookings are counted from employee_leaves (including historical
     * imported leaves), not double-counted via published leave_requests.
     * In-year days use the approved immutable planned_days; cross-year working
     * slices need a complete production calendar or the result is UNKNOWN.
     */
    private function totalInYear(string $company, string $method, int $year, iterable $records): ?int
    {
        $total = 0;
        foreach ($records as $record) {
            $days = $this->sliceDays($company, $method, $year,
                (string) $record->planned_started_on, (string) $record->planned_ended_on,
                (int) $record->planned_days);
            if ($days === null) return null;
            $total += $days;
        }
        return $total;
    }

    public function sliceDays(string $company, string $method, int $year, string $start, string $end, int $wholeDays): ?int
    {
        $from = max($start, sprintf('%04d-01-01', $year));
        $to = min($end, sprintf('%04d-12-31', $year));
        if ($from > $to) return 0;
        if ($from === $start && $to === $end) return $wholeDays;
        if ($method === 'calendar_days') {
            return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        }
        if ($method !== 'working_days') return null;
        $days = DB::table('production_calendar_days')->where('company_id', $company)
            ->whereBetween('work_date', [$from, $to])->get(['day_type']);
        $span = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        if ($days->count() !== $span) return null;
        return $days->filter(static fn (object $day): bool =>
            in_array($day->day_type, ['workday', 'shortened'], true))->count();
    }

    /** Called inside the approval transaction under the existing company row lock. */
    public function assertApprovalHasBalance(string $company, object $leave): void
    {
        $type = DB::table('leave_types')->where('company_id', $company)
            ->where('id', $leave->leave_type_id)->first(['id', 'name', 'annual_days', 'balance_method', 'category']);
        abort_unless($type, 422);
        if ($type->category !== 'ordinary') return;

        $first = (int) substr($leave->planned_started_on, 0, 4);
        $last = (int) substr($leave->planned_ended_on, 0, 4);
        $years = range($first, $last);
        // Older installations have no seeded grants; activating enforcement
        // must be a separate deliberate company-wide release decision.
        $periods = DB::table('leave_balance_periods')
            ->where('company_id', $company)->where('employee_id', $leave->employee_id)
            ->where('leave_type_id', $type->id)->whereIn('year', $years)
            ->get()->keyBy('year');
        if (!config('hrm.leave_balance_enforcement_enabled', false) && $periods->isEmpty()) return;

        foreach ($years as $year) {
            $period = $periods->get($year);
            if ($period === null) {
                throw ValidationException::withMessages([
                    'leave_type_id' => 'An explicit leave entitlement period must be configured before approval.',
                ]);
            }
            abort_if($period->closed_at !== null, 409, 'The annual leave entitlement period is closed.');
            $current = $this->snapshot($company, $leave->employee_id, $type, $year, $period);
            $requested = $this->sliceDays($company, (string) $type->balance_method,
                $year, $leave->planned_started_on, $leave->planned_ended_on, (int) $leave->planned_days);
            if ($requested === null || $current['available_days'] === null) {
                throw ValidationException::withMessages([
                    'planned_started_on' => 'A complete calendar is required before confirming this year balance.',
                ]);
            }
            if ($requested > $current['available_days']) {
                throw ValidationException::withMessages([
                    'planned_started_on' => 'The confirmed leave days exceed the configured available balance.',
                ]);
            }
        }
    }

    public function period(string $company, string $id): object
    {
        return DB::table('leave_balance_periods')
            ->where('company_id', $company)->where('id', $id)->firstOrFail();
    }

    public function type(string $company, string $typeId): object
    {
        $type = DB::table('leave_types')->where('company_id', $company)
            ->where('id', $typeId)->where('category', 'ordinary')
            ->first(['id', 'name', 'annual_days', 'balance_method']);
        if (! $type) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'Only ordinary leave types belonging to this company have balances.',
            ]);
        }
        return $type;
    }

    public function assertEmployee(string $company, string $employee): void
    {
        abort_unless(DB::table('employees')->where('company_id', $company)->where('id', $employee)->exists(), 404);
    }

    public function checkYear(int $year): void
    {
        abort_unless($year >= 2000 && $year <= 2100, 422);
    }
}
