<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Leave\LeaveBalanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\AdjustLeaveBalanceRequest;
use App\Http\Requests\Leave\CarryLeaveBalanceRequest;
use App\Http\Requests\Leave\OpenLeaveBalanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LeaveBalanceController extends Controller
{
    private function role(Request $request, string $company): ?string
    {
        return DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())->value('role_code');
    }

    private function validatedYear(Request $request): int
    {
        if (array_diff(array_keys($request->query()), ['year', 'employee_id']) !== []) {
            throw ValidationException::withMessages(['query' => 'Unexpected leave balance filters.']);
        }
        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);
        return (int) $validated['year'];
    }

    public function index(Request $request, string $company, LeaveBalanceService $balances): JsonResponse
    {
        abort_unless(in_array($this->role($request, $company), ['company_admin', 'hr_specialist'], true), 403);
        $year = $this->validatedYear($request);
        $validated = $request->validate(['employee_id' => ['required', 'uuid']]);
        return response()->json([
            'data' => $balances->forEmployee($company, $validated['employee_id'], $year),
            'meta' => ['year' => $year, 'can_manage' => $this->canManage($request, $company),
                'enforcement_enabled' => (bool) config('hrm.leave_balance_enforcement_enabled', false)],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function mine(Request $request, string $company, LeaveBalanceService $balances): JsonResponse
    {
        abort_unless(in_array($this->role($request, $company),
            ['company_admin', 'hr_specialist', 'department_manager', 'employee'], true), 403);
        // Reject every supplied employee identity, including aliases and unrelated filters.
        if (array_diff(array_keys($request->query()), ['year']) !== []) {
            throw ValidationException::withMessages(['query' => 'Own balance accepts only year.']);
        }
        $year = $this->validatedYear($request);
        $employee = DB::table('employee_user_links')
            ->where('company_id', $company)->where('user_id', $request->user()->getAuthIdentifier())
            ->value('employee_id');
        abort_unless($employee, 403, 'Account is not linked to an employee.');

        return response()->json([
            'data' => $balances->forEmployee($company, $employee, $year),
            'meta' => ['year' => $year, 'can_manage' => false,
                'enforcement_enabled' => (bool) config('hrm.leave_balance_enforcement_enabled', false)],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function canManage(Request $request, string $company): bool
    {
        return $this->role($request, $company) === 'company_admin'
            && (bool) config('hrm.leave_writes_enabled', false)
            && (bool) config('hrm.leave_balance_writes_enabled', false);
    }

    private function journal(Request $request, string $period, string $kind,
        int $delta, string $reason, ?string $source = null): void
    {
        DB::table('leave_balance_entries')->insert([
            'id' => (string) Str::uuid(),
            'period_id' => $period,
            'kind' => $kind, 'delta_days' => $delta,
            'reason' => trim($reason), 'source_period_id' => $source,
            'created_by_user_id' => $request->user()->getAuthIdentifier(),
            'created_at' => now(),
        ]);
    }

    public function open(OpenLeaveBalanceRequest $request, string $company,
        LeaveBalanceService $balances, HrAuditWriter $audit): JsonResponse
    {
        $data = $request->validated();
        return DB::transaction(function () use ($request, $company, $data, $balances, $audit): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $balances->assertEmployee($company, $data['employee_id']);
            $type = $balances->type($company, $data['leave_type_id']);
            $year = (int) $data['year'];
            $exists = DB::table('leave_balance_periods')
                ->where('company_id', $company)->where('employee_id', $data['employee_id'])
                ->where('leave_type_id', $type->id)->where('year', $year)->exists();
            abort_if($exists, 409, 'The entitlement year is already configured.');

            $id = (string) Str::uuid();
            DB::table('leave_balance_periods')->insert([
                'id' => $id, 'company_id' => $company, 'employee_id' => $data['employee_id'],
                'leave_type_id' => $type->id, 'year' => $year,
                'granted_days' => (int) $data['granted_days'], 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->journal($request, $id, 'grant', (int) $data['granted_days'], $data['reason']);
            $audit->record($request->user(), $company, HrAuditAction::LeaveBalancePeriodOpened, null,
                ['period_id' => $id, 'employee_id' => $data['employee_id'], 'year' => $year,
                    'leave_type_id' => $type->id, 'granted_days' => (int) $data['granted_days']]);
            $period = $balances->period($company, $id);
            return response()->json([
                'data' => $balances->snapshot($company, $data['employee_id'], $type, $year, $period),
            ], 201)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function adjust(AdjustLeaveBalanceRequest $request, string $company, string $period,
        LeaveBalanceService $balances, HrAuditWriter $audit): JsonResponse
    {
        $data = $request->validated();
        return DB::transaction(function () use ($request, $company, $period, $balances, $audit, $data): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $row = $balances->period($company, $period);
            abort_unless((int) $row->revision === (int) $data['revision'], 409);
            abort_if($row->closed_at !== null, 409, 'A closed year cannot be adjusted.');
            $type = $balances->type($company, $row->leave_type_id);
            $current = $balances->snapshot($company, $row->employee_id, $type, (int) $row->year, $row);
            $delta = (int) $data['delta_days'];
            if ($current['available_days'] === null) {
                throw ValidationException::withMessages([
                    'delta_days' => 'Cannot modify entitlement before all approved bookings can be counted.',
                ]);
            }
            if ($current['available_days'] + $delta < 0
                || abs((int) $row->adjustment_days + $delta) > 3660) {
                throw ValidationException::withMessages([
                    'delta_days' => 'Adjustment would produce an unsupported or negative remaining balance.',
                ]);
            }
            $changed = DB::table('leave_balance_periods')->where('id', $period)
                ->where('revision', $row->revision)->whereNull('closed_at')
                ->update(['adjustment_days' => (int) $row->adjustment_days + $delta,
                    'revision' => (int) $row->revision + 1, 'updated_at' => now()]);
            abort_unless($changed === 1, 409);
            $this->journal($request, $period, 'adjustment', $delta, $data['reason']);
            $audit->record($request->user(), $company, HrAuditAction::LeaveBalanceAdjusted, null, [
                'period_id' => $period, 'year' => (int) $row->year, 'delta_days' => $delta,
            ]);
            return response()->json(['data' => $balances->snapshot($company, $row->employee_id,
                $type, (int) $row->year, $balances->period($company, $period))])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function carryover(CarryLeaveBalanceRequest $request, string $company, string $period,
        LeaveBalanceService $balances, HrAuditWriter $audit): JsonResponse
    {
        $data = $request->validated();
        return DB::transaction(function () use ($request, $company, $period, $data, $balances, $audit): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $target = $balances->period($company, $period);
            $source = $balances->period($company, $data['source_period_id']);
            abort_unless((int) $target->revision === (int) $data['revision'], 409);
            abort_if($target->closed_at !== null || $target->carry_from_period_id !== null
                || $source->closed_at !== null, 409, 'The transfer period is closed or already transferred.');
            abort_unless((int) $source->year + 1 === (int) $target->year
                && $source->employee_id === $target->employee_id
                && $source->leave_type_id === $target->leave_type_id
                && $source->id !== $target->id, 422);
            $type = $balances->type($company, $target->leave_type_id);
            $prior = $balances->snapshot($company, $source->employee_id, $type, (int) $source->year, $source);
            $current = $balances->snapshot($company, $target->employee_id, $type, (int) $target->year, $target);
            $days = (int) $data['days'];
            if ($prior['available_days'] === null || $current['available_days'] === null) {
                throw ValidationException::withMessages([
                    'days' => 'Leave calendar must be complete before carrying over unused days.',
                ]);
            }
            if ($prior['pending_days'] !== 0) {
                throw ValidationException::withMessages([
                    'days' => 'Review or cancel all pending prior-year requests before closing the year.',
                ]);
            }
            if ($prior['available_days'] < $days) {
                throw ValidationException::withMessages([
                    'days' => 'Cannot carry more days than the verified previous-year balance.',
                ]);
            }

            // Closing the prior year and crediting the target are atomic.
            $closed = DB::table('leave_balance_periods')->where('id', $source->id)
                ->where('revision', $source->revision)->whereNull('closed_at')
                ->update(['closed_at' => now(), 'closing_available_days' => $prior['available_days'],
                    'revision' => (int) $source->revision + 1, 'updated_at' => now()]);
            abort_unless($closed === 1, 409);
            $updated = DB::table('leave_balance_periods')->where('id', $target->id)
                ->where('revision', $target->revision)->whereNull('carry_from_period_id')
                ->update(['carried_days' => (int) $target->carried_days + $days,
                    'carry_from_period_id' => $source->id, 'revision' => (int) $target->revision + 1,
                    'updated_at' => now()]);
            abort_unless($updated === 1, 409);
            $this->journal($request, $source->id, 'closure', 0, $data['reason']);
            $this->journal($request, $target->id, 'carryover', $days, $data['reason'], $source->id);
            $audit->record($request->user(), $company, HrAuditAction::LeaveBalanceCarried, null, [
                'employee_id' => $target->employee_id, 'source_period_id' => $source->id,
                'target_period_id' => $target->id, 'days' => $days,
                'source_closing_available_days' => $prior['available_days'],
            ]);
            return response()->json(['data' => $balances->snapshot($company, $target->employee_id,
                $type, (int) $target->year, $balances->period($company, $target->id))])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
