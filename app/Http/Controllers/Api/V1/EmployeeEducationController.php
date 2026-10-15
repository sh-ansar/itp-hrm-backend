<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Personnel\EmployeeEducationRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Personnel\ArchiveEmployeeEducationRequest;
use App\Http\Requests\Personnel\SaveEmployeeEducationRequest;
use Illuminate\Http\JsonResponse;

final class EmployeeEducationController extends Controller
{
    public function store(
        SaveEmployeeEducationRequest $request,
        string $company, string $employee, EmployeeEducationRecords $records,
    ): JsonResponse {
        return response()->json(['data' => $records->create(
            $request->user(), $company, $employee, $request->validated(),
        )], 201);
    }

    public function update(
        SaveEmployeeEducationRequest $request,
        string $company, string $employee, string $record, EmployeeEducationRecords $records,
    ): JsonResponse {
        return response()->json(['data' => $records->update(
            $request->user(), $company, $employee, $record, $request->validated(),
        )]);
    }

    public function destroy(
        ArchiveEmployeeEducationRequest $request,
        string $company, string $employee, string $record, EmployeeEducationRecords $records,
    ): JsonResponse {
        return response()->json(['data' => $records->archive(
            $request->user(), $company, $employee, $record,
            (int) $request->validated('revision'),
        )]);
    }
}
