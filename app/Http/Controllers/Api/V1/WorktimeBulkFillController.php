<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Worktime\BulkTimesheetFill;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorktimeBulkFillController extends Controller
{
    public function run(
        Request $request,
        string $company,
        BulkTimesheetFill $service,
        WorkforceAuthorization $authorization,
    ): JsonResponse {
        abort_unless((bool) config('hrm.worktime_bulk_fill_enabled', false)
            && (bool) config('hrm.worktime_writes_enabled', false), 404);
        abort_unless($authorization->canEditTimesheet($request->user(), $company), 403);

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:100'],
            'employee_ids.*' => ['required', 'uuid', 'distinct'],
            'dates' => ['required', 'array', 'min:1', 'max:31'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'apply' => ['required', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
            'preview_token' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        foreach ($validated['dates'] as $index => $date) {
            if (! str_starts_with($date, $validated['month'].'-')) {
                throw ValidationException::withMessages([
                    "dates.{$index}" => 'All dates must be in the selected month.',
                ]);
            }
        }
        if ($validated['apply'] && !($validated['confirm'] ?? false)) {
            throw ValidationException::withMessages([
                'confirm' => 'Explicit confirmation is required before bulk saving.',
            ]);
        }
        if ($validated['apply'] && empty($validated['preview_token'])) {
            throw ValidationException::withMessages([
                'preview_token' => 'Preview the requested dates and employees before saving.',
            ]);
        }

        return response()->json($service->run(
            $request->user(),
            $company,
            $validated['month'],
            $validated['employee_ids'],
            $validated['dates'],
            (bool) $validated['apply'],
            $validated['preview_token'] ?? null,
        ));
    }
}
