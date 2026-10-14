<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leave\LeavePlanWorkflow;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\LeavePlanSaveRequest;
use App\Http\Requests\Leave\LeaveWorkflowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaveRequestController extends Controller
{
    public function index(Request $request, string $company, WorkforceAuthorization $permissions): JsonResponse
    {
        abort_unless((bool) config('hrm.leave_writes_enabled', false), 404);
        abort_unless($permissions->canManageLeaveRequests($request->user(), $company), 403);
        $filters = $request->validate([
            'status' => ['sometimes', 'in:draft,submitted,approved,rejected,cancelled'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = DB::table('leave_requests as lr')
            ->join('employees as e', function ($join) use ($company): void {
                $join->on('e.id', '=', 'lr.employee_id')->where('e.company_id', $company);
            })
            ->join('leave_types as lt', function ($join) use ($company): void {
                $join->on('lt.id', '=', 'lr.leave_type_id')->where('lt.company_id', $company);
            })
            ->where('lr.company_id', $company)
            ->select('lr.id', 'lr.employee_id', 'lr.leave_type_id',
                'lr.planned_started_on', 'lr.planned_ended_on', 'lr.planned_days',
                'lr.status', 'lr.revision', 'lr.note', 'lr.decision_comment', 'lr.published_leave_id',
                'lr.created_by_user_id', 'lt.name as leave_type_name',
                'e.last_name', 'e.first_name', 'e.middle_name');
        if (isset($filters['status'])) $query->where('lr.status', $filters['status']);

        $page = $query->orderByDesc('lr.created_at')->orderBy('lr.id')->paginate(50);
        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'can_approve_leave_requests' => $permissions->canApproveLeaveRequests($request->user(), $company),
            ],
        ]);
    }

    public function store(LeavePlanSaveRequest $request, string $company, LeavePlanWorkflow $workflow): JsonResponse
    {
        return response()->json([
            'data' => $workflow->create($request->user(), $company, $request->validated()),
        ], 201);
    }

    public function update(
        LeavePlanSaveRequest $request, string $company, string $leaveRequest, LeavePlanWorkflow $workflow,
    ): JsonResponse {
        return response()->json([
            'data' => $workflow->update($request->user(), $company, $leaveRequest, $request->validated()),
        ]);
    }

    public function submit(
        LeaveWorkflowRequest $request, string $company, string $leaveRequest, LeavePlanWorkflow $workflow,
    ): JsonResponse {
        $data = $request->validated();
        return response()->json(['data' => $workflow->submit(
            $request->user(), $company, $leaveRequest, (int) $data['revision'],
        )]);
    }

    public function cancel(
        LeaveWorkflowRequest $request, string $company, string $leaveRequest, LeavePlanWorkflow $workflow,
    ): JsonResponse {
        $data = $request->validated();
        return response()->json(['data' => $workflow->cancel(
            $request->user(), $company, $leaveRequest, (int) $data['revision'],
        )]);
    }

    public function decision(
        LeaveWorkflowRequest $request, string $company, string $leaveRequest, LeavePlanWorkflow $workflow,
    ): JsonResponse {
        $data = $request->validated();
        return response()->json(['data' => $workflow->decide(
            $request->user(), $company, $leaveRequest, (int) $data['revision'],
            $data['decision'], $data['comment'] ?? null,
        )]);
    }
}
