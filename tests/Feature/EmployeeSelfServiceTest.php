<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set(['hrm.leave_writes_enabled' => true, 'hrm.role_management_enabled' => true]);
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $type = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic company']);
        foreach ([[$admin, 'company_admin'], [$user, 'employee'], [$other, 'employee']] as [$actor, $role]) {
            DB::table('company_user_roles')->insert(['company_id' => $company, 'user_id' => $actor->id, 'role_code' => $role]);
        }
        DB::table('employees')->insert(['id' => $employee, 'company_id' => $company, 'personnel_number' => 'SYNTH-SELF',
            'last_name' => 'Synthetic', 'first_name' => 'Person']);
        DB::table('leave_types')->insert(['id' => $type, 'company_id' => $company, 'code' => 'parental',
            'name' => 'Parental', 'category' => 'maternity', 'balance_method' => 'calendar_days']);
        $base = "/api/v1/companies/{$company}";
        $link = $base.'/memberships/'.$user->id.'/employee-link';
        $payload = ['leave_type_id' => $type, 'planned_started_on' => '2026-11-01', 'planned_ended_on' => '2026-11-05', 'note' => 'Synthetic request'];
        return compact('admin', 'user', 'other', 'company', 'employee', 'type', 'base', 'link', 'payload');
    }

    public function test_explicit_link_revision_uniqueness_tenant_and_role_guards(): void
    {
        extract($this->fixture());
        $this->actingAs($user)->getJson($base.'/me/workspace')->assertOk()->assertJsonPath('data.employee', null);
        $this->postJson($base.'/me/leave-requests', $payload)->assertForbidden();
        $this->putJson($link, ['employee_id' => $employee, 'revision' => 0])->assertForbidden();
        $this->actingAs($admin)->putJson($link, ['employee_id' => $employee, 'revision' => 0])->assertOk()->assertJsonPath('data.revision', 1);
        $this->putJson($link, ['employee_id' => null, 'revision' => 0])->assertConflict();
        $this->putJson($base.'/memberships/'.$other->id.'/employee-link', ['employee_id' => $employee, 'revision' => 0])->assertUnprocessable();
        $foreign = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $foreign, 'name' => 'Foreign']);
        $foreignEmployee = (string) Str::uuid();
        DB::table('employees')->insert(['id' => $foreignEmployee, 'company_id' => $foreign, 'personnel_number' => 'X', 'last_name' => 'Foreign', 'first_name' => 'Person']);
        $this->putJson($link, ['employee_id' => $foreignEmployee, 'revision' => 1])->assertUnprocessable();
        $this->actingAs($user)->getJson("/api/v1/companies/{$foreign}/me/workspace")->assertForbidden();
        $this->actingAs($admin)->putJson($link, ['employee_id' => null, 'revision' => 1])->assertOk();
        $this->actingAs($user)->postJson($base.'/me/leave-requests', $payload)->assertForbidden();
        $this->assertDatabaseCount('hr_audit_events', 2);
    }

    public function test_employee_draft_edit_submit_approval_reload_and_no_administrative_access(): void
    {
        extract($this->fixture());
        $this->actingAs($admin)->putJson($link, ['employee_id' => $employee, 'revision' => 0])->assertOk();
        $this->actingAs($user)->postJson($base.'/me/leave-requests', $payload + ['employee_id' => $employee])->assertUnprocessable();
        $id = $this->postJson($base.'/me/leave-requests', $payload)->assertCreated()->assertJsonPath('data.planned_days', 5)->json('data.id');
        $this->putJson($base.'/me/leave-requests/'.$id, $payload + ['revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->postJson($base.'/me/leave-requests/'.$id.'/submit', ['revision' => 1])->assertConflict();
        $this->postJson($base.'/me/leave-requests/'.$id.'/submit', ['revision' => 2])->assertOk();
        $this->getJson($base.'/leave-requests')->assertForbidden();
        $this->postJson($base.'/leave-requests/'.$id.'/decision', ['revision' => 3, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($other)->getJson($base.'/me/workspace')->assertOk()->assertJsonCount(0, 'data.requests');
        $this->postJson($base.'/me/leave-requests/'.$id.'/cancel', ['revision' => 3])->assertForbidden();
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision', ['revision' => 3, 'decision' => 'approved'])->assertOk();
        $response = $this->actingAs($user)->getJson($base.'/me/workspace')->assertOk()
            ->assertJsonPath('data.requests.0.status', 'approved')->assertJsonCount(1, 'data.leaves')
            ->assertJsonCount(1, 'data.leave_types');
        $this->assertArrayNotHasKey('created_by_user_id', $response->json('data.requests.0'));
        $this->postJson($base.'/me/leave-requests/'.$id.'/cancel', ['revision' => 4])->assertConflict();
        $this->assertDatabaseCount('employee_leaves', 1);
        $this->getJson($base.'/employees')->assertForbidden();
    }

    public function test_linked_other_account_cannot_read_or_modify_another_authors_request_and_flag_is_enforced(): void
    {
        extract($this->fixture());
        $this->actingAs($admin)->putJson($link, ['employee_id' => $employee, 'revision' => 0])->assertOk();
        $id = $this->actingAs($user)->postJson($base.'/me/leave-requests', $payload)->assertCreated()->json('data.id');
        $second = (string) Str::uuid();
        DB::table('employees')->insert(['id' => $second, 'company_id' => $company, 'personnel_number' => 'SECOND', 'last_name' => 'Second', 'first_name' => 'Person']);
        $this->actingAs($admin)->putJson($base.'/memberships/'.$other->id.'/employee-link', ['employee_id' => $second, 'revision' => 0])->assertOk();
        $this->actingAs($other)->getJson($base.'/me/workspace')->assertOk()->assertJsonCount(0, 'data.requests');
        $this->putJson($base.'/me/leave-requests/'.$id, $payload + ['revision' => 1])->assertNotFound();
        $this->postJson($base.'/me/leave-requests/'.$id.'/submit', ['revision' => 1])->assertNotFound();
        config()->set('hrm.leave_writes_enabled', false);
        $this->actingAs($user)->postJson($base.'/me/leave-requests', $payload)->assertNotFound();
        $this->getJson($base.'/me/workspace')->assertOk()->assertJsonPath('meta.can_request', false);
    }

    public function test_approver_cannot_decide_their_own_employee_leave_created_by_hr(): void
    {
        extract($this->fixture());
        $this->actingAs($admin)->putJson($base.'/memberships/'.$admin->id.'/employee-link', ['employee_id' => $employee, 'revision' => 0])->assertOk();
        DB::table('company_user_roles')->where('user_id', $other->id)->update(['role_code' => 'hr_specialist']);
        $request = $this->actingAs($other)->postJson($base.'/leave-requests', $payload + ['employee_id' => $employee])->assertCreated()->json('data');
        $this->postJson($base.'/leave-requests/'.$request['id'].'/submit', ['revision' => 1])->assertOk();
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$request['id'].'/decision', ['revision' => 2, 'decision' => 'approved'])->assertForbidden();
    }

    public function test_employee_own_timesheet_reads_only_linked_records_without_fabricating_hours(): void
    {
        extract($this->fixture());
        $url = $base.'/me/timesheet?month=2026-10';

        $this->actingAs($user)->getJson($url)->assertForbidden();
        $this->actingAs($admin)->putJson($link, ['employee_id' => $employee, 'revision' => 0])->assertOk();

        DB::table('production_calendar_days')->insert([
            ['id' => (string) Str::uuid(), 'company_id' => $company, 'work_date' => '2026-10-15',
                'day_type' => 'workday', 'planned_minutes' => 480, 'label' => null, 'revision' => 1],
            ['id' => (string) Str::uuid(), 'company_id' => $company, 'work_date' => '2026-10-16',
                'day_type' => 'holiday', 'planned_minutes' => 0, 'label' => 'Synthetic holiday', 'revision' => 1],
        ]);
        DB::table('worktime_entries')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee, 'work_date' => '2026-10-15',
            'planned_minutes' => 480, 'worked_minutes' => 420, 'absence_minutes' => 60,
            'overtime_minutes' => 0, 'revision' => 1,
        ]);

        // Employee read remains possible even with administrative timesheet writes disabled.
        config()->set('hrm.worktime_writes_enabled', false);
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store')
            ->assertJsonPath('meta.can_edit', false)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.worked_minutes', 420)
            ->assertJsonPath('data.0.absence_minutes', 60)
            ->assertJsonPath('data.1.day_type', 'holiday')
            ->assertJsonPath('data.1.worked_minutes', null);
        $this->getJson($url.'&employee_id='.((string) Str::uuid()))->assertUnprocessable();
        $this->getJson($base.'/me/timesheet?month=2026-99')->assertUnprocessable();
        $this->getJson($base.'/me/timesheet')->assertUnprocessable();

        $this->actingAs($other)->getJson($url)->assertForbidden();
        $otherEmployee = (string) Str::uuid();
        DB::table('employees')->insert(['id' => $otherEmployee, 'company_id' => $company,
            'personnel_number' => 'SYNTH-OTHER', 'last_name' => 'Other', 'first_name' => 'Employee']);
        $this->actingAs($admin)->putJson($base.'/memberships/'.$other->id.'/employee-link',
            ['employee_id' => $otherEmployee, 'revision' => 0])->assertOk();
        $this->actingAs($other)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.worked_minutes', null);
        $this->actingAs($user)->getJson($url)->assertJsonPath('data.0.worked_minutes', 420);
        DB::table('company_user_roles')->where('company_id', $company)->where('user_id', $user->id)->delete();
        $this->getJson($url)->assertForbidden();
    }

}
