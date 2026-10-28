<?php

namespace App\Domain\Worktime;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Combined absence register. Historical imported leave is a read-only projection:
 * no duplicate absence rows, artificial approval or balance mutations.
 */
final class AbsenceRegisterProjector
{
    public function page(
        string $company,
        Builder $visible,
        string $from,
        string $to,
        int $perPage,
        bool $protectedDetails,
    ): LengthAwarePaginator {
        $absences = DB::table('employee_absences as a')
            ->joinSub(clone $visible, 've', 've.id', '=', 'a.employee_id')
            ->where('a.started_on', '<=', $to)
            ->where('a.ended_on', '>=', $from)
            ->select(
                'a.id', 'a.employee_id', 've.personnel_number',
                've.last_name', 've.first_name', 've.middle_name',
                'a.absence_type', 'a.reason', 'a.started_on', 'a.ended_on',
                'a.absence_minutes', 'a.status', 'a.revision',
            )
            ->selectRaw("'absence' as source")
            ->selectRaw('NULL as category')
            ->selectRaw('NULL as workflow_approved')
            ->selectSub($this->department($company, 'a.employee_id', 'a.started_on'), 'department_name');

        $leaves = DB::table('employee_leaves as l')
            ->joinSub(clone $visible, 've', 've.id', '=', 'l.employee_id')
            ->join('leave_types as lt', function ($join) use ($company): void {
                $join->on('lt.id', '=', 'l.leave_type_id')
                    ->where('lt.company_id', '=', $company);
            })
            ->whereNull('l.cancelled_at')
            ->whereRaw('COALESCE(l.actual_started_on,l.planned_started_on) <= ?', [$to])
            ->whereRaw('COALESCE(l.actual_ended_on,l.planned_ended_on) >= ?', [$from])
            ->whereNotExists(function (Builder $q): void {
                // Prefer the explicit absence if there is any date overlap.
                // Never render the same employee absence twice.
                $q->selectRaw('1')
                    ->from('employee_absences as a')
                    ->whereColumn('a.employee_id', 'l.employee_id')
                    ->whereRaw('a.started_on <= COALESCE(l.actual_ended_on,l.planned_ended_on)')
                    ->whereRaw('a.ended_on >= COALESCE(l.actual_started_on,l.planned_started_on)');
            })
            ->select(
                'l.id', 'l.employee_id', 've.personnel_number',
                've.last_name', 've.first_name', 've.middle_name',
                'lt.code as absence_type', 'lt.name as reason',
            )
            ->selectRaw('COALESCE(l.actual_started_on,l.planned_started_on) as started_on')
            ->selectRaw('COALESCE(l.actual_ended_on,l.planned_ended_on) as ended_on')
            ->selectRaw('NULL as absence_minutes')
            ->selectRaw("CASE WHEN l.actual_started_on IS NULL THEN 'planned' ELSE 'recorded' END as status")
            ->addSelect('l.revision')
            ->selectRaw("'leave' as source")
            ->addSelect('lt.category as category')
            ->selectRaw("CASE WHEN EXISTS(
                SELECT 1 FROM leave_requests as lr
                WHERE lr.company_id = ? AND lr.published_leave_id = l.id AND lr.status = 'approved'
            ) THEN 1 ELSE 0 END as workflow_approved", [$company])
            ->selectSub($this->department(
                $company, 'l.employee_id', 'l.planned_started_on',
            ), 'department_name');

        $page = DB::query()->fromSub($absences->unionAll($leaves), 'records')
            ->orderBy('started_on')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($perPage);

        $page->getCollection()->transform(static function (object $row) use ($protectedDetails): array {
            $isLeave = $row->source === 'leave';
            $privateLeave = $isLeave && in_array($row->category, ['medical', 'maternity', 'childcare'], true);
            $privateAbsence = !$isLeave && in_array($row->absence_type, [
                'sick_leave', 'medical_leave', 'maternity', 'childcare',
            ], true);
            $masked = !$protectedDetails && ($privateLeave || $privateAbsence);
            $from = CarbonImmutable::parse($row->started_on);
            $to = CarbonImmutable::parse($row->ended_on);
            return [
                'id' => $isLeave ? 'leave:'.$row->id : $row->id,
                'employee_id' => $row->employee_id,
                'personnel_number' => $row->personnel_number,
                'last_name' => $row->last_name,
                'first_name' => $row->first_name,
                'middle_name' => $row->middle_name,
                'department_name' => $row->department_name,
                'absence_type' => $masked ? 'restricted' : $row->absence_type,
                'reason' => $masked ? 'Отсутствие' : $row->reason,
                'started_on' => $row->started_on,
                'ended_on' => $row->ended_on,
                'days' => (int) $from->diffInDays($to) + 1,
                'absence_minutes' => $row->absence_minutes !== null
                    ? (int) $row->absence_minutes : null,
                'status' => $isLeave && !(bool) $row->workflow_approved
                    ? 'historical' : $row->status,
                'source' => $row->source,
                'revision' => (int) $row->revision,
            ];
        });

        return $page;
    }

    private function department(string $company, string $employeeColumn, string $dateColumn): Builder
    {
        return DB::table('employee_assignments as aa')
            ->join('departments as dd', 'dd.id', '=', 'aa.department_id')
            ->select('dd.name')
            ->where('dd.company_id', $company)
            ->whereColumn('aa.employee_id', $employeeColumn)
            ->whereColumn('aa.valid_from', '<=', $dateColumn)
            ->where(function (Builder $dates) use ($dateColumn): void {
                $dates->whereNull('aa.valid_to')
                    ->orWhereColumn('aa.valid_to', '>=', $dateColumn);
            })
            ->orderByDesc('aa.valid_from')->orderBy('aa.id')->limit(1);
    }
}
