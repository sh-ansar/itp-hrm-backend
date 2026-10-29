<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Worktime\WorktimeMatrixBulkEditor;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorktimeMatrixBulkController extends Controller
{
    public function __invoke(Request $request, string $company, WorktimeMatrixBulkEditor $editor): JsonResponse
    {
        abort_unless(config('hrm.worktime_matrix_enabled', false)
            && config('hrm.worktime_writes_enabled', false), 404);
        $input = $request->validate([
            // Either a selected employee x date rectangle, or exact sparse cells.
            'employee_ids' => ['required_without:cells', 'array', 'min:1', 'max:50'],
            'employee_ids.*' => ['required', 'uuid', 'distinct'],
            'dates' => ['required_without:cells', 'array', 'min:1', 'max:62'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'cells' => ['required_without:employee_ids', 'array', 'min:1', 'max:2500'],
            'cells.*.employee_id' => ['required', 'uuid'],
            'cells.*.date' => ['required', 'date_format:Y-m-d'],
            'operation' => ['required', Rule::in(['fill_missing', 'set_worked'])],
            'worked_minutes' => ['required_if:operation,set_worked', 'nullable', 'integer', 'between:0,1440'],
            'overwrite_existing' => ['sometimes', 'boolean'],
            'apply' => ['required', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
            'preview_token' => ['sometimes', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);
        if (isset($input['cells'])) {
            if (isset($input['employee_ids']) || isset($input['dates'])) {
                throw ValidationException::withMessages(['cells' => 'Use cells or a rectangle, not both.']);
            }
            foreach ($input['cells'] as $row) {
                if (array_diff(array_keys($row), ['employee_id', 'date']) !== []) {
                    throw ValidationException::withMessages(['cells' => 'Unexpected cell fields.']);
                }
            }
            $keys = array_map(static fn (array $cell): string => $cell['employee_id'].'|'.$cell['date'],
                $input['cells']);
            if (count(array_unique($keys)) !== count($keys)) {
                throw ValidationException::withMessages(['cells' => 'Duplicated employee-date cells.']);
            }
            $employeeIds = array_unique(array_column($input['cells'], 'employee_id'));
            $orderedDates = array_values(array_unique(array_column($input['cells'], 'date')));
            if (count($employeeIds) > 50 || count($orderedDates) > 62) {
                throw ValidationException::withMessages(['cells' => 'Maximum 50 people and 62 dates.']);
            }
        } else {
            if (count($input['employee_ids']) * count($input['dates']) > 2500) {
                throw ValidationException::withMessages(['dates' => 'Maximum 2500 employee-day cells per operation.']);
            }
            $orderedDates = $input['dates'];
        }
        sort($orderedDates, SORT_STRING);
        if (CarbonImmutable::parse($orderedDates[0])->diffInDays(
            CarbonImmutable::parse($orderedDates[array_key_last($orderedDates)])) > 61) {
            throw ValidationException::withMessages(['dates' => 'Selected dates span more than 62 calendar days.']);
        }
        if ($input['operation'] === 'fill_missing' && isset($input['worked_minutes'])) {
            throw ValidationException::withMessages(['worked_minutes' => 'Not needed for fill_missing.']);
        }
        if ($input['apply'] && (empty($input['confirm']) || !isset($input['preview_token']))) {
            throw ValidationException::withMessages(['confirm' => 'Preview and confirm the exact selection first.']);
        }
        if (array_diff(array_keys($request->all()), array_keys($input)) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unexpected matrix fields.']);
        }
        return response()->json($editor->run($request->user(), $company, $input))
            ->header('Cache-Control', 'private, no-store');
    }
}
