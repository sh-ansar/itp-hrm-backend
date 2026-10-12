<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeAssignmentUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Primary']);
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'Operations']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Engineer']);
        DB::table('employees')->insert([
            [
                'id' => $employee,
                'company_id' => $company,
                'personnel_number' => 'EMP-1',
                'last_name' => 'Test',
                'first_name' => 'One',
            ],
            [
                'id' => $otherEmployee,
                'company_id' => $company,
                'personnel_number' => 'EMP-2',
                'last_name' => 'Test',
                'first_name' => 'Two',
            ],
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => 'hr_specialist',
        ]);
        DB::table('employee_assignments')->insert([
            [
                'id' => $first,
                'employee_id' => $employee,
                'department_id' => $department,
                'position_id' => $position,
                'valid_from' => '2025-01-01',
                'valid_to' => '2025-12-31',
                'basis_reference' => 'Order 1',
                'revision' => 1,
            ],
            [
                'id' => $second,
                'employee_id' => $employee,
                'department_id' => $department,
                'position_id' => $position,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'basis_reference' => 'Order 2',
                'revision' => 1,
            ],
        ]);

        config()->set('hrm.personnel_writes_enabled', true);

        return compact('company', 'department', 'position', 'employee', 'otherEmployee', 'first', 'second', 'user');
    }

    private function endpoint(string $company, string $employee, string $assignment): string
    {
        return "/api/v1/companies/{$company}/employees/{$employee}/assignments/{$assignment}";
    }

    public function test_hr_can_close_assignment_with_revision_and_audit(): void
    {
        extract($this->fixture());

        $this->actingAs($user)->putJson($this->endpoint($company, $employee, $second), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'basis_reference' => 'Order 2 close',
            'revision' => 1,
        ])->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.valid_to', '2026-12-31');

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $second,
            'valid_to' => '2026-12-31',
            'basis_reference' => 'Order 2 close',
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'personnel.assignment_updated',
        ]);
    }

    public function test_stale_revision_is_rejected_without_overwrite_or_audit(): void
    {
        extract($this->fixture());

        DB::table('employee_assignments')->where('id', $second)->update([
            'basis_reference' => 'Changed elsewhere',
            'revision' => 2,
        ]);

        $this->actingAs($user)->putJson($this->endpoint($company, $employee, $second), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'basis_reference' => 'Stale edit',
            'revision' => 1,
        ])->assertConflict();

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $second,
            'basis_reference' => 'Changed elsewhere',
            'revision' => 2,
        ]);
        $this->assertDatabaseMissing('hr_audit_events', [
            'action' => 'personnel.assignment_updated',
        ]);
    }

    public function test_update_cannot_expand_assignment_into_another_period(): void
    {
        extract($this->fixture());

        $this->actingAs($user)->putJson($this->endpoint($company, $employee, $first), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2025-01-01',
            'valid_to' => '2026-01-01',
            'basis_reference' => 'Overlap attempt',
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['valid_from']);

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $first,
            'valid_to' => '2025-12-31',
            'basis_reference' => 'Order 1',
            'revision' => 1,
        ]);
        $this->assertDatabaseMissing('hr_audit_events', [
            'action' => 'personnel.assignment_updated',
        ]);
    }

    public function test_assignment_owned_by_another_employee_is_not_addressable(): void
    {
        extract($this->fixture());

        $this->actingAs($user)->putJson($this->endpoint($company, $otherEmployee, $first), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2025-01-01',
            'valid_to' => '2025-12-31',
            'basis_reference' => 'Wrong owner',
            'revision' => 1,
        ])->assertNotFound();

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $first,
            'employee_id' => $employee,
            'revision' => 1,
        ]);
    }

    public function test_put_rejects_unexpected_identity_fields(): void
    {
        extract($this->fixture());

        $this->actingAs($user)->putJson($this->endpoint($company, $employee, $second), [
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'basis_reference' => 'Order 2',
            'revision' => 1,
            'employee_id' => $otherEmployee,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment']);

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $second,
            'employee_id' => $employee,
            'revision' => 1,
        ]);
    }
}
