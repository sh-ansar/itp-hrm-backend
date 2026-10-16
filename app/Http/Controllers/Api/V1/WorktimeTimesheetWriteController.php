<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Worktime\TimesheetEntryWriter;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Worktime\UpsertTimesheetEntriesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorktimeTimesheetWriteController extends Controller
{
    public function show(
        Request $request,
        string $company,
        string $employee,
        TimesheetEntryWriter $writer,
        WorkforceAuthorization $authorization,
    ): JsonResponse {
        abort_unless((bool) config('hrm.worktime_writes_enabled', false), 404);
        abort_unless($authorization->canEditTimesheet($request->user(), $company), 403);

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        return response()->json([
            'data' => $writer->days($company, $employee, $validated['month']),
            'meta' => [
                'month' => $validated['month'],
                'can_edit' => true,
            ],
        ]);
    }

    public function update(
        UpsertTimesheetEntriesRequest $request,
        string $company,
        string $employee,
        TimesheetEntryWriter $writer,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json($writer->upsert(
            $request->user(),
            $company,
            $employee,
            $validated['month'],
            $validated['days'],
        ));
    }
}
