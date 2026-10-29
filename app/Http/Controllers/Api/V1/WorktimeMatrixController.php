<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Worktime\WorktimeMatrixReader;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorktimeMatrixController extends Controller
{
    public function __invoke(Request $request, string $company, WorktimeMatrixReader $reader): JsonResponse
    {
        abort_unless(config('hrm.worktime_matrix_enabled', false), 404);
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'string', 'max:100'],
            'department_id' => ['sometimes', 'uuid'],
            'schedule_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['all', 'recorded', 'unrecorded'])],
            'group_by' => ['sometimes', Rule::in(['department', 'schedule', 'none'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        if (CarbonImmutable::parse($input['from'])->diffInDays(CarbonImmutable::parse($input['to'])) > 61) {
            throw ValidationException::withMessages(['to' => 'The grid supports up to 62 consecutive dates.']);
        }
        return response()->json($reader->read($request->user(), $company, $input))
            ->header('Cache-Control', 'private, no-store');
    }
}
