<?php

use App\Http\Controllers\Api\V1\CompanyMembershipController;
use App\Http\Controllers\Api\V1\CompanyDirectoryController;
use App\Http\Controllers\Api\V1\CompanyIndexController;
use App\Http\Controllers\Api\V1\CurrentAccessController;
use App\Http\Controllers\Api\V1\DepartmentIndexController;
use App\Http\Controllers\Api\V1\DepartmentRecordController;
use App\Http\Controllers\Api\V1\EmployeeAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeIndexController;
use App\Http\Controllers\Api\V1\EmployeeDevelopmentIndexController;
use App\Http\Controllers\Api\V1\EmployeeRecordController;
use App\Http\Controllers\Api\V1\EmployeeShowController;
use App\Http\Controllers\Api\V1\EmployeePhotoController;
use App\Http\Controllers\Api\V1\EmployeeProfileRecordController;
use App\Http\Controllers\Api\V1\EmployeePrivateProfileController;
use App\Http\Controllers\Api\V1\EmployeePrivateRecordController;
use App\Http\Controllers\Api\V1\EmployeeEducationController;
use App\Http\Controllers\Api\V1\PositionIndexController;
use App\Http\Controllers\Api\V1\PositionRecordController;
use App\Http\Controllers\Api\V1\PersonnelActionController;
use App\Http\Controllers\Api\V1\PersonnelDocumentController;
use App\Http\Controllers\Api\V1\PersonnelDocumentFileController;
use App\Http\Controllers\Api\V1\StaffingPlanController;
use App\Http\Controllers\Api\V1\StaffingHistoryController;
use App\Http\Controllers\Api\V1\WorktimeReadController;
use App\Http\Controllers\Api\V1\WorktimeTimesheetWriteController;
use App\Http\Controllers\Api\V1\WorkScheduleWriteController;
use App\Http\Controllers\Api\V1\ProductionCalendarWriteController;
use App\Http\Controllers\Api\V1\LeaveReadController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\EmployeeBonusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-hrm-api',
        // Frontend must not expose credential entry while the independent
        // HRM login feature remains explicitly disabled on the server.
        'capabilities' => [
            'browser_login' => (bool) config('hrm.browser_login_enabled', false),
            'password_reset' => (bool) config('hrm.password_reset_enabled', false),
        ],
    ]));

    Route::middleware(['auth:sanctum', 'hrm.active-account'])->group(function (): void {
        Route::get('/me/access', CurrentAccessController::class);
        Route::get('/companies', CompanyIndexController::class);
        Route::get('/companies/{company}/directories/{group}', [CompanyDirectoryController::class, 'index'])
            ->whereUuid('company');
        Route::post('/companies/{company}/directories/{group}', [CompanyDirectoryController::class, 'store'])
            ->whereUuid('company');
        Route::put('/companies/{company}/directories/{group}/{entry}', [CompanyDirectoryController::class, 'update'])
            ->whereUuid('company')->whereUuid('entry');
        Route::delete('/companies/{company}/directories/{group}/{entry}', [CompanyDirectoryController::class, 'destroy'])
            ->whereUuid('company')->whereUuid('entry');

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
        Route::get('/companies/{company}/worktime/timesheet/{employee}', [WorktimeTimesheetWriteController::class, 'show'])
            ->whereUuid('company')->whereUuid('employee');
        Route::put('/companies/{company}/worktime/timesheet/{employee}', [WorktimeTimesheetWriteController::class, 'update'])
            ->whereUuid('company')->whereUuid('employee');
        Route::get('/companies/{company}/worktime/schedules', [WorktimeReadController::class, 'schedules'])
            ->whereUuid('company');
        Route::post('/companies/{company}/worktime/schedules', [WorkScheduleWriteController::class, 'store'])
            ->whereUuid('company');
        Route::put('/companies/{company}/worktime/schedules/{schedule}', [WorkScheduleWriteController::class, 'update'])
            ->whereUuid('company')->whereUuid('schedule');
        Route::put('/companies/{company}/worktime/schedules/{schedule}/employees/{employee}', [WorkScheduleWriteController::class, 'assign'])
            ->whereUuid('company')->whereUuid('schedule')->whereUuid('employee');
        Route::get('/companies/{company}/worktime/calendar', [WorktimeReadController::class, 'calendar'])
            ->whereUuid('company');
        Route::put('/companies/{company}/worktime/calendar', ProductionCalendarWriteController::class)
            ->whereUuid('company');
        Route::get('/companies/{company}/worktime/absences', [WorktimeReadController::class, 'absences'])
            ->whereUuid('company');

        Route::get('/companies/{company}/leaves', LeaveReadController::class)
            ->whereUuid('company');
        Route::get('/companies/{company}/leave-types', [LeaveTypeController::class, 'index'])
            ->whereUuid('company');
        Route::post('/companies/{company}/leave-types', [LeaveTypeController::class, 'store'])
            ->whereUuid('company');
        Route::get('/companies/{company}/leave-requests', [LeaveRequestController::class, 'index'])
            ->whereUuid('company');
        Route::post('/companies/{company}/leave-requests', [LeaveRequestController::class, 'store'])
            ->whereUuid('company');
        Route::put('/companies/{company}/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'update'])
            ->whereUuid('company')->whereUuid('leaveRequest');
        Route::post('/companies/{company}/leave-requests/{leaveRequest}/submit', [LeaveRequestController::class, 'submit'])
            ->whereUuid('company')->whereUuid('leaveRequest');
        Route::post('/companies/{company}/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])
            ->whereUuid('company')->whereUuid('leaveRequest');
        Route::post('/companies/{company}/leave-requests/{leaveRequest}/decision', [LeaveRequestController::class, 'decision'])
            ->whereUuid('company')->whereUuid('leaveRequest');
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

        Route::get('/companies/{company}/bonuses', [EmployeeBonusController::class, 'index'])
            ->whereUuid('company');
        Route::post('/companies/{company}/bonuses', [EmployeeBonusController::class, 'store'])
            ->whereUuid('company');
        Route::put('/companies/{company}/bonuses/{bonus}', [EmployeeBonusController::class, 'update'])
            ->whereUuid('company')->whereUuid('bonus');
        Route::post('/companies/{company}/bonuses/{bonus}/submit', [EmployeeBonusController::class, 'submit'])
            ->whereUuid('company')->whereUuid('bonus');
        Route::post('/companies/{company}/bonuses/{bonus}/cancel', [EmployeeBonusController::class, 'cancel'])
            ->whereUuid('company')->whereUuid('bonus');
        Route::post('/companies/{company}/bonuses/{bonus}/decision', [EmployeeBonusController::class, 'decision'])
            ->whereUuid('company')->whereUuid('bonus');
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

        Route::get('/companies/{company}/employees/{employee}/photo', [EmployeePhotoController::class, 'show'])
            ->whereUuid('company')->whereUuid('employee');
        Route::post('/companies/{company}/employees/{employee}/photo', [EmployeePhotoController::class, 'upload'])
            ->whereUuid('company')->whereUuid('employee');
        Route::delete('/companies/{company}/employees/{employee}/photo', [EmployeePhotoController::class, 'destroy'])
            ->whereUuid('company')->whereUuid('employee');

        Route::put('/companies/{company}/employees/{employee}', [EmployeeRecordController::class, 'update'])
            ->whereUuid('company')->whereUuid('employee');
        Route::put('/companies/{company}/employees/{employee}/private-profile/{section}', [EmployeePrivateProfileController::class, 'update'])
            ->whereUuid('company')->whereUuid('employee')->whereIn('section', ['personal', 'military']);
        Route::post('/companies/{company}/employees/{employee}/private-records/{section}', [EmployeePrivateRecordController::class, 'store'])
            ->whereUuid('company')->whereUuid('employee')->whereIn('section', ['family', 'identity']);
        Route::put('/companies/{company}/employees/{employee}/private-records/{section}/{record}', [EmployeePrivateRecordController::class, 'update'])
            ->whereUuid('company')->whereUuid('employee')->whereUuid('record')->whereIn('section', ['family', 'identity']);
        Route::delete('/companies/{company}/employees/{employee}/private-records/{section}/{record}', [EmployeePrivateRecordController::class, 'destroy'])
            ->whereUuid('company')->whereUuid('employee')->whereUuid('record')->whereIn('section', ['family', 'identity']);
        Route::post('/companies/{company}/employees/{employee}/education', [EmployeeEducationController::class, 'store'])
            ->whereUuid('company')->whereUuid('employee');
        Route::put('/companies/{company}/employees/{employee}/education/{record}', [EmployeeEducationController::class, 'update'])
            ->whereUuid('company')->whereUuid('employee')->whereUuid('record');
        Route::delete('/companies/{company}/employees/{employee}/education/{record}', [EmployeeEducationController::class, 'destroy'])
            ->whereUuid('company')->whereUuid('employee')->whereUuid('record');

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

        Route::get('/companies/{company}/memberships', [CompanyMembershipController::class, 'index'])
            ->whereUuid('company');

        Route::put('/companies/{company}/memberships/{member}', [CompanyMembershipController::class, 'upsert'])
            ->whereUuid('company')
            ->whereNumber('member');

        Route::delete('/companies/{company}/memberships/{member}', [CompanyMembershipController::class, 'destroy'])
            ->whereUuid('company')
            ->whereNumber('member');
    });
});
