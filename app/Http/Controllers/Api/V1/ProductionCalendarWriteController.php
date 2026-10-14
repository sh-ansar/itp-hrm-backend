<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Worktime\ProductionCalendarWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Worktime\UpsertCalendarDaysRequest;
use Illuminate\Http\JsonResponse;

final class ProductionCalendarWriteController extends Controller
{
    public function __invoke(
        UpsertCalendarDaysRequest $request,
        string $company,
        ProductionCalendarWriter $writer,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json($writer->upsert(
            $request->user(), $company, $validated['month'], $validated['days'],
        ));
    }
}
