<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorktimePeriodController extends Controller
{
    public function show(Request $request, string $company, WorkforceAuthorization $permissions): JsonResponse
    {
        $this->assertEnabled();
        abort_unless($permissions->canEditTimesheet($request->user(), $company), 403);
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        abort_unless(DB::table('companies')->where('id', $company)->exists(), 404);
        $period = DB::table('worktime_periods')->where('company_id', $company)->where('month', $month)->first();
        return response()->json(['data' => $period ?? [
            'company_id' => $company,
            'month' => $month,
            'status' => 'draft',
            'revision' => 0,
        ], 'meta' => ['can_submit' => true,
            'can_approve' => $this->admin($request, $company),
            'can_reopen' => $this->admin($request, $company)]]);
    }

    public function transition(
        Request $request, string $company, WorkforceAuthorization $permissions,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->assertEnabled();
        abort_unless($permissions->canEditTimesheet($request->user(), $company), 403);
        $input = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'action' => ['required', 'in:submit,approve,reopen'],
            'revision' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ]);
        $admin = $this->admin($request, $company);
        if (in_array($input['action'], ['approve', 'reopen'], true)) abort_unless($admin, 403);
        if ($input['action'] === 'reopen') abort_unless(!empty($input['reason']), 422);

        return DB::transaction(function () use ($request, $company, $input, $audit): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $current = DB::table('worktime_periods')->where('company_id', $company)
                ->where('month', $input['month'])->lockForUpdate()->first();
            $revision = $current !== null ? (int) $current->revision : 0;
            abort_unless($revision === (int) $input['revision'], 409, 'The period changed; reload.');
            $status = $current?->status ?? 'draft';
            $transition = $input['action'];
            abort_unless(match ($transition) {
                'submit' => $status === 'draft',
                'approve' => $status === 'submitted',
                'reopen' => $status === 'approved',
            }, 409, 'Invalid timesheet period transition.');
            if ($transition === 'submit') {
                $employeeIds = DB::table('employees')->where('company_id', $company)->pluck('id');
                $from = $input['month'].'-01';
                $to = date('Y-m-t', strtotime($from));
                abort_unless(DB::table('worktime_entries')->whereIn('employee_id', $employeeIds)
                    ->whereBetween('work_date', [$from, $to])->exists(), 409,
                    'Enter at least one day before submitting a timesheet period.');
            }
            if ($transition === 'approve') {
                abort_if((int) $current->submitted_by_user_id === (int) $request->user()->getAuthIdentifier(),
                    403, 'The submitter cannot approve the same timesheet period.');
            }
            $values = [
                'status' => match ($transition) {
                    'submit' => 'submitted', 'approve' => 'approved', 'reopen' => 'draft',
                },
                'revision' => $revision + 1,
                'updated_at' => now(),
            ];
            if ($transition === 'submit') {
                $values['submitted_by_user_id'] = $request->user()->getAuthIdentifier();
                $values['submitted_at'] = now();
                $values['approved_at'] = null;
                $values['approved_by_user_id'] = null;
            } elseif ($transition === 'approve') {
                $values['approved_by_user_id'] = $request->user()->getAuthIdentifier();
                $values['approved_at'] = now();
            } else {
                $values['reopen_reason'] = $input['reason'];
            }
            if ($current === null) {
                DB::table('worktime_periods')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $company,
                    'month' => $input['month'], 'created_at' => now(), ...$values,
                ]);
            } else {
                $changed = DB::table('worktime_periods')->where('id', $current->id)
                    ->where('revision', $revision)->update($values);
                abort_unless($changed === 1, 409);
            }
            $audit->record($request->user(), $company, match ($transition) {
                'submit' => HrAuditAction::WorktimePeriodSubmitted,
                'approve' => HrAuditAction::WorktimePeriodApproved,
                'reopen' => HrAuditAction::WorktimePeriodReopened,
            }, null, ['month' => $input['month'], 'revision' => $revision + 1,
                'reason' => $transition === 'reopen' ? $input['reason'] : null]);
            return response()->json(['data' => DB::table('worktime_periods')
                ->where('company_id', $company)->where('month', $input['month'])->first()]);
        }, 3);
    }

    private function assertEnabled(): void
    {
        abort_unless((bool) config('hrm.worktime_writes_enabled', false)
            && (bool) config('hrm.worktime_period_approvals_enabled', false), 404);
    }

    private function admin(Request $request, string $company): bool
    {
        return DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('role_code', 'company_admin')->exists();
    }
}
