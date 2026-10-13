<?php

use App\Http\Controllers\Api\V1\CompanyMembershipController;
use App\Http\Controllers\Api\V1\CompanyIndexController;
use App\Http\Controllers\Api\V1\DepartmentIndexController;
use App\Http\Controllers\Api\V1\DepartmentRecordController;
use App\Http\Controllers\Api\V1\EmployeeAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeIndexController;
use App\Http\Controllers\Api\V1\EmployeeDevelopmentIndexController;
use App\Http\Controllers\Api\V1\EmployeeRecordController;
use App\Http\Controllers\Api\V1\EmployeeShowController;
use App\Http\Controllers\Api\V1\EmployeeProfileRecordController;
use App\Http\Controllers\Api\V1\PositionIndexController;
use App\Http\Controllers\Api\V1\PositionRecordController;
use App\Http\Controllers\Api\V1\PersonnelActionController;
use App\Http\Controllers\Api\V1\PersonnelDocumentController;
use App\Http\Controllers\Api\V1\PersonnelDocumentFileController;
use App\Http\Controllers\Api\V1\StaffingPlanController;
use App\Http\Controllers\Api\V1\StaffingHistoryController;
use App\Http\Controllers\Api\V1\WorktimeReadController;
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
        Route::post('/companies/{company}/positions', [PositionRecordController::class, 'store'])
            ->whereUuid('company');
        Route::put('/companies/{company}/positions/{position}', [PositionRecordController::class, 'update'])
            ->whereUuid('company')->whereUuid('position');
        Route::delete('/companies/{company}/positions/{position}', [PositionRecordController::class, 'destroy'])
            ->whereUuid('company')->whereUuid('position');
        Route::get('/companies/{company}/departments', DepartmentIndexController::class)->whereUuid('company');
        Route::post('/companies/{company}/departments', [DepartmentRecordController::class, 'store'])
            ->whereUuid('company');
        Route::put('/companies/{company}/departments/{department}', [DepartmentRecordController::class, 'update'])
            ->whereUuid('company')->whereUuid('department');
        Route::delete('/companies/{company}/departments/{department}', [DepartmentRecordController::class, 'destroy'])
            ->whereUuid('company')->whereUuid('department');

        Route::get('/companies/{company}/staffing-history', StaffingHistoryController::class)
            ->whereUuid('company');

        Route::get('/companies/{company}/worktime/timesheet', [WorktimeReadController::class, 'timesheet'])
            ->whereUuid('company');
        Route::get('/companies/{company}/worktime/schedules', [WorktimeReadController::class, 'schedules'])
            ->whereUuid('company');
        Route::get('/companies/{company}/worktime/calendar', [WorktimeReadController::class, 'calendar'])
            ->whereUuid('company');
        Route::get('/companies/{company}/worktime/absences', [WorktimeReadController::class, 'absences'])
            ->whereUuid('company');
        Route::get('/companies/{company}/staffing-plans', [StaffingPlanController::class, 'index'])
            ->whereUuid('company');
        Route::post('/companies/{company}/staffing-plans', [StaffingPlanController::class, 'store'])
            ->whereUuid('company');
        Route::get('/companies/{company}/staffing-plans/{plan}', [StaffingPlanController::class, 'show'])
            ->whereUuid('company')->whereUuid('plan');
        Route::put('/companies/{company}/staffing-plans/{plan}', [StaffingPlanController::class, 'update'])
            ->whereUuid('company')->whereUuid('plan');
        Route::post('/companies/{company}/staffing-plans/{plan}/lines', [StaffingPlanController::class, 'storeLine'])
            ->whereUuid('company')->whereUuid('plan');
        Route::put('/companies/{company}/staffing-plans/{plan}/lines/{line}', [StaffingPlanController::class, 'updateLine'])
            ->whereUuid('company')->whereUuid('plan')->whereUuid('line');
        Route::post('/companies/{company}/staffing-plans/{plan}/approve', [StaffingPlanController::class, 'approve'])
            ->whereUuid('company')->whereUuid('plan');

        Route::get('/me', static function (Request $request) {
            $user = $request->user();

            return response()->json(['data' => [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name,
            ]]);
        });

        Route::get('/companies/{company}/employees', EmployeeIndexController::class)
            ->whereUuid('company');

        Route::get('/companies/{company}/development/{section}', EmployeeDevelopmentIndexController::class)
            ->whereUuid('company')->whereIn('section', ['training', 'reserve']);

        Route::post('/companies/{company}/documents', [PersonnelDocumentController::class, 'store'])
            ->whereUuid('company');
        Route::post('/companies/{company}/documents/{document}/file', [PersonnelDocumentFileController::class, 'upload'])
            ->whereUuid('company')->whereUuid('document');
        Route::get('/companies/{company}/documents/{document}/file', [PersonnelDocumentFileController::class, 'download'])
            ->whereUuid('company')->whereUuid('document');
        Route::get('/companies/{company}/documents', [PersonnelDocumentController::class, 'index'])
            ->whereUuid('company');

        Route::get('/companies/{company}/personnel-actions', [PersonnelActionController::class, 'index'])
            ->whereUuid('company');
        Route::post('/companies/{company}/employees/{employee}/personnel-actions/hire', [PersonnelActionController::class, 'hire'])
            ->whereUuid('company')->whereUuid('employee');
        Route::post('/companies/{company}/employees/{employee}/personnel-actions/transfer', [PersonnelActionController::class, 'transfer'])
            ->whereUuid('company')->whereUuid('employee');
        Route::post('/companies/{company}/employees/{employee}/personnel-actions/terminate', [PersonnelActionController::class, 'terminate'])
            ->whereUuid('company')->whereUuid('employee');

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
