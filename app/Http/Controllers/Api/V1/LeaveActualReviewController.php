<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Leave\LeaveDayCounter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reconcile actual leave without silently changing an approved planned period.
 * A confirmed unused leave is distinguished from missing actual evidence.
 */
final class LeaveActualReviewController extends Controller
{
    public function update(Request $request, string $company, string $leave,
        LeaveDayCounter $counter, HrAuditWriter $audit): JsonResponse
    {
        abort_unless((bool) config('hrm.leave_writes_enabled', false), 404);
        $role = DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
        if (array_diff(array_keys($request->all()),
            ['revision', 'state', 'actual_started_on', 'actual_ended_on', 'reason']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected actual leave review field.']);
        }
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'state' => ['required', Rule::in(['taken','not_taken'])],
            'actual_started_on' => ['required_if:state,taken', 'nullable', 'date_format:Y-m-d'],
            'actual_ended_on' => ['required_if:state,taken', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        if ($data['state'] === 'not_taken'
            && (!empty($data['actual_started_on']) || !empty($data['actual_ended_on']))) {
            throw ValidationException::withMessages(['actual_started_on' => 'An unused leave has no actual dates.']);
        }
        return DB::transaction(function () use ($request, $company, $leave, $counter, $audit, $data): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $row = DB::table('employee_leaves as l')
                ->join('employees as e','e.id','=','l.employee_id')
                ->join('leave_types as lt','lt.id','=','l.leave_type_id')
                ->where('l.id',$leave)->where('e.company_id',$company)
                ->where('lt.company_id',$company)
                ->whereNull('l.cancelled_at')
                ->lockForUpdate()
                ->first(['l.id','l.employee_id','l.leave_type_id','l.planned_started_on',
                    'l.planned_ended_on','l.actual_started_on','l.actual_ended_on',
                    'l.revision','l.actual_days','l.actual_review_state']);
            abort_unless($row, 404);
            abort_unless((int) $row->revision === (int) $data['revision'], 409,
                'The leave record changed. Reload it before reviewing actual dates.');
            $today = now()->toDateString();
            if ($data['state'] === 'not_taken') {
                if ($row->planned_ended_on >= $today) {
                    throw ValidationException::withMessages([
                        'state' => 'Not-taken confirmation is only permitted after the planned period ended.',
                    ]);
                }
                $start = null;
                $end = null;
                $days = 0;
            } else {
                $start = (string) $data['actual_started_on'];
                $end = (string) $data['actual_ended_on'];
                if ($start > $end || $end > $today) {
                    throw ValidationException::withMessages([
                        'actual_ended_on' => 'Actual dates must form a completed interval and cannot be in the future.',
                    ]);
                }
                $days = $counter->count($company, $row->leave_type_id, $start, $end);
            }
            // Reopening an already carried/closed entitlement would make the
            // next year's credited balance inconsistent with verified actuals.
            // The company lock serializes this check against concurrent carryover.
            $years = [];
            foreach ([$row->planned_started_on, $row->planned_ended_on,
                $row->actual_started_on, $row->actual_ended_on, $start, $end] as $date) {
                if ($date !== null) $years[] = (int) substr($date, 0, 4);
            }
            $closed = DB::table('leave_balance_periods')
                ->where('company_id', $company)
                ->where('employee_id', $row->employee_id)
                ->where('leave_type_id', $row->leave_type_id)
                ->whereIn('year', range(min($years), max($years)))
                ->whereNotNull('closed_at')->exists();
            abort_if($closed, 409,
                'The entitlement year is closed. Reopen it through a reviewed ledger correction before changing actual leave.');

            $updated = DB::table('employee_leaves')->where('id', $leave)
                ->where('revision', $row->revision)->whereNull('cancelled_at')
                ->update([
                    'actual_started_on' => $start,'actual_ended_on' => $end,
                    'actual_days' => $days,
                    'actual_review_state' => $data['state'],
                    'actual_review_note' => trim($data['reason']),
                    'actual_reviewed_at' => now(),
                    'actual_reviewed_by_user_id' => $request->user()->getAuthIdentifier(),
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now(),
                ]);
            abort_unless($updated === 1, 409);
            $audit->record($request->user(), $company, HrAuditAction::LeaveActualReviewed, null, [
                'leave_id' => $row->id,'employee_id' => $row->employee_id,
                'state' => $data['state'],'actual_started_on' => $start,
                'actual_ended_on' => $end,'actual_days' => $days,
                'previous_actual_days' => $row->actual_days,
                'previous_actual_review_state' => $row->actual_review_state,
                'reason' => trim($data['reason']),
                'revision' => (int) $row->revision + 1,
            ]);
            return response()->json(['data' => [
                'id' => $row->id, 'revision' => (int) $row->revision + 1,
                'actual_review_state' => $data['state'],
                'actual_started_on' => $start, 'actual_ended_on' => $end,
                'actual_days' => $days,
            ]])->header('Cache-Control','private, no-store');
        },3);
    }
}
