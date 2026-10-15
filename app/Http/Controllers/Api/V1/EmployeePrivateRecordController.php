<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Personnel\EmployeePrivateRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Personnel\ArchiveEmployeePrivateRecordRequest;
use App\Http\Requests\Personnel\SaveEmployeePrivateRecordRequest;
use Illuminate\Http\JsonResponse;

final class EmployeePrivateRecordController extends Controller
{
    public function store(
        SaveEmployeePrivateRecordRequest $request,
        string $company,
        string $employee,
        string $section,
        EmployeePrivateRecords $records,
    ): JsonResponse {
        return response()->json(['data' => $records->create(
            $request->user(), $company, $employee, $section, $request->validated(),
        )], 201);
    }

    public function update(
        SaveEmployeePrivateRecordRequest $request,
        string $company,
        string $employee,
        string $section,
        string $record,
        EmployeePrivateRecords $records,
    ): JsonResponse {
        return response()->json(['data' => $records->update(
            $request->user(), $company, $employee, $section, $record, $request->validated(),
        )]);
    }

    public function destroy(
        ArchiveEmployeePrivateRecordRequest $request,
        string $company,
        string $employee,
        string $section,
        string $record,
        EmployeePrivateRecords $records,
    ): JsonResponse {
        return response()->json(['data' => $records->archive(
            $request->user(), $company, $employee, $section, $record,
            (int) $request->validated('revision'),
        )]);
    }
}
