<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Personnel\EmployeePrivateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Personnel\SavePrivateProfileRequest;
use Illuminate\Http\JsonResponse;

final class EmployeePrivateProfileController extends Controller
{
    public function update(
        SavePrivateProfileRequest $request,
        string $company,
        string $employee,
        string $section,
        EmployeePrivateProfile $profiles,
    ): JsonResponse {
        return response()->json([
            'data' => $profiles->save(
                $request->user(), $company, $employee, $section, $request->validated(),
            ),
        ]);
    }
}
