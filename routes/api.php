<?php

use App\Http\Controllers\Api\V1\CompanyMembershipController;
use App\Http\Controllers\Api\V1\CompanyIndexController;
use App\Http\Controllers\Api\V1\DepartmentIndexController;
use App\Http\Controllers\Api\V1\EmployeeAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeIndexController;
use App\Http\Controllers\Api\V1\EmployeeRecordController;
use App\Http\Controllers\Api\V1\EmployeeShowController;
use App\Http\Controllers\Api\V1\EmployeeProfileRecordController;
use App\Http\Controllers\Api\V1\PositionIndexController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-hrm-api',
    ]));

    Route::middleware(['auth:sanctum', 'hrm.active-account'])->group(function (): void {
        Route::get('/companies', CompanyIndexController::class);
        Route::get('/companies/{company}/positions', PositionIndexController::class)->whereUuid('company');
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

        Route::post('/companies/{company}/employees', [EmployeeRecordController::class, 'store'])
            ->whereUuid('company');

        Route::get('/companies/{company}/employees/{employee}', EmployeeShowController::class)
            ->whereUuid('company')->whereUuid('employee');

        Route::put('/companies/{company}/employees/{employee}', [EmployeeRecordController::class, 'update'])
            ->whereUuid('company')->whereUuid('employee');

        Route::post('/companies/{company}/employees/{employee}/assignments', [EmployeeAssignmentController::class, 'store'])
            ->whereUuid('company')->whereUuid('employee');

        Route::put('/companies/{company}/employees/{employee}/assignments/{assignment}', [EmployeeAssignmentController::class, 'update'])
            ->whereUuid('company')->whereUuid('employee')->whereUuid('assignment');

        Route::post('/companies/{company}/employees/{employee}/assignments/{assignment}/transition', [EmployeeAssignmentController::class, 'transition'])
            ->whereUuid('company')->whereUuid('employee')->whereUuid('assignment');

        Route::post('/companies/{company}/employees/{employee}/profile/{section}', [EmployeeProfileRecordController::class, 'store'])
            ->whereUuid('company')->whereUuid('employee')->whereIn('section', ['experience', 'reserve', 'training']);

        Route::put('/companies/{company}/employees/{employee}/profile/{section}/{record}', [EmployeeProfileRecordController::class, 'update'])
            ->whereUuid('company')->whereUuid('employee')->whereUuid('record')
            ->whereIn('section', ['experience', 'reserve', 'training']);

        Route::put('/companies/{company}/memberships/{member}', [CompanyMembershipController::class, 'upsert'])
            ->whereUuid('company')
            ->whereNumber('member');

        Route::delete('/companies/{company}/memberships/{member}', [CompanyMembershipController::class, 'destroy'])
            ->whereUuid('company')
            ->whereNumber('member');
    });
});
