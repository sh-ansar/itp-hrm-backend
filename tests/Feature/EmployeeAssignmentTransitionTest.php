<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeAssignmentTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = (string) Str::uuid();
        $department = (string) Str::uuid();
        $newDepartment = (string) Str::uuid();
        $position = (string) Str::uuid();
        $newPosition = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $assignment = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Primary']);
        DB::table('departments')->insert([
            ['id' => $department, 'company_id' => $company, 'name' => 'Operations'],
            ['id' => $newDepartment, 'company_id' => $company, 'name' => 'Projects'],
        ]);
        DB::table('positions')->insert([
            ['id' => $position, 'company_id' => $company, 'name' => 'Engineer'],
            ['id' => $newPosition, 'company_id' => $company, 'name' => 'Lead engineer'],
        ]);
        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'EMP-T',
            'last_name' => 'Transition',
            'first_name' => 'Test',
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => 'hr_specialist',
        ]);
        DB::table('employee_assignments')->insert([
            'id' => $assignment,
            'employee_id' => $employee,
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'basis_reference' => 'Initial order',
            'revision' => 1,
        ]);

        config()->set('hrm.personnel_writes_enabled', true);

        return compact('company', 'department', 'newDepartment', 'position', 'newPosition', 'employee', 'assignment', 'user');
    }

    private function endpoint(string $company, string $employee, string $assignment): string
    {
        return "/api/v1/companies/{$company}/employees/{$employee}/assignments/{$assignment}/transition";
    }

    public function test_transition_closes_current_and_creates_next_in_one_transaction(): void
    {
        extract($this->fixture());

        $response = $this->actingAs($user)->postJson($this->endpoint($company, $employee, $assignment), [
            'department_id' => $newDepartment,
            'position_id' => $newPosition,
            'valid_from' => '2026-07-01',
            'valid_to' => null,
            'basis_reference' => 'Transfer order 7',
            'revision' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.closed_assignment.valid_to', '2026-06-30')
            ->assertJsonPath('data.closed_assignment.revision', 2)
            ->assertJsonPath('data.new_assignment.valid_from', '2026-07-01')
            ->assertJsonPath('data.new_assignment.revision', 1);

        $newId = $response->json('data.new_assignment.id');
        $this->assertDatabaseHas('employee_assignments', [
            'id' => $assignment,
            'valid_to' => '2026-06-30',
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('employee_assignments', [
            'id' => $newId,
            'employee_id' => $employee,
            'department_id' => $newDepartment,
            'position_id' => $newPosition,
            'valid_from' => '2026-07-01',
            'valid_to' => null,
            'basis_reference' => 'Transfer order 7',
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'personnel.assignment_transitioned',
        ]);
    }

    public function test_stale_transition_leaves_current_open_and_creates_nothing(): void
    {
        extract($this->fixture());
        DB::table('employee_assignments')->where('id', $assignment)->update(['revision' => 2]);

        $this->actingAs($user)->postJson($this->endpoint($company, $employee, $assignment), [
            'department_id' => $newDepartment,
            'position_id' => $newPosition,
            'valid_from' => '2026-07-01',
            'basis_reference' => 'Stale transfer',
            'revision' => 1,
        ])->assertConflict();

        $this->assertDatabaseCount('employee_assignments', 1);
        $this->assertDatabaseHas('employee_assignments', [
            'id' => $assignment,
            'valid_to' => null,
            'revision' => 2,
        ]);
        $this->assertDatabaseMissing('hr_audit_events', ['action' => 'personnel.assignment_transitioned']);
    }

    public function test_transition_rejects_same_or_earlier_start_without_partial_close(): void
    {
        extract($this->fixture());

        $this->actingAs($user)->postJson($this->endpoint($company, $employee, $assignment), [
            'department_id' => $newDepartment,
            'position_id' => $newPosition,
            'valid_from' => '2026-01-01',
            'basis_reference' => 'Invalid transfer',
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['valid_from']);

        $this->assertDatabaseCount('employee_assignments', 1);
        $this->assertDatabaseHas('employee_assignments', [
            'id' => $assignment,
            'valid_to' => null,
            'revision' => 1,
        ]);
    }

    public function test_already_closed_assignment_cannot_transition(): void
    {
        extract($this->fixture());
        DB::table('employee_assignments')->where('id', $assignment)->update(['valid_to' => '2026-06-30']);

        $this->actingAs($user)->postJson($this->endpoint($company, $employee, $assignment), [
            'department_id' => $newDepartment,
            'position_id' => $newPosition,
            'valid_from' => '2026-07-01',
            'basis_reference' => 'Duplicate transfer',
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment']);

        $this->assertDatabaseCount('employee_assignments', 1);
        $this->assertDatabaseMissing('hr_audit_events', ['action' => 'personnel.assignment_transitioned']);
    }
}
