<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Leave\LeaveDayCounter;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Audited policy edits. Immutable code/category and optimistic revision prevent
 * silent re-interpretation of already approved leave/entitlement history.
 */
final class LeaveTypeUpdateController extends Controller
{
    public function __invoke(
        Request $request,
        string $company,
        string $leaveType,
        WorkforceAuthorization $access,
        HrAuditWriter $audit,
        LeaveDayCounter $counter,
    ): JsonResponse {
        abort_unless((bool) config('hrm.leave_writes_enabled', false), 404);
        abort_unless($access->canManageLeaveTypes($request->user(), $company), 403);

        $input = $request->validate([
            'revision' => ['required','integer','between:1,2147483646'],
            'name' => ['required','string','max:255'],
            'annual_days' => ['present','nullable','integer','between:1,366'],
            'balance_method' => ['required', Rule::in(['calendar_days','working_days'])],
            'reason' => ['required','string','min:8','max:500'],
        ]);
        if (array_diff(array_keys($request->all()), array_keys($input)) !== []) {
            throw ValidationException::withMessages(['type'=>'Unexpected leave-type fields']);
        }
        $input['name']=trim($input['name']);
        $input['reason']=trim($input['reason']);
        if ($input['name']==='' || strlen($input['reason'])<8) {
            throw ValidationException::withMessages(['name'=>'Name and reason are required']);
        }

        return response()->json(DB::transaction(function () use ($request,$company,$leaveType,$input,$audit,$counter): array {
            DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();
            $current=DB::table('leave_types')->where('company_id',$company)
                ->where('id',$leaveType)->lockForUpdate()->firstOrFail();
            abort_unless((int)$current->revision===(int)$input['revision'],409,
                'Leave category changed; refresh before saving');
            if ($current->category!=='ordinary'
                && ($input['balance_method']!=='calendar_days'||$input['annual_days']!==null)) {
                throw ValidationException::withMessages(['balance_method'=>'Protected categories require calendar days and no annual quota']);
            }
            $policyChanged=$current->balance_method!==$input['balance_method'];
            $referenceChanged=(int)($current->annual_days??0)!==(int)($input['annual_days']??0);
            if ($policyChanged || $referenceChanged) {
                abort_if(DB::table('leave_balance_periods')->where('company_id',$company)
                    ->where('leave_type_id',$leaveType)->exists(),409,
                    'Existing entitlement periods must be reviewed before changing leave policy');
            }
            if ($policyChanged) {
                abort_if(DB::table('employee_leaves')->where('leave_type_id',$leaveType)->exists(),409,
                    'Published leave history prevents changing the day-count policy');
                abort_if(DB::table('leave_requests')->where('company_id',$company)
                    ->where('leave_type_id',$leaveType)->where('status','approved')->exists(),409,
                    'Approved leave requests prevent changing the day-count policy');
            }
            $updated=DB::table('leave_types')->where('id',$leaveType)->where('revision',$current->revision)
                ->update([
                    'name'=>$input['name'],
                    'annual_days'=>$input['annual_days'],
                    'balance_method'=>$input['balance_method'],
                    'revision'=>(int)$current->revision+1,
                    'updated_at'=>now(),
                ]);
            abort_unless($updated===1,409,'Leave category was edited concurrently');
            if ($policyChanged) {
                // The new method must agree with every open request; otherwise
                // roll back the type edit without altering pending employee requests.
                $pending=DB::table('leave_requests')->where('company_id',$company)
                    ->where('leave_type_id',$leaveType)->whereIn('status',['draft','submitted'])
                    ->get(['planned_started_on','planned_ended_on','planned_days']);
                foreach($pending as $leave){
                    $days=$counter->count($company,$leaveType,$leave->planned_started_on,$leave->planned_ended_on);
                    abort_unless($days===(int)$leave->planned_days,409,
                        'Existing leave requests require recalculation before changing this policy');
                }
            }
            $audit->record($request->user(),$company,HrAuditAction::LeaveTypeUpdated,null,[
                'leave_type_id'=>$leaveType,
                'revision'=>(int)$current->revision+1,
                'policy_changed'=>$policyChanged,
                'annual_reference_changed'=>$referenceChanged,
                'reason'=>$input['reason'],
            ]);
            return ['data'=>[
                'id'=>$leaveType,'code'=>$current->code,'name'=>$input['name'],
                'annual_days'=>$input['annual_days'],'balance_method'=>$input['balance_method'],
                'category'=>$current->category,'revision'=>(int)$current->revision+1,
            ]];
        },3))->header('Cache-Control','private, no-store');
    }
}
