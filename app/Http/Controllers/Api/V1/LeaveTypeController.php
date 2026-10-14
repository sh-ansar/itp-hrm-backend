<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\EmployeeVisibility;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\LeaveTypeStoreRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LeaveTypeController extends Controller
{
    public function index(
        Request $request, string $company, EmployeeVisibility $visibility,
        WorkforceAuthorization $permissions,
    ): JsonResponse {
        $visibility->visibleFor($request->user(), $company);
        $types = DB::table('leave_types')->where('company_id', $company)
            ->select('id', 'code', 'name', 'annual_days', 'balance_method', 'revision')
            ->orderBy('name')->get();

        return response()->json([
            'data' => $types,
            'meta' => ['can_manage_leave_types' => $permissions->canApproveLeaveRequests($request->user(), $company)],
        ]);
    }

    public function store(
        LeaveTypeStoreRequest $request, string $company, HrAuditWriter $audit,
    ): JsonResponse {
        $data = $request->validated();
        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $company, $data, $id, $audit): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            if (DB::table('leave_types')->where('company_id', $company)->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'The leave type code already exists.']);
            }
            DB::table('leave_types')->insert(array_merge($data, [
                'id' => $id, 'company_id' => $company, 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $audit->record($request->user(), $company, HrAuditAction::LeaveTypeCreated, null, [
                'leave_type_id' => $id, 'revision' => 1,
            ]);
        }, 3);

        return response()->json(['data' => array_merge($data, ['id' => $id, 'revision' => 1])], 201);
    }
}
