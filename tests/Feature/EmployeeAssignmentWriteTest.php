<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeAssignmentWriteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'hr_specialist'): array
    {
        $company = (string) Str::uuid();
        $otherCompany = (string) Str::uuid();
        $department = (string) Str::uuid();
        $otherDepartment = (string) Str::uuid();
        $position = (string) Str::uuid();
        $otherPosition = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Primary'],
            ['id' => $otherCompany, 'name' => 'Other'],
        ]);

        DB::table('departments')->insert([
            ['id' => $department, 'company_id' => $company, 'name' => 'Operations'],
            ['id' => $otherDepartment, 'company_id' => $otherCompany, 'name' => 'Foreign'],
        ]);

        DB::table('positions')->insert([
            ['id' => $position, 'company_id' => $company, 'name' => 'Engineer'],
            ['id' => $otherPosition, 'company_id' => $otherCompany, 'name' => 'Foreign role'],
        ]);

        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'EMP-100',
            'last_name' => 'Test',
            'first_name' => 'Person',
        ]);

        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $role,
        ]);

        return compact(
            'company',
            'otherCompany',
            'department',
            'otherDepartment',
            'position',
            'otherPosition',
            'employee',
            'user',
        );
    }

    private function endpoint(string $company, string $employee): string
    {
        return "/api/v1/companies/{$company}/employees/{$employee}/assignments";
    }

    public function test_write_is_fail_closed_when_personnel_writes_are_disabled(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', false);

        $this->actingAs($user)->postJson($this->endpoint($company, $employee), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
        ])->assertNotFound();

        $this->assertDatabaseCount('employee_assignments', 0);
    }

    public function test_hr_can_create_non_overlapping_assignment_and_audit_is_atomic(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);

        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $employee,
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2025-01-01',
            'valid_to' => '2025-12-31',
        ]);

        $response = $this->actingAs($user)->postJson($this->endpoint($company, $employee), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
            'basis_reference' => 'Order HR-2026-01',
        ])->assertCreated()
            ->assertJsonPath('data.employee_id', $employee)
            ->assertJsonPath('data.valid_from', '2026-01-01');

        $assignmentId = $response->json('data.id');

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $assignmentId,
            'employee_id' => $employee,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);

        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'personnel.assignment_created',
        ]);
    }

    public function test_overlapping_assignment_is_rejected_without_partial_write_or_audit(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);

        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $employee,
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-06-30',
        ]);

        $this->actingAs($user)->postJson($this->endpoint($company, $employee), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-06-30',
            'valid_to' => '2026-12-31',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['valid_from']);

        $this->assertDatabaseCount('employee_assignments', 1);
        $this->assertDatabaseMissing('hr_audit_events', [
            'action' => 'personnel.assignment_created',
        ]);
    }

    public function test_foreign_department_or_position_is_rejected(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);

        $this->actingAs($user)->postJson($this->endpoint($company, $employee), [
            'department_id' => $otherDepartment,
            'position_id' => $otherPosition,
            'valid_from' => '2026-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment']);

        $this->assertDatabaseCount('employee_assignments', 0);
    }

    public function test_department_manager_cannot_create_assignment(): void
    {
        extract($this->fixture('department_manager'));
        config()->set('hrm.personnel_writes_enabled', true);

        $this->actingAs($user)->postJson($this->endpoint($company, $employee), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
        ])->assertForbidden();

        $this->assertDatabaseCount('employee_assignments', 0);
    }
}
