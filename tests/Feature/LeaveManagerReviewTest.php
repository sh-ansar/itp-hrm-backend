<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LeaveManagerReviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 22));
        config()->set([
            'hrm.leave_writes_enabled' => true,
            'hrm.leave_manager_approval_enabled' => true,
            'hrm.leave_balance_writes_enabled' => true,
        ]);
        $company = (string) Str::uuid();
        $department = (string) Str::uuid();
        $otherDepartment = (string) Str::uuid();
        $position = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        $type = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic review company']);
        foreach ([$department, $otherDepartment] as $id) {
            DB::table('departments')->insert(['id' => $id, 'company_id' => $company, 'name' => $id]);
        }
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Synthetic position']);
        foreach ([$employee, $otherEmployee] as $id) {
            DB::table('employees')->insert(['id' => $id, 'company_id' => $company,
                'personnel_number' => $id, 'first_name' => 'Synthetic', 'last_name' => 'Employee']);
            DB::table('employee_assignments')->insert(['id' => (string) Str::uuid(),
                'employee_id' => $id, 'department_id' => $id === $employee ? $department : $otherDepartment,
                'position_id' => $position, 'valid_from' => '2026-01-01']);
        }
        $staff = User::factory()->create();
        $hr = User::factory()->create();
        $manager = User::factory()->create();
        $admin = User::factory()->create();
        foreach ([[$staff, 'employee'], [$hr, 'hr_specialist'], [$manager, 'department_manager'],
            [$admin, 'company_admin']] as [$user, $role]) {
            DB::table('company_user_roles')->insert(['company_id' => $company, 'user_id' => $user->id, 'role_code' => $role]);
        }
        DB::table('department_access_scopes')->insert(['company_id' => $company,
            'user_id' => $manager->id, 'department_id' => $department]);
        DB::table('employee_user_links')->insert(['company_id' => $company, 'user_id' => $staff->id,
            'employee_id' => $employee, 'revision' => 1]);
        DB::table('leave_types')->insert(['id' => $type, 'company_id' => $company, 'code' => 'annual',
            'name' => 'Sensitive type', 'category' => 'ordinary', 'annual_days' => 24, 'balance_method' => 'calendar_days']);
        $base = "/api/v1/companies/{$company}";
        $payload = ['leave_type_id' => $type, 'planned_started_on' => '2026-11-01',
            'planned_ended_on' => '2026-11-05', 'note' => 'Private employee correspondence'];
        return compact('company', 'department', 'otherDepartment', 'employee', 'otherEmployee',
            'type', 'staff', 'hr', 'manager', 'admin', 'base', 'payload');
    }

    private function submit(array $f): array
    {
        $row = $this->actingAs($f['staff'])->postJson($f['base'].'/me/leave-requests', $f['payload'])
            ->assertCreated()->json('data');
        return $this->postJson($f['base'].'/me/leave-requests/'.$row['id'].'/submit', ['revision' => 1])
            ->assertOk()->json('data');
    }

    public function test_employee_manager_hr_flow_changes_balance_only_at_final_approval_and_audits_both_decisions(): void
    {
        $f = $this->fixture(); extract($f);
        $this->actingAs($admin)->postJson($base.'/leave-balances/periods', [
            'employee_id' => $employee, 'leave_type_id' => $type, 'year' => 2026, 'granted_days' => 10,
            'reason' => 'Reviewed synthetic entitlement',
        ])->assertCreated();
        $row = $this->submit($f);
        $url = $base.'/leave-requests/'.$row['id'];
        $this->actingAs($hr)->postJson($url.'/decision', ['revision' => 2, 'decision' => 'approved'])->assertConflict();
        $this->actingAs($manager)->getJson($base.'/leave-requests')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.note', null)
            ->assertJsonPath('data.0.leave_type_id', null)->assertJsonPath('data.0.leave_type_name', null)
            ->assertJsonPath('data.0.category', null)->assertJsonPath('data.0.evidence_status', null)
            ->assertJsonPath('meta.can_manage_leave_requests', false)
            ->assertJsonPath('meta.can_review_leave_requests', true);
        $this->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.revision', 3)->assertJsonMissing(['note' => 'Private employee correspondence']);
        $this->assertDatabaseCount('employee_leaves', 0);
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.available_days', 10)->assertJsonPath('data.0.pending_days', 5);
        $this->getJson($base.'/me/workspace')->assertJsonPath('data.requests.0.manager_recommendation', 'approved');
        $this->actingAs($hr)->postJson($url.'/decision', ['revision' => 2, 'decision' => 'approved'])->assertConflict();
        $this->postJson($url.'/decision', ['revision' => 3, 'decision' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.available_days', 5)->assertJsonPath('data.0.pending_days', 0)
            ->assertJsonPath('data.0.booked_days', 5);
        $this->assertDatabaseCount('employee_leaves', 1);
        foreach (['leave.request_manager_approved', 'leave.request_approved'] as $action) {
            $this->assertDatabaseHas('hr_audit_events', ['company_id' => $company, 'action' => $action]);
        }
        $this->actingAs($hr)->getJson($base.'/leave-types')->assertJsonPath('meta.can_manage_leave_types', false);
    }

    public function test_manager_cannot_read_other_department_or_write_final_decisions_or_edit_requests(): void
    {
        $f = $this->fixture(); extract($f);
        $row = $this->submit($f);
        DB::table('employee_assignments')->where('employee_id', $employee)->update(['department_id' => $otherDepartment]);
        $url = $base.'/leave-requests/'.$row['id'];
        $this->actingAs($manager)->getJson($base.'/leave-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])->assertNotFound();
        $this->postJson($url.'/decision', ['revision' => 2, 'decision' => 'approved'])->assertForbidden();
        $this->postJson($url.'/cancel', ['revision' => 2])->assertForbidden();
        $this->putJson($url, $payload + ['employee_id' => $employee, 'revision' => 2])->assertForbidden();
        $foreign = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $foreign, 'name' => 'Unrelated company']);
        $this->postJson("/api/v1/companies/{$foreign}/leave-requests/{$row['id']}/manager-decision",
            ['revision' => 2, 'decision' => 'approved'])->assertForbidden();
    }

    public function test_rejection_requires_reason_is_terminal_and_does_not_publish_or_deduct(): void
    {
        $f = $this->fixture(); extract($f);
        $row = $this->submit($f);
        $url = $base.'/leave-requests/'.$row['id'];
        $this->actingAs($manager)->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'rejected'])
            ->assertUnprocessable();
        $this->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'rejected', 'comment' => 'Scheduling conflict'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson($url.'/manager-decision', ['revision' => 3, 'decision' => 'approved'])->assertConflict();
        $this->actingAs($hr)->postJson($url.'/decision', ['revision' => 3, 'decision' => 'approved'])->assertConflict();
        $this->assertDatabaseCount('employee_leaves', 0);
        $this->assertDatabaseHas('hr_audit_events', ['action' => 'leave.request_manager_rejected']);
        $this->actingAs($staff)->getJson($base.'/me/workspace')
            ->assertJsonPath('data.requests.0.manager_decision_comment', 'Scheduling conflict');
    }

    public function test_own_request_repeated_review_and_revoked_scope_are_denied(): void
    {
        $f = $this->fixture(); extract($f);
        $row = $this->submit($f);
        $url = $base.'/leave-requests/'.$row['id'];
        DB::table('employee_user_links')->where('user_id', $staff->id)->delete();
        DB::table('employee_user_links')->insert(['company_id' => $company, 'user_id' => $manager->id,
            'employee_id' => $employee, 'revision' => 1]);
        $this->actingAs($manager)->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])
            ->assertForbidden();
        DB::table('employee_user_links')->where('user_id', $manager->id)->delete();
        $this->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])->assertOk();
        $this->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])->assertConflict();
        $this->postJson($url.'/manager-decision', ['revision' => 3, 'decision' => 'approved'])->assertConflict();
        DB::table('department_access_scopes')->where('user_id', $manager->id)->delete();
        $this->getJson($base.'/leave-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($url.'/manager-decision', ['revision' => 3, 'decision' => 'rejected', 'comment' => 'No scope'])
            ->assertNotFound();
    }

    public function test_reviewer_cannot_finalize_after_role_change_and_hr_rejection_keeps_balance_unbooked(): void
    {
        $f = $this->fixture(); extract($f);
        $row = $this->submit($f);
        $url = $base.'/leave-requests/'.$row['id'];
        $this->actingAs($manager)->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])->assertOk();
        DB::table('company_user_roles')->where('company_id', $company)->where('user_id', $manager->id)
            ->update(['role_code' => 'hr_specialist']);
        $this->postJson($url.'/decision', ['revision' => 3, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($hr)->postJson($url.'/decision', ['revision' => 3, 'decision' => 'rejected'])
            ->assertUnprocessable();
        $this->postJson($url.'/decision', ['revision' => 3, 'decision' => 'rejected', 'comment' => 'HR policy conflict'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseCount('employee_leaves', 0);
        $this->assertDatabaseHas('hr_audit_events', ['action' => 'leave.request_rejected']);
    }

    public function test_flag_off_preserves_legacy_admin_contract_and_prohibits_manager_and_hr_approval(): void
    {
        $f = $this->fixture(); extract($f);
        config()->set('hrm.leave_manager_approval_enabled', false);
        $row = $this->submit($f);
        $url = $base.'/leave-requests/'.$row['id'];
        $this->actingAs($manager)->getJson($base.'/leave-requests')->assertForbidden();
        $this->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($hr)->postJson($url.'/decision', ['revision' => 2, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($admin)->postJson($url.'/decision', ['revision' => 2, 'decision' => 'approved'])->assertOk();
    }

    public function test_employee_or_hr_cannot_forge_manager_decision_or_manager_metadata(): void
    {
        $f = $this->fixture(); extract($f);
        $row = $this->submit($f);
        $url = $base.'/leave-requests/'.$row['id'];
        foreach ([$staff, $hr, $admin] as $actor) {
            $this->actingAs($actor)->postJson($url.'/manager-decision', [
                'revision' => 2, 'decision' => 'approved',
            ])->assertForbidden();
        }
        $this->actingAs($hr)->postJson($url.'/decision', [
            'revision' => 2, 'decision' => 'approved',
            'manager_recommendation' => 'approved', 'manager_reviewed_by_user_id' => $manager->id,
        ])->assertConflict();
        $this->assertDatabaseHas('leave_requests', ['id' => $row['id'], 'manager_recommendation' => null, 'revision' => 2]);
        $this->assertDatabaseCount('employee_leaves', 0);
    }

    public function test_selected_department_head_must_have_the_matching_scoped_login(): void
    {
        $f = $this->fixture(); extract($f);
        config()->set('hrm.leave_manager_route_snapshot_enabled', true);
        // The one manager with a scope is not linked to the selected employee.
        DB::table('departments')->where('id', $department)
            ->update(['manager_employee_id' => $otherEmployee]);
        $this->actingAs($staff)->postJson($base.'/me/leave-requests', $payload)
            ->assertCreated();
        $draft = DB::table('leave_requests')->where('employee_id', $employee)->first();
        $this->postJson($base.'/me/leave-requests/'.$draft->id.'/submit', ['revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('manager');
        // Linking the actual department head to the scoped manager permits
        // submission, without permitting a different manager to approve.
        DB::table('employee_user_links')->insert([
            'company_id' => $company, 'employee_id' => $otherEmployee,
            'user_id' => $manager->id, 'revision' => 1,
        ]);
        $this->postJson($base.'/me/leave-requests/'.$draft->id.'/submit', ['revision' => 1])
            ->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertDatabaseHas('leave_requests', [
            'id' => $draft->id, 'assigned_manager_user_id' => $manager->id,
            'manager_department_id' => $department,
        ]);
    }

    public function test_explicit_manager_route_is_pinned_and_other_manager_in_same_department_cannot_approve(): void
    {
        $f = $this->fixture(); extract($f);
        config()->set('hrm.leave_manager_route_snapshot_enabled', true);
        $row = $this->submit($f);
        $url = $base.'/leave-requests/'.$row['id'];
        $this->assertDatabaseHas('leave_requests', [
            'id' => $row['id'], 'assigned_manager_user_id' => $manager->id, 'manager_department_id' => $department,
        ]);
        // Request owner sees the actual pinned reviewer, not internal HR notes.
        $this->actingAs($staff)->getJson($base.'/me/workspace')
            ->assertOk()->assertJsonPath('data.requests.0.assigned_manager_name', $manager->name);
        $this->actingAs($hr)->getJson($base.'/me/workspace')
            ->assertOk()->assertJsonCount(0, 'data.requests');

        $second = User::factory()->create();
        DB::table('company_user_roles')->insert(['company_id' => $company,
            'user_id' => $second->id, 'role_code' => 'department_manager']);
        DB::table('department_access_scopes')->insert(['company_id' => $company,
            'user_id' => $second->id, 'department_id' => $department]);

        $this->actingAs($second)->getJson($base.'/leave-requests')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])
            ->assertForbidden();

        $this->actingAs($manager)->getJson($base.'/leave-requests')->assertJsonCount(1, 'data');
        $this->postJson($url.'/manager-decision', ['revision' => 2, 'decision' => 'approved'])
            ->assertOk()->assertJsonPath('data.manager_recommendation', 'approved');
    }

    public function test_four_role_approval_keeps_original_reviewer_after_head_rotation(): void
    {
        $f = $this->fixture();
        extract($f);
        config()->set([
            'hrm.leave_manager_route_snapshot_enabled' => true,
            'hrm.personnel_writes_enabled' => true,
        ]);

        $headOne = (string) Str::uuid();
        $headTwo = (string) Str::uuid();
        foreach ([$headOne, $headTwo] as $index => $head) {
            DB::table('employees')->insert([
                'id' => $head, 'company_id' => $company,
                'personnel_number' => 'HEAD-'.$index,
                'first_name' => 'Synthetic', 'last_name' => 'Department Head',
            ]);
            DB::table('employee_assignments')->insert([
                'id' => (string) Str::uuid(), 'employee_id' => $head,
                'department_id' => $department, 'position_id' => DB::table('positions')->where('company_id', $company)->value('id'),
                'valid_from' => '2026-01-01',
            ]);
        }

        $nextManager = User::factory()->create();
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $nextManager->id, 'role_code' => 'department_manager',
        ]);
        DB::table('department_access_scopes')->insert([
            'company_id' => $company, 'user_id' => $nextManager->id, 'department_id' => $department,
        ]);
        foreach ([[$headOne, $manager], [$headTwo, $nextManager]] as [$head, $account]) {
            DB::table('employee_user_links')->insert([
                'company_id' => $company, 'employee_id' => $head,
                'user_id' => $account->id, 'revision' => 1,
            ]);
        }
        DB::table('departments')->where('id', $department)
            ->update(['manager_employee_id' => $headOne]);

        $this->actingAs($admin)->postJson($base.'/leave-balances/periods', [
            'employee_id' => $employee, 'leave_type_id' => $type,
            'year' => 2026, 'granted_days' => 20,
            'reason' => 'Isolated P0 acceptance baseline',
        ])->assertCreated();

        $first = $this->submit($f);
        $oldUrl = $base.'/leave-requests/'.$first['id'];
        $this->assertDatabaseHas('leave_requests', [
            'id' => $first['id'], 'assigned_manager_user_id' => $manager->id,
            'manager_department_id' => $department, 'revision' => 2,
        ]);

        // The same authorized administrator changes the explicit head using
        // the public API with revision checking; no direct role escalation.
        $this->actingAs($admin)->putJson($base.'/departments/'.$department, [
            'name' => $department, 'parent_id' => null,
            'manager_employee_id' => $headTwo, 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertDatabaseHas('departments', [
            'id' => $department, 'manager_employee_id' => $headTwo, 'revision' => 2,
        ]);

        $second = $this->actingAs($staff)->postJson($base.'/me/leave-requests', array_merge($payload, [
            'planned_started_on' => '2026-11-10',
            'planned_ended_on' => '2026-11-12',
        ]))->assertCreated()->json('data');
        $this->postJson($base.'/me/leave-requests/'.$second['id'].'/submit', ['revision' => 1])
            ->assertOk()->assertJsonPath('data.status', 'submitted');
        $newUrl = $base.'/leave-requests/'.$second['id'];
        $this->assertDatabaseHas('leave_requests', [
            'id' => $second['id'], 'assigned_manager_user_id' => $nextManager->id,
            'manager_department_id' => $department, 'revision' => 2,
        ]);

        $this->actingAs($manager)->getJson($base.'/leave-requests')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first['id']);
        $this->actingAs($nextManager)->getJson($base.'/leave-requests')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second['id']);

        $this->actingAs($manager)->postJson($newUrl.'/manager-decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertForbidden();
        $this->actingAs($nextManager)->postJson($oldUrl.'/manager-decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertForbidden();
        $this->actingAs($hr)->postJson($oldUrl.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertConflict();

        $this->actingAs($manager)->postJson($oldUrl.'/manager-decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.revision', 3);
        $this->actingAs($nextManager)->postJson($newUrl.'/manager-decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.revision', 3);
        $this->actingAs($staff)->postJson($oldUrl.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertForbidden();
        $this->actingAs($hr)->postJson($oldUrl.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson($newUrl.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseCount('employee_leaves', 2);
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertOk()->assertJsonPath('data.0.available_days', 12)
            ->assertJsonPath('data.0.booked_days', 8);
        $this->assertSame(2, DB::table('hr_audit_events')->where('company_id', $company)
            ->where('action', 'leave.request_manager_approved')->count());
        $this->assertSame(2, DB::table('hr_audit_events')->where('company_id', $company)
            ->where('action', 'leave.request_approved')->count());
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'department.updated',
        ]);
    }

    public function test_manager_routing_fails_closed_for_missing_and_ambiguous_reviewer(): void
    {
        $f = $this->fixture(); extract($f);
        config()->set('hrm.leave_manager_route_snapshot_enabled', true);
        $draft = $this->actingAs($staff)->postJson($base.'/me/leave-requests', $payload)
            ->assertCreated()->json('data');
        $url = $base.'/me/leave-requests/'.$draft['id'].'/submit';

        DB::table('department_access_scopes')->where('company_id', $company)->delete();
        $this->postJson($url, ['revision' => 1])->assertUnprocessable();
        DB::table('department_access_scopes')->insert(['company_id' => $company,
            'user_id' => $manager->id, 'department_id' => $department]);

        $second = User::factory()->create();
        DB::table('company_user_roles')->insert(['company_id' => $company,
            'user_id' => $second->id, 'role_code' => 'department_manager']);
        DB::table('department_access_scopes')->insert(['company_id' => $company,
            'user_id' => $second->id, 'department_id' => $department]);
        $this->postJson($url, ['revision' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('leave_requests', [
            'id' => $draft['id'], 'status' => 'draft', 'revision' => 1, 'assigned_manager_user_id' => null,
        ]);
    }
}
