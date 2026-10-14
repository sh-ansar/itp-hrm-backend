<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set('hrm.leave_writes_enabled', true);
        $admin = User::factory()->create();
        $hr = User::factory()->create();
        $employee = (string) Str::uuid();
        $company = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Main Company']);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company,
            'personnel_number' => 'EMP-55', 'first_name' => 'Example', 'last_name' => 'Person',
        ]);
        foreach ([[$admin, 'company_admin'], [$hr, 'hr_specialist']] as [$user, $role]) {
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $user->id, 'role_code' => $role,
            ]);
        }
        return compact('company', 'admin', 'hr', 'employee');
    }

    private function leaveType(User $admin, string $company, string $method = 'calendar_days'): string
    {
        return $this->actingAs($admin)->postJson("/api/v1/companies/{$company}/leave-types", [
            'code' => 'annual', 'name' => 'Annual paid leave',
            'balance_method' => $method, 'annual_days' => 24,
        ])->assertCreated()->json('data.id');
    }

    private function data(string $employee, string $type, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee,
            'leave_type_id' => $type,
            'planned_started_on' => '2026-10-01',
            'planned_ended_on' => '2026-10-05',
            'note' => null,
        ], $overrides);
    }

    public function test_leave_write_endpoints_remain_off_until_approved_activation(): void
    {
        $admin = User::factory()->create();
        $company = (string) Str::uuid();
        $this->actingAs($admin)->postJson("/api/v1/companies/{$company}/leave-requests", [])
            ->assertNotFound();
        $this->actingAs($admin)->postJson("/api/v1/companies/{$company}/leave-types", [])
            ->assertNotFound();
    }

    public function test_hr_draft_submit_admin_approval_publishes_once_and_audits(): void
    {
        extract($this->fixture());
        $type = $this->leaveType($admin, $company);
        $base = "/api/v1/companies/{$company}/leave-requests";

        $this->actingAs($hr)->getJson("/api/v1/companies/{$company}/leave-types")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.can_manage_leave_types', false);

        $draft = $this->actingAs($hr)->postJson($base, $this->data($employee, $type))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.planned_days', 5)
            ->assertJsonPath('data.revision', 1)->json('data');
        $id = $draft['id'];

        $this->actingAs($hr)->putJson($base.'/'.$id, $this->data($employee, $type, [
            'planned_ended_on' => '2026-10-06', 'revision' => 1,
        ]))->assertOk()->assertJsonPath('data.planned_days', 6)->assertJsonPath('data.revision', 2);

        $this->actingAs($hr)->putJson($base.'/'.$id, $this->data($employee, $type, [
            'revision' => 1,
        ]))->assertStatus(409);
        $this->actingAs($hr)->postJson($base.'/'.$id.'/submit', ['revision' => 2])
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.revision', 3);

        $this->actingAs($hr)->postJson($base.'/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertForbidden();

        $approved = $this->actingAs($admin)->postJson($base.'/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.revision', 4)->json('data');

        $this->assertNotNull($approved['published_leave_id']);
        $this->assertDatabaseHas('employee_leaves', [
            'id' => $approved['published_leave_id'],
            'employee_id' => $employee,
            'planned_days' => 6,
            'actual_started_on' => null,
        ]);
        $this->actingAs($hr)->getJson("/api/v1/companies/{$company}/leaves?year=2026")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.planned_days', 6);

        $this->actingAs($admin)->postJson($base.'/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertStatus(409);
        $this->actingAs($admin)->postJson($base.'/'.$id.'/cancel', [
            'revision' => 4,
        ])->assertStatus(409);

        $this->assertDatabaseCount('employee_leaves', 1);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'leave.request_approved',
        ]);
        $this->actingAs($hr)->getJson($base)->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('meta.can_approve_leave_requests', false);
        $this->actingAs($admin)->getJson($base)->assertOk()
            ->assertJsonPath('meta.can_approve_leave_requests', true);
    }

    public function test_cross_company_employee_type_and_overlap_are_rejected(): void
    {
        extract($this->fixture());
        $type = $this->leaveType($admin, $company);
        $otherCompany = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $otherCompany, 'name' => 'Other']);
        DB::table('employees')->insert([
            'id' => $otherEmployee, 'company_id' => $otherCompany,
            'personnel_number' => 'OUT', 'first_name' => 'Out', 'last_name' => 'Company',
        ]);
        $base = "/api/v1/companies/{$company}/leave-requests";

        $this->actingAs($hr)->postJson($base, $this->data($otherEmployee, $type))->assertUnprocessable();
        $this->actingAs($hr)->postJson($base, $this->data($employee, (string) Str::uuid()))->assertUnprocessable();
        $this->actingAs($hr)->postJson($base, $this->data($employee, $type))->assertCreated();
        $this->actingAs($hr)->postJson($base, $this->data($employee, $type, [
            'planned_started_on' => '2026-10-03',
            'planned_ended_on' => '2026-10-07',
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_working_day_policy_requires_complete_calendar_and_counts_only_workdays(): void
    {
        extract($this->fixture());
        $type = $this->leaveType($admin, $company, 'working_days');
        $url = "/api/v1/companies/{$company}/leave-requests";

        $this->actingAs($hr)->postJson($url, $this->data($employee, $type, [
            'planned_started_on' => '2026-10-01', 'planned_ended_on' => '2026-10-03',
        ]))->assertUnprocessable();

        foreach ([
            ['2026-10-01', 'workday'],
            ['2026-10-02', 'holiday'],
            ['2026-10-03', 'shortened'],
        ] as [$date, $typeName]) {
            DB::table('production_calendar_days')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company,
                'work_date' => $date, 'day_type' => $typeName,
                'planned_minutes' => $typeName === 'holiday' ? 0 : 480,
                'revision' => 1,
            ]);
        }

        $this->actingAs($hr)->postJson($url, $this->data($employee, $type, [
            'planned_started_on' => '2026-10-01', 'planned_ended_on' => '2026-10-03',
        ]))->assertCreated()->assertJsonPath('data.planned_days', 2);
    }

    public function test_own_approval_or_rejecting_unsubmitted_request_is_prohibited(): void
    {
        extract($this->fixture());
        $type = $this->leaveType($admin, $company);
        $base = "/api/v1/companies/{$company}/leave-requests";

        $created = $this->actingAs($admin)->postJson($base, $this->data($employee, $type))
            ->assertCreated()->json('data');
        $this->actingAs($admin)->postJson($base.'/'.$created['id'].'/decision', [
            'revision' => 1, 'decision' => 'rejected', 'comment' => 'Not possible',
        ])->assertStatus(409);
        $this->actingAs($admin)->postJson($base.'/'.$created['id'].'/submit', [
            'revision' => 1,
        ])->assertOk();
        $this->actingAs($admin)->postJson($base.'/'.$created['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertForbidden();

        $this->actingAs($hr)->postJson($base.'/'.$created['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertForbidden();
        $this->assertDatabaseCount('employee_leaves', 0);
    }
}
