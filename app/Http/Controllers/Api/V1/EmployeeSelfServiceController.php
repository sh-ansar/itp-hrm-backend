<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Leave\LeavePlanWorkflow;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Own-data boundary. Never accept an employee identity from a self-service client. */
final class EmployeeSelfServiceController extends Controller
{
    private function member(Request $request, string $company): void
    {
        abort_unless(DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $request->user()->id)
            ->whereIn('role_code', ['company_admin', 'hr_specialist', 'department_manager', 'employee'])->exists(), 403);
    }

    private function employee(Request $request, string $company): ?string
    {
        $this->member($request, $company);
        return DB::table('employee_user_links')->where('company_id', $company)
            ->where('user_id', $request->user()->id)->value('employee_id');
    }

    public function show(Request $request, string $company)
    {
        $request->validate(['requests_page' => ['sometimes', 'integer', 'min:1'], 'leaves_page' => ['sometimes', 'integer', 'min:1']]);
        $id = $this->employee($request, $company);
        $employee = $id ? DB::table('employees')->where('company_id', $company)->where('id', $id)
            ->first(['id', 'personnel_number', 'first_name', 'last_name', 'middle_name']) : null;
        $requests = $id ? DB::table('leave_requests as r')
            ->join('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('r.company_id', $company)->where('r.employee_id', $id)
            // HR planning notes are not employee-facing correspondence.
            ->where('r.created_by_user_id', $request->user()->id)
            ->orderByDesc('r.created_at')->orderByDesc('r.id')
            ->select('r.id', 'r.leave_type_id', 't.name as leave_type_name', 'r.planned_started_on',
                'r.planned_ended_on', 'r.planned_days', 'r.status', 'r.revision', 'r.note', 'r.decision_comment')
            ->paginate(25, ['*'], 'requests_page') : null;
        $leaves = $id ? DB::table('employee_leaves as l')
            ->join('leave_types as t', 't.id', '=', 'l.leave_type_id')
            ->where('t.company_id', $company)->where('l.employee_id', $id)
            ->orderByDesc('l.planned_started_on')->orderBy('l.id')
            ->select('l.id', 't.name as leave_type_name', 'l.planned_started_on', 'l.planned_ended_on',
                'l.planned_days', 'l.actual_started_on', 'l.actual_ended_on', 'l.actual_days')
            ->paginate(25, ['*'], 'leaves_page') : null;
        return response()->json(['data' => [
            'employee' => $employee, 'requests' => $requests?->items() ?? [], 'leaves' => $leaves?->items() ?? [],
            'leave_types' => $id ? DB::table('leave_types')->where('company_id', $company)
                ->orderBy('name')->get(['id', 'name', 'category']) : [],
        ], 'meta' => ['can_request' => $id !== null && (bool) config('hrm.leave_writes_enabled', false),
            'requests_last_page' => $requests?->lastPage() ?? 1, 'leaves_last_page' => $leaves?->lastPage() ?? 1]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, string $company, LeavePlanWorkflow $workflow, ?string $leaveRequest = null)
    {
        return DB::transaction(function () use ($request, $company, $workflow, $leaveRequest) {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            abort_unless(config('hrm.leave_writes_enabled', false), 404);
            $employee = $this->employee($request, $company);
            abort_unless($employee, 403, 'Account is not linked to an employee.');
            if ($leaveRequest) $this->ownRequest($request, $company, $employee, $leaveRequest);
            $input = $request->validate([
                'employee_id' => ['prohibited'], 'company_id' => ['prohibited'],
                'leave_type_id' => ['required', 'uuid'],
                'planned_started_on' => ['required', 'date_format:Y-m-d'],
                'planned_ended_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:planned_started_on'],
                'note' => ['present', 'nullable', 'string', 'max:2000'],
                'revision' => $leaveRequest ? ['required', 'integer', 'between:1,2147483646'] : ['prohibited'],
            ]);
            $input['employee_id'] = $employee;
            return response()->json(['data' => $leaveRequest
                ? $workflow->update($request->user(), $company, $leaveRequest, $input)
                : $workflow->create($request->user(), $company, $input)], $leaveRequest ? 200 : 201);
        }, 3);
    }

    private function ownRequest(Request $request, string $company, string $employee, string $id): void
    {
        abort_unless(DB::table('leave_requests')->where('company_id', $company)->where('employee_id', $employee)
            ->where('created_by_user_id', $request->user()->id)->where('id', $id)->exists(), 404);
    }

    public function transition(Request $request, string $company, string $leaveRequest, string $action, LeavePlanWorkflow $workflow)
    {
        return DB::transaction(function () use ($request, $company, $leaveRequest, $action, $workflow) {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            abort_unless(config('hrm.leave_writes_enabled', false), 404);
            $employee = $this->employee($request, $company);
            abort_unless($employee, 403);
            $this->ownRequest($request, $company, $employee, $leaveRequest);
            $input = $request->validate(['revision' => ['required', 'integer', 'between:1,2147483646'],
                'employee_id' => ['prohibited'], 'decision' => ['prohibited']]);
            return response()->json(['data' => $workflow->{$action}($request->user(), $company, $leaveRequest, $input['revision'])]);
        }, 3);
    }

    private function admin(Request $request, string $company): void
    {
        abort_unless(config('hrm.role_management_enabled', false), 404);
        abort_unless(DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $request->user()->id)->where('role_code', 'company_admin')->exists(), 403);
    }

    public function link(Request $request, string $company, int $user, HrAuditWriter $audit)
    {
        $this->admin($request, $company);
        return DB::transaction(function () use ($request, $company, $user, $audit) {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $this->admin($request, $company);
            abort_unless(DB::table('company_user_roles')->where('company_id', $company)->where('user_id', $user)->exists(), 404);
            $row = DB::table('employee_user_links')->where('company_id', $company)->where('user_id', $user)->first();
            if ($request->isMethod('get')) return response()->json(['data' => $row]);
            $data = $request->validate([
                'employee_id' => ['present', 'nullable', 'uuid', Rule::exists('employees', 'id')->where('company_id', $company)],
                'revision' => ['required', 'integer', 'between:0,2147483646'],
            ]);
            abort_unless((int) ($row->revision ?? 0) === $data['revision'], 409, 'Reload the account link before saving.');
            if ($data['employee_id'] && DB::table('employee_user_links')->where('company_id', $company)
                ->where('employee_id', $data['employee_id'])->where('user_id', '<>', $user)->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'Employee is already linked to another account.']);
            }
            $values = ['employee_id' => $data['employee_id'], 'revision' => $data['revision'] + 1, 'updated_at' => now()];
            DB::table('employee_user_links')->updateOrInsert(['company_id' => $company, 'user_id' => $user],
                $row ? $values : array_merge($values, ['created_at' => now()]));
            $audit->record($request->user(), $company, HrAuditAction::EmployeeAccountLinked, $user,
                ['employee_id' => $data['employee_id'], 'revision' => $values['revision']]);
            return response()->json(['data' => $values]);
        }, 3);
    }
}
