<?php

namespace App\Domain\Leave;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApprovedLeaveReversal
{
    public function __construct(private readonly HrAuditWriter $audit) {}

    /**
     * A reversible future booking, never a deletion of personnel history.
     * Retroactive, actual-attendance and closed-year changes require separate HR corrections.
     */
    public function reverse(User $actor, string $company, string $id, int $expectedRevision, string $reason): array
    {
        abort_unless(config('hrm.approved_leave_reversal_enabled', false), 404);
        return DB::transaction(function () use ($actor, $company, $id, $expectedRevision, $reason): array {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $request = DB::table('leave_requests')->where('company_id', $company)
                ->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $request->revision === $expectedRevision, 409, 'Leave request revision changed.');
            abort_unless($request->status === 'approved' && $request->published_leave_id, 409,
                'Only an approved and published leave can be reversed.');
            abort_if($request->planned_started_on <= now()->toDateString(), 409,
                'Started or past leave requires a separate factual-attendance correction.');
            $leave = DB::table('employee_leaves')->where('id', $request->published_leave_id)
                ->where('employee_id', $request->employee_id)->lockForUpdate()->firstOrFail();
            abort_if($leave->cancelled_at !== null
                || $leave->actual_started_on !== null
                || $leave->actual_ended_on !== null
                || $leave->actual_days !== null, 409,
                'Cannot reverse an already cancelled or factually recorded leave.');

            $years = range((int) substr($request->planned_started_on, 0, 4),
                (int) substr($request->planned_ended_on, 0, 4));
            abort_if(DB::table('leave_balance_periods')->where('company_id', $company)
                ->where('employee_id', $request->employee_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->whereIn('year', $years)->whereNotNull('closed_at')->exists(), 409,
                'Leave entitlement year is already closed.');
            abort_if(DB::table('worktime_entries')->where('employee_id', $request->employee_id)
                ->whereBetween('work_date', [$request->planned_started_on, $request->planned_ended_on])
                ->exists(), 409, 'Recorded attendance prevents automatic reversal.');

            $now = now();
            $changed = DB::table('employee_leaves')->where('id', $leave->id)
                ->whereNull('cancelled_at')->where('revision', $leave->revision)
                ->update([
                    'cancelled_at' => $now,
                    'cancelled_by_user_id' => $actor->getAuthIdentifier(),
                    'cancellation_reason' => $reason,
                    'revision' => (int) $leave->revision + 1,
                    'updated_at' => $now,
                ]);
            abort_unless($changed === 1, 409);
            $newRevision = $expectedRevision + 1;
            $changed = DB::table('leave_requests')->where('company_id', $company)
                ->where('id', $id)->where('revision', $expectedRevision)
                ->update([
                    'status' => 'cancelled',
                    'revision' => $newRevision,
                    'reversed_at' => $now,
                    'reversed_by_user_id' => $actor->getAuthIdentifier(),
                    'reversal_reason' => $reason,
                    'updated_at' => $now,
                ]);
            abort_unless($changed === 1, 409);
            $this->audit->record($actor, $company, HrAuditAction::LeaveRequestApprovedReversed, null, [
                'request_id' => $id, 'published_leave_id' => $leave->id,
                'employee_id' => $request->employee_id, 'revision' => $newRevision,
            ]);
            return [
                'id' => $id, 'employee_id' => $request->employee_id,
                'status' => 'cancelled', 'revision' => $newRevision,
                'reversed_at' => $now->toIso8601String(),
                'reversal_reason' => $reason,
            ];
        }, 3);
    }
}
