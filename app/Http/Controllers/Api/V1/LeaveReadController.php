<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\EmployeeVisibility;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaveReadController extends Controller
{
    public function __invoke(
        Request $request,
        string $company,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
        WorkforceAuthorization $permissions,
    ): JsonResponse {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $year = (int) $validated['year'];
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay()->toDateString();
        $to = CarbonImmutable::create($year, 12, 31)->endOfDay()->toDateString();
        $today = now()->toDateString();

        $base = DB::table('employee_leaves as l')
            ->joinSub(
                $visibility->visibleFor($request->user(), $company),
                've',
                've.id',
                '=',
                'l.employee_id',
            )
            ->join('leave_types as lt', function (JoinClause $join) use ($company): void {
                $join->on('lt.id', '=', 'l.leave_type_id')
                    ->where('lt.company_id', '=', $company);
            })
            ->where(function (Builder $period) use ($from, $to): void {
                $period
                    ->where(function (Builder $planned) use ($from, $to): void {
                        $planned->where('l.planned_started_on', '<=', $to)
                            ->where('l.planned_ended_on', '>=', $from);
                    })
                    ->orWhere(function (Builder $actual) use ($from, $to): void {
                        $actual->whereNotNull('l.actual_started_on')
                            ->where('l.actual_started_on', '<=', $to)
                            ->where(function (Builder $actualEnd) use ($from): void {
                                $actualEnd->whereNull('l.actual_ended_on')
                                    ->orWhere('l.actual_ended_on', '>=', $from);
                            });
                    });
            });

        $summaryRaw = (clone $base)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT l.employee_id) as employees')
            ->selectRaw('COALESCE(SUM(COALESCE(l.actual_days, l.planned_days)), 0) as days')
            ->selectRaw(
                'SUM(CASE
                    WHEN l.actual_started_on IS NOT NULL
                     AND l.actual_started_on <= ?
                     AND (l.actual_ended_on IS NULL OR l.actual_ended_on >= ?)
                    THEN 1 ELSE 0 END) as ongoing',
                [$today, $today],
            )
            ->selectRaw(
                'SUM(CASE
                    WHEN l.actual_started_on IS NOT NULL
                     AND l.actual_ended_on IS NOT NULL
                     AND l.actual_ended_on < ?
                    THEN 1 ELSE 0 END) as completed',
                [$today],
            )
            ->selectRaw(
                'SUM(CASE
                    WHEN (l.actual_started_on IS NOT NULL AND l.actual_started_on <> l.planned_started_on)
                      OR (l.actual_ended_on IS NOT NULL AND l.actual_ended_on <> l.planned_ended_on)
                    THEN 1 ELSE 0 END) as shifted'
            )
            ->first();

        $total = (int) ($summaryRaw->total ?? 0);
        $ongoing = (int) ($summaryRaw->ongoing ?? 0);
        $completed = (int) ($summaryRaw->completed ?? 0);

        $query = (clone $base)
            ->select(
                'l.id',
                'l.employee_id',
                'l.leave_type_id',
                'l.planned_started_on',
                'l.planned_ended_on',
                'l.planned_days',
                'l.actual_started_on',
                'l.actual_ended_on',
                'l.actual_days',
                'l.revision',
                'lt.code as leave_type_code',
                'lt.name as leave_type_name',
                'lt.annual_days',
                'lt.balance_method',
                've.personnel_number',
                've.last_name',
                've.first_name',
                've.middle_name',
            )
            ->selectSub($this->departmentNameSubquery($company), 'department_name')
            ->selectSub($this->positionNameSubquery($company), 'position_name')
            ->orderBy('l.planned_started_on')
            ->orderBy('ve.last_name')
            ->orderBy('ve.first_name')
            ->orderBy('l.id');

        $page = $query->paginate((int) ($validated['per_page'] ?? 50));
        $page->getCollection()->transform(
            fn (object $row): array => $this->serializeLeave($row, $today),
        );

        $audit->record($request->user(), $company, HrAuditAction::LeaveRecordsListed, null, [
            'year' => $year,
            'returned_count' => count($page->items()),
        ]);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'year' => $year,
                'summary' => [
                    'total' => $total,
                    'employees' => (int) ($summaryRaw->employees ?? 0),
                    'days' => (int) ($summaryRaw->days ?? 0),
                    'planned' => max($total - $ongoing - $completed, 0),
                    'ongoing' => $ongoing,
                    'completed' => $completed,
                    'shifted' => (int) ($summaryRaw->shifted ?? 0),
                ],
                'leave_writes_enabled' => $permissions->canManageLeaveRequests($request->user(), $company),
                'can_manage_leave_requests' => $permissions->canManageLeaveRequests($request->user(), $company),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    private function serializeLeave(object $row, string $today): array
    {
        $actualStarted = $row->actual_started_on;
        $actualEnded = $row->actual_ended_on;

        $status = 'planned';
        if ($actualStarted !== null && $actualStarted <= $today
            && ($actualEnded === null || $actualEnded >= $today)) {
            $status = 'ongoing';
        } elseif ($actualStarted !== null && $actualEnded !== null && $actualEnded < $today) {
            $status = 'completed';
        }

        $shifted = ($actualStarted !== null && $actualStarted !== $row->planned_started_on)
            || ($actualEnded !== null && $actualEnded !== $row->planned_ended_on);

        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'personnel_number' => $row->personnel_number,
            'last_name' => $row->last_name,
            'first_name' => $row->first_name,
            'middle_name' => $row->middle_name,
            'department_name' => $row->department_name,
            'position_name' => $row->position_name,
            'leave_type_id' => $row->leave_type_id,
            'leave_type_code' => $row->leave_type_code,
            'leave_type_name' => $row->leave_type_name,
            'annual_days' => $row->annual_days !== null ? (int) $row->annual_days : null,
            'balance_method' => $row->balance_method,
            'planned_started_on' => $row->planned_started_on,
            'planned_ended_on' => $row->planned_ended_on,
            'planned_days' => (int) $row->planned_days,
            'actual_started_on' => $actualStarted,
            'actual_ended_on' => $actualEnded,
            'actual_days' => $row->actual_days !== null ? (int) $row->actual_days : null,
            'started_on' => $actualStarted ?? $row->planned_started_on,
            'ended_on' => $actualEnded ?? $row->planned_ended_on,
            'days' => (int) ($row->actual_days ?? $row->planned_days),
            'status' => $status,
            'schedule_state' => $shifted ? 'shifted' : 'on_schedule',
            'revision' => (int) $row->revision,
        ];
    }

    private function departmentNameSubquery(string $company): Builder
    {
        return DB::table('employee_assignments as leave_a')
            ->join('departments as leave_d', 'leave_d.id', '=', 'leave_a.department_id')
            ->select('leave_d.name')
            ->whereColumn('leave_a.employee_id', 'l.employee_id')
            ->where('leave_d.company_id', $company)
            ->whereColumn('leave_a.valid_from', '<=', 'l.planned_started_on')
            ->where(function (Builder $dates): void {
                $dates->whereNull('leave_a.valid_to')
                    ->orWhereColumn('leave_a.valid_to', '>=', 'l.planned_started_on');
            })
            ->orderByDesc('leave_a.valid_from')
            ->orderBy('leave_a.id')
            ->limit(1);
    }

    private function positionNameSubquery(string $company): Builder
    {
        return DB::table('employee_assignments as leave_a')
            ->join('positions as leave_p', 'leave_p.id', '=', 'leave_a.position_id')
            ->select('leave_p.name')
            ->whereColumn('leave_a.employee_id', 'l.employee_id')
            ->where('leave_p.company_id', $company)
            ->whereColumn('leave_a.valid_from', '<=', 'l.planned_started_on')
            ->where(function (Builder $dates): void {
                $dates->whereNull('leave_a.valid_to')
                    ->orWhereColumn('leave_a.valid_to', '>=', 'l.planned_started_on');
            })
            ->orderByDesc('leave_a.valid_from')
            ->orderBy('leave_a.id')
            ->limit(1);
    }
}
