<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Personnel\EmployeeVisibility;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorktimePlannedDaysController extends Controller
{
    public function __invoke(
        Request $request, string $company,
        WorkforceAuthorization $access, EmployeeVisibility $visibility,
    ): JsonResponse {
        abort_unless((bool) config('hrm.worktime_planning_enabled', false), 404);
        abort_unless($access->canReadPlanningTemplates($request->user(), $company), 403);
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['required', 'uuid', 'distinct'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        abort_unless(CarbonImmutable::parse($input['from'])
            ->diffInDays(CarbonImmutable::parse($input['to'])) <= 365, 422);
        // The employee list must be authorized as a whole; never silently
        // disclose partial results when a hidden employee ID is probed.
        $visibleIds = $visibility->visibleFor($request->user(), $company)
            ->whereIn('employees.id', $input['employee_ids'])->pluck('employees.id')->all();
        abort_unless(count($visibleIds) === count($input['employee_ids']), 404);

        $query = DB::table('employee_work_plans as p')
            ->join('work_schedule_patterns as sp', 'sp.id', '=', 'p.pattern_id')
            ->join('work_schedules as ws', 'ws.id', '=', 'sp.work_schedule_id')
            ->where('p.company_id', $company)->whereIn('p.employee_id', $visibleIds)
            ->whereBetween('p.work_date', [$input['from'], $input['to']])
            ->select(['p.id', 'p.employee_id', 'p.work_date', 'p.planned_minutes',
                'p.day_kind', 'p.shift_code', 'p.source', 'p.revision',
                'ws.name as schedule_name', 'sp.version as pattern_version'])
            ->orderBy('p.employee_id')->orderBy('p.work_date');
        $page = $query->paginate((int) ($input['per_page'] ?? 500));
        return response()->json([
            'data' => $page->items(),
            'meta' => ['from' => $input['from'], 'to' => $input['to'],
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'total' => $page->total(), 'per_page' => $page->perPage()],
        ])->header('Cache-Control', 'private, no-store');
    }
}
