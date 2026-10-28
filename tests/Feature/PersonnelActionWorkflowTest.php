<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonnelActionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = (string) Str::uuid();
        $departmentA = (string) Str::uuid();
        $departmentB = (string) Str::uuid();
        $positionA = (string) Str::uuid();
        $positionB = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Company']);
        DB::table('departments')->insert([
            ['id' => $departmentA, 'company_id' => $company, 'name' => 'Finance', 'revision' => 1],
            ['id' => $departmentB, 'company_id' => $company, 'name' => 'Operations', 'revision' => 1],
        ]);
        DB::table('positions')->insert([
            ['id' => $positionA, 'company_id' => $company, 'name' => 'Analyst', 'revision' => 1],
            ['id' => $positionB, 'company_id' => $company, 'name' => 'Lead', 'revision' => 1],
        ]);
        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'EMP-ACT-1',
            'last_name' => 'Action',
            'first_name' => 'Employee',
            'revision' => 1,
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => 'hr_specialist',
        ]);

        config()->set('hrm.personnel_writes_enabled', true);

        return compact(
            'company',
            'departmentA',
            'departmentB',
            'positionA',
            'positionB',
            'employee',
            'user',
        );
    }

    public function test_hire_transfer_termination_are_atomic_and_journaled(): void
    {
        extract($this->fixture());

        $hire = $this->actingAs($user)->postJson(
            "/api/v1/companies/{$company}/employees/{$employee}/personnel-actions/hire",
            [
                'department_id' => $departmentA,
                'position_id' => $positionA,
                'effective_on' => '2026-01-10',
                'basis_reference' => 'ORDER-HIRE-001',
                'notes' => 'Initial hiring',
            ]
        )->assertCreated()
            ->assertJsonPath('data.action_type', 'hire');

        $firstAssignment = $hire->json('data.target_assignment_id');

        $transfer = $this->actingAs($user)->postJson(
            "/api/v1/companies/{$company}/employees/{$employee}/personnel-actions/transfer",
            [
                'source_assignment_id' => $firstAssignment,
                'source_assignment_revision' => 1,
                'department_id' => $departmentB,
                'position_id' => $positionB,
                'effective_on' => '2026-06-01',
                'basis_reference' => 'ORDER-TRANSFER-002',
                'notes' => null,
            ]
        )->assertCreated()
            ->assertJsonPath('data.action_type', 'transfer')
            ->assertJsonPath('data.source_assignment_closed_on', '2026-05-31');

        $secondAssignment = $transfer->json('data.target_assignment_id');

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $firstAssignment,
            'valid_to' => '2026-05-31',
            'revision' => 2,
        ]);

        $this->actingAs($user)->postJson(
            "/api/v1/companies/{$company}/employees/{$employee}/personnel-actions/terminate",
            [
                'source_assignment_id' => $secondAssignment,
                'source_assignment_revision' => 1,
                'effective_on' => '2026-09-30',
                'basis_reference' => 'ORDER-TERM-003',
                'notes' => 'End of employment',
            ]
        )->assertCreated()
            ->assertJsonPath('data.action_type', 'termination');

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $secondAssignment,
            'valid_to' => '2026-09-30',
            'revision' => 2,
        ]);
        $this->assertDatabaseCount('personnel_actions', 3);
        $this->assertDatabaseHas('personnel_actions', [
            'company_id' => $company,
            'employee_id' => $employee,
            'action_type' => 'hire',
            'basis_reference' => 'ORDER-HIRE-001',
        ]);
        $this->assertDatabaseHas('personnel_actions', [
            'company_id' => $company,
            'employee_id' => $employee,
            'action_type' => 'transfer',
            'basis_reference' => 'ORDER-TRANSFER-002',
        ]);
        $this->assertDatabaseHas('personnel_actions', [
            'company_id' => $company,
            'employee_id' => $employee,
            'action_type' => 'termination',
            'basis_reference' => 'ORDER-TERM-003',
        ]);

        $this->assertDatabaseCount('hr_audit_events', 3);
    }

    public function test_archive_is_derived_after_last_working_day_and_rehire_keeps_history(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28)->startOfDay());
        extract($this->fixture());
        $base = "/api/v1/companies/{$company}";
        $employeeUrl = $base."/employees";
        $actionsUrl = $base."/employees/{$employee}/personnel-actions";

        $first = $this->actingAs($user)->postJson($actionsUrl.'/hire', [
            'department_id' => $departmentA,
            'position_id' => $positionA,
            'effective_on' => '2026-01-10',
            'basis_reference' => 'P1-HIRE-001',
        ])->assertCreated()->json('data.target_assignment_id');

        $this->actingAs($user)->postJson($actionsUrl.'/terminate', [
            'source_assignment_id' => $first,
            'source_assignment_revision' => 1,
            'effective_on' => '2026-10-28',
            'basis_reference' => 'P1-TERM-002',
        ])->assertCreated();

        // The termination date is the inclusive last day of employment.
        $this->getJson($employeeUrl.'?status=active')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.employment_status', 'active');
        $this->getJson($employeeUrl.'?status=archived')->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->travelTo(now()->setDate(2026, 10, 29)->startOfDay());
        $this->getJson($employeeUrl.'?status=active')->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson($employeeUrl.'?status=archived')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $employee)
            ->assertJsonPath('data.0.employment_status', 'archived');

        // Rehire creates a new appointment. The old termination and dates remain immutable.
        $second = $this->actingAs($user)->postJson($actionsUrl.'/hire', [
            'department_id' => $departmentB,
            'position_id' => $positionB,
            'effective_on' => '2026-10-29',
            'basis_reference' => 'P1-REHIRE-003',
        ])->assertCreated()->json('data.target_assignment_id');

        $this->getJson($employeeUrl.'?status=active')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.current_position_name', 'Lead');
        $this->getJson($employeeUrl.'?status=archived')->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson($base.'/personnel-actions')->assertOk()
            ->assertJsonPath('meta.total', 3);
        $this->assertNotSame($first, $second);
        $this->assertDatabaseHas('employee_assignments', [
            'id' => $first, 'valid_to' => '2026-10-28',
        ]);
        $this->assertDatabaseHas('employee_assignments', [
            'id' => $second, 'valid_from' => '2026-10-29', 'valid_to' => null,
        ]);
        $this->assertDatabaseCount('personnel_actions', 3);
        $this->assertDatabaseCount('hr_audit_events', 11);
    }

    public function test_stale_transfer_revision_does_not_create_action_or_target_assignment(): void
    {
        extract($this->fixture());

        $assignment = (string) Str::uuid();
        DB::table('employee_assignments')->insert([
            'id' => $assignment,
            'employee_id' => $employee,
            'department_id' => $departmentA,
            'position_id' => $positionA,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'basis_reference' => 'BASE',
            'revision' => 2,
        ]);

        $before = DB::table('employee_assignments')->count();

        $this->actingAs($user)->postJson(
            "/api/v1/companies/{$company}/employees/{$employee}/personnel-actions/transfer",
            [
                'source_assignment_id' => $assignment,
                'source_assignment_revision' => 1,
                'department_id' => $departmentB,
                'position_id' => $positionB,
                'effective_on' => '2026-06-01',
                'basis_reference' => 'STALE',
            ]
        )->assertConflict();

        $this->assertSame($before, DB::table('employee_assignments')->count());
        $this->assertDatabaseCount('personnel_actions', 0);
        $this->assertDatabaseHas('employee_assignments', [
            'id' => $assignment,
            'valid_to' => null,
            'revision' => 2,
        ]);
    }

    public function test_journal_is_company_scoped_filterable_and_read_only_for_manager(): void
    {
        extract($this->fixture());

        $target = (string) Str::uuid();
        DB::table('employee_assignments')->insert([
            'id' => $target,
            'employee_id' => $employee,
            'department_id' => $departmentA,
            'position_id' => $positionA,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'basis_reference' => 'ORDER',
            'revision' => 1,
        ]);
        DB::table('personnel_actions')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company,
            'employee_id' => $employee,
            'action_type' => 'hire',
            'effective_on' => '2026-01-01',
            'basis_reference' => 'ORDER',
            'notes' => null,
            'source_assignment_id' => null,
            'target_assignment_id' => $target,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/personnel-actions?type=hire")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.personnel_number', 'EMP-ACT-1')
            ->assertJsonPath('data.0.target_department_name', 'Finance')
            ->assertJsonPath('data.0.target_position_name', 'Analyst')
            ->assertJsonPath('meta.personnel_actions_writes_enabled', true);

        DB::table('company_user_roles')->where('user_id', $user->id)->update([
            'role_code' => 'department_manager',
        ]);

        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/personnel-actions")
            ->assertForbidden();

        $this->actingAs($user)->postJson(
            "/api/v1/companies/{$company}/employees/{$employee}/personnel-actions/terminate",
            [
                'source_assignment_id' => $target,
                'source_assignment_revision' => 1,
                'effective_on' => '2026-02-01',
                'basis_reference' => 'DENIED',
            ]
        )->assertNotFound();
    }
}
