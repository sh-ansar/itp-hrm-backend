<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApprovedLeaveReversalTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->startOfDay());
        config()->set(['hrm.leave_writes_enabled' => true, 'hrm.approved_leave_reversal_enabled' => true,
            'hrm.leave_balance_writes_enabled' => true]);
        $admin = User::factory()->create();
        $hr = User::factory()->create();
        $staff = User::factory()->create();
        $manager = User::factory()->create();
        $outsider = User::factory()->create();
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic reversal QA']);
        DB::table('employees')->insert(['id' => $employee, 'company_id' => $company,
            'personnel_number' => 'REV-001', 'first_name' => 'Synthetic', 'last_name' => 'Worker']);
        foreach ([[$admin, 'company_admin'], [$hr, 'hr_specialist'], [$staff, 'employee'],
            [$manager, 'department_manager']] as [$actor, $role]) {
            DB::table('company_user_roles')->insert(['company_id' => $company,
                'user_id' => $actor->id, 'role_code' => $role]);
        }
        DB::table('employee_user_links')->insert(['company_id' => $company, 'user_id' => $staff->id,
            'employee_id' => $employee, 'revision' => 1]);
        $otherCompany = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $otherCompany, 'name' => 'Other synthetic company']);
        DB::table('company_user_roles')->insert(['company_id' => $otherCompany,
            'user_id' => $outsider->id, 'role_code' => 'company_admin']);
        $base = "/api/v1/companies/{$company}";
        $type = $this->actingAs($admin)->postJson($base.'/leave-types', ['code' => 'annual',
            'name' => 'QA annual leave', 'annual_days' => 24, 'balance_method' => 'calendar_days'])
            ->assertCreated()->json('data.id');
        $this->postJson($base.'/leave-balances/periods', ['employee_id' => $employee,
            'leave_type_id' => $type, 'year' => 2027, 'granted_days' => 24,
            'reason' => 'Explicit synthetic QA grant'])->assertCreated();
        $payload = ['employee_id' => $employee, 'leave_type_id' => $type,
            'planned_started_on' => '2027-02-01', 'planned_ended_on' => '2027-02-05', 'note' => null];
        $id = $this->actingAs($hr)->postJson($base.'/leave-requests', $payload)
            ->assertCreated()->json('data.id');
        $this->postJson($base.'/leave-requests/'.$id.'/submit', ['revision' => 1])->assertOk();
        $leave = $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision',
            ['revision' => 2, 'decision' => 'approved'])->assertOk()->json('data.published_leave_id');
        $url = $base.'/leave-requests/'.$id.'/reverse-approved';
        return compact('admin', 'hr', 'staff', 'manager', 'outsider', 'company', 'employee',
            'otherCompany', 'base', 'type', 'payload', 'id', 'leave', 'url');
    }

    public function test_reversal_restores_balance_removes_schedule_preserves_history_and_allows_rebooking(): void
    {
        extract($this->fixture());
        $balance = $base.'/leave-balances?employee_id='.$employee.'&year=2027';
        $this->getJson($balance)->assertOk()->assertJsonPath('data.0.available_days', 19);
        $this->getJson($base.'/leaves?year=2027')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($url, ['revision' => 3, 'reason' => '  Employee changed future plans  '])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.revision', 4)
            ->assertJsonPath('data.reversal_reason', 'Employee changed future plans');
        $this->getJson($balance)->assertOk()->assertJsonPath('data.0.available_days', 24)
            ->assertJsonPath('data.0.booked_days', 0);
        $this->getJson($base.'/leaves?year=2027')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('employee_leaves', 1);
        $this->assertDatabaseHas('employee_leaves', ['id' => $leave,
            'cancelled_by_user_id' => $admin->id, 'planned_days' => 5, 'revision' => 2]);
        $this->assertDatabaseHas('hr_audit_events', ['company_id' => $company,
            'action' => 'leave.approved_request_reversed', 'actor_user_id' => $admin->id]);
        $this->getJson($base.'/leave-requests')->assertOk()
            ->assertJsonPath('data.0.reversal_reason', 'Employee changed future plans');
        $this->actingAs($staff)->getJson($base.'/me/workspace')->assertOk()->assertJsonCount(0, 'data.leaves');
        $this->actingAs($hr)->postJson($base.'/leave-requests', $payload)->assertCreated();
    }

    public function test_gate_roles_company_and_own_employee_are_enforced_without_mutation(): void
    {
        extract($this->fixture());
        $audit = DB::table('hr_audit_events')->count();
        config()->set('hrm.approved_leave_reversal_enabled', false);
        $this->postJson($url, ['revision' => 3, 'reason' => 'A valid reversal reason'])->assertNotFound();
        config()->set('hrm.approved_leave_reversal_enabled', true);
        foreach ([$hr, $staff, $manager, $outsider] as $actor) {
            $this->actingAs($actor)->postJson($url, ['revision' => 3, 'reason' => 'A valid reversal reason'])
                ->assertForbidden();
        }
        $foreignUrl = str_replace($company, $otherCompany, $url);
        $this->actingAs($outsider)->postJson($foreignUrl, ['revision' => 3, 'reason' => 'A valid reason'])
            ->assertNotFound();
        DB::table('employee_user_links')->where('company_id', $company)->delete();
        DB::table('employee_user_links')->insert(['company_id' => $company,
            'user_id' => $admin->id, 'employee_id' => $employee, 'revision' => 1]);
        $this->actingAs($admin)->postJson($url, ['revision' => 3, 'reason' => 'A valid reason'])->assertForbidden();
        $this->assertUnchanged($id, $leave, $audit);
    }

    public function test_reason_revision_and_repeat_are_rejected_without_extra_audit(): void
    {
        extract($this->fixture());
        $audit = DB::table('hr_audit_events')->count();
        foreach (['', '    ', 'abcd', str_repeat('a', 501)] as $reason) {
            $this->postJson($url, ['revision' => 3, 'reason' => $reason])->assertUnprocessable();
        }
        $this->postJson($url, ['revision' => 2, 'reason' => 'A valid reason'])->assertConflict();
        $this->assertUnchanged($id, $leave, $audit);
        $this->postJson($url, ['revision' => 3, 'reason' => 'A valid reason'])->assertOk();
        foreach ([3, 4] as $revision) {
            $this->postJson($url, compact('revision') + ['reason' => 'Repeated cancellation'])->assertConflict();
        }
        $this->assertSame($audit + 1, DB::table('hr_audit_events')->count());
    }

    public function test_started_actual_closed_year_and_locked_timesheet_prevent_reversal(): void
    {
        extract($this->fixture());
        $audit = DB::table('hr_audit_events')->count();
        $this->travelTo(now()->setDate(2027, 2, 1));
        $this->postJson($url, ['revision' => 3, 'reason' => 'A valid reason'])->assertConflict();
        $this->travelTo(now()->setDate(2026, 10, 23));
        foreach (['actual_days' => 1, 'actual_started_on' => '2027-02-01', 'actual_ended_on' => '2027-02-05'] as $field => $value) {
            DB::table('employee_leaves')->where('id', $leave)->update([$field => $value]);
            $this->postJson($url, ['revision' => 3, 'reason' => 'A valid reason'])->assertConflict();
            DB::table('employee_leaves')->where('id', $leave)->update([$field => null]);
        }
        DB::table('leave_balance_periods')->where('employee_id', $employee)->update(['closed_at' => now()]);
        $this->postJson($url, ['revision' => 3, 'reason' => 'A valid reason'])->assertConflict();
        DB::table('leave_balance_periods')->where('employee_id', $employee)->update(['closed_at' => null]);
        DB::table('worktime_periods')->insert(['id' => (string) Str::uuid(), 'company_id' => $company,
            'month' => '2027-02', 'status' => 'approved', 'revision' => 1]);
        $this->postJson($url, ['revision' => 3, 'reason' => 'A valid reason'])->assertConflict();
        DB::table('worktime_periods')->where('company_id', $company)->delete();
        DB::table('worktime_entries')->insert(['id' => (string) Str::uuid(), 'employee_id' => $employee,
            'work_date' => '2027-02-02', 'worked_minutes' => 480, 'revision' => 1]);
        $this->postJson($url, ['revision' => 3, 'reason' => 'A valid reason'])->assertConflict();
        $this->assertUnchanged($id, $leave, $audit);
    }

    private function assertUnchanged(string $id, string $leave, int $audit): void
    {
        $this->assertDatabaseHas('leave_requests', ['id' => $id, 'status' => 'approved', 'revision' => 3]);
        $this->assertDatabaseHas('employee_leaves', ['id' => $leave, 'cancelled_at' => null]);
        $this->assertSame($audit, DB::table('hr_audit_events')->count());
    }
}
