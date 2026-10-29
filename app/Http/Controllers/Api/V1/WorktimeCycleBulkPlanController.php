<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Worktime\ScheduleCyclePlanner;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorktimeCycleBulkPlanController extends Controller
{
    public function __invoke(
        Request $request, string $company, string $schedule,
        ScheduleCyclePlanner $planner, WorkforceAuthorization $access,
    ): JsonResponse {
        abort_unless((bool) config('hrm.worktime_writes_enabled', false)
            && (bool) config('hrm.worktime_planning_enabled', false), 404);
        abort_unless($access->canReadPlanningTemplates($request->user(), $company), 403);

        $input = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['required', 'uuid', 'distinct'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'schedule_revision' => ['required', 'integer', 'min:1'],
            'cycle_offset' => ['sometimes', 'integer', 'between:0,59'],
            'replace_future_plans' => ['sometimes', 'boolean'],
            'apply' => ['required', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
            'preview_token' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        if (array_diff(array_keys($request->all()), array_keys($input)) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unexpected planning fields.']);
        }
        if ($input['apply'] && (empty($input['confirm']) || !isset($input['preview_token']))) {
            throw ValidationException::withMessages([
                'confirm' => 'A matching preview and explicit confirmation are required.',
            ]);
        }

        return response()->json($planner->run($request->user(), $company, $schedule, $input))
            ->header('Cache-Control', 'private, no-store');
    }
}
