<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leave\LeavePlanWorkflow;
use App\Domain\Leave\ApprovedLeaveReversal;
use App\Http\Requests\Leave\ReverseApprovedLeaveRequest;
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
        $manager = $permissions->canReviewLeaveRequests($request->user(), $company);
        abort_unless($manager || $permissions->canManageLeaveRequests($request->user(), $company), 403);
        $filters = $request->validate([
            'status' => ['sometimes', 'in:draft,submitted,approved,rejected,cancelled'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = DB::table('leave_requests as lr')
            ->leftJoin('leave_request_evidence as ev', function ($join) use ($company): void {
                $join->on('ev.leave_request_id', '=', 'lr.id')->where('ev.company_id', $company);
            })
            ->join('employees as e', function ($join) use ($company): void {
                $join->on('e.id', '=', 'lr.employee_id')->where('e.company_id', $company);
            })
            ->join('leave_types as lt', function ($join) use ($company): void {
                $join->on('lt.id', '=', 'lr.leave_type_id')->where('lt.company_id', $company);
            })
            ->leftJoin('users as assigned_manager', 'assigned_manager.id', '=', 'lr.assigned_manager_user_id')
            ->where('lr.company_id', $company)
            ->select('lr.id', 'lr.employee_id', 'lr.leave_type_id',
                'lr.planned_started_on', 'lr.planned_ended_on', 'lr.planned_days',
                'lr.manager_recommendation', 'lr.manager_reviewed_by_user_id', 'lr.manager_reviewed_at', 'lr.manager_decision_comment',
                'lr.assigned_manager_user_id', 'lr.manager_department_id', 'assigned_manager.name as assigned_manager_name',
                'lr.status', 'lr.revision', 'lr.note', 'lr.decision_comment', 'lr.published_leave_id',
                'lr.reversed_at', 'lr.reversal_reason', 'lr.created_by_user_id', 'ev.status as evidence_status', 'ev.revision as evidence_revision', 'lt.name as leave_type_name',
                'e.last_name', 'e.first_name', 'e.middle_name', 'lt.category');
        if ($manager) {
            // Current department scope is authoritative, including transferred employees.
            $visible = app(\App\Domain\Personnel\EmployeeVisibility::class)
                ->visibleFor($request->user(), $company)->select('employees.id');
            $query->whereIn('lr.employee_id', $visible)->whereIn('lr.status', ['submitted', 'approved', 'rejected']);
            if (config('hrm.leave_manager_route_snapshot_enabled', false)) {
                // Do not leak a different manager's requests inside shared scopes.
                $query->where('lr.assigned_manager_user_id', $request->user()->getAuthIdentifier());
            }
        }
        if (isset($filters['status'])) $query->where('lr.status', $filters['status']);

        $page = $query->orderByDesc('lr.created_at')->orderBy('lr.id')->paginate(50);
        $items = $page->items();
        if ($manager) {
            // Department scheduling needs dates and decisions, never health/category,
            // employee notes, private PDF metadata or the internal type identity.
            foreach ($items as $row) {
                foreach (['leave_type_id', 'leave_type_name', 'category', 'note',
                    'decision_comment', 'reversal_reason', 'reversed_at', 'evidence_status', 'evidence_revision', 'published_leave_id'] as $field) {
                    $row->{$field} = null;
                }
                $row->manager_decision_comment = null;
            }
        }
        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'manager_approval_required' => (bool) config('hrm.leave_manager_approval_enabled', false),
                'can_review_leave_requests' => $manager,
                'can_manage_leave_requests' => $permissions->canManageLeaveRequests($request->user(), $company),
                'can_reverse_approved_leave' => $permissions->canReverseApprovedLeave($request->user(), $company),
                'can_approve_leave_requests' => $permissions->canApproveLeaveRequests($request->user(), $company),
                'protected_evidence_uploads_enabled' => ! $manager && (bool) config('hrm.protected_leave_evidence_enabled', false),
                'protected_evidence_required' => (bool) config('hrm.protected_leave_evidence_required', false),
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

    public function reverseApproved(
        ReverseApprovedLeaveRequest $request, string $company, string $leaveRequest, ApprovedLeaveReversal $workflow,
    ): JsonResponse {
        $data = $request->validated();
        return response()->json(['data' => $workflow->reverse(
            $request->user(), $company, $leaveRequest, (int) $data['revision'], $data['reason'],
        )]);
    }

    public function managerDecision(
        LeaveWorkflowRequest $request, string $company, string $leaveRequest, LeavePlanWorkflow $workflow,
    ): JsonResponse {
        $data = $request->validated();
        return response()->json(['data' => $workflow->managerDecide(
            $request->user(), $company, $leaveRequest, (int) $data['revision'],
            $data['decision'], $data['comment'] ?? null,
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
