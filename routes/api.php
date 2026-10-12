<?php

use App\Http\Controllers\Api\V1\CompanyMembershipController;
use App\Http\Controllers\Api\V1\CompanyIndexController;
use App\Http\Controllers\Api\V1\DepartmentIndexController;
use App\Http\Controllers\Api\V1\EmployeeIndexController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-hrm-api',
    ]));

    Route::middleware(['auth:sanctum', 'hrm.active-account'])->group(function (): void {
        Route::get('/companies', CompanyIndexController::class);
        Route::get('/companies/{company}/departments', DepartmentIndexController::class)->whereUuid('company');

        Route::get('/me', static function (Request $request) {
            $user = $request->user();

            return response()->json(['data' => [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name,
            ]]);
        });

        Route::get('/companies/{company}/employees', EmployeeIndexController::class)
            ->whereUuid('company');

        Route::put('/companies/{company}/memberships/{member}', [CompanyMembershipController::class, 'upsert'])
            ->whereUuid('company')
            ->whereNumber('member');

        Route::delete('/companies/{company}/memberships/{member}', [CompanyMembershipController::class, 'destroy'])
            ->whereUuid('company')
            ->whereNumber('member');
    });
});
