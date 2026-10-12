<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeRegisterProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_projects_one_current_assignment_without_duplicate_employee_rows(): void
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
            ['id' => $departmentA, 'company_id' => $company, 'name' => 'Finance'],
            ['id' => $departmentB, 'company_id' => $company, 'name' => 'Projects'],
        ]);
        DB::table('positions')->insert([
            ['id' => $positionA, 'company_id' => $company, 'name' => 'Engineer'],
            ['id' => $positionB, 'company_id' => $company, 'name' => 'Lead engineer'],
        ]);
        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'EMP-101',
            'last_name' => 'Searchable',
            'first_name' => 'Person',
            'revision' => 1,
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => 'hr_specialist',
        ]);

        // Deliberately keep two active legacy rows to prove the register projection
        // does not duplicate the employee. The latest effective row wins.
        DB::table('employee_assignments')->insert([
            [
                'id' => (string) Str::uuid(),
                'employee_id' => $employee,
                'department_id' => $departmentA,
                'position_id' => $positionA,
                'valid_from' => now()->subYear()->toDateString(),
                'valid_to' => null,
                'revision' => 1,
            ],
            [
                'id' => (string) Str::uuid(),
                'employee_id' => $employee,
                'department_id' => $departmentB,
                'position_id' => $positionB,
                'valid_from' => now()->subMonth()->toDateString(),
                'valid_to' => null,
                'revision' => 1,
            ],
        ]);

        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employee)
            ->assertJsonPath('data.0.current_department_name', 'Projects')
            ->assertJsonPath('data.0.current_position_name', 'Lead engineer')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_register_searches_personnel_number_and_name_without_copying_term_to_audit(): void
    {
        $company = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Company']);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => 'hr_specialist',
        ]);
        DB::table('employees')->insert([
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company,
                'personnel_number' => 'EMP-ALPHA',
                'last_name' => 'Alpha',
                'first_name' => 'One',
                'revision' => 1,
            ],
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company,
                'personnel_number' => 'EMP-BETA',
                'last_name' => 'Beta',
                'first_name' => 'Two',
                'revision' => 1,
            ],
        ]);

        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees?search=beta")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.personnel_number', 'EMP-BETA')
            ->assertJsonPath('meta.total', 1);

        $event = DB::table('hr_audit_events')->where('action', 'personnel.employees_listed')->latest('id')->first();
        $this->assertNotNull($event);
        $context = json_decode((string) $event->context, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($context['search_applied']);
        $this->assertArrayNotHasKey('search', $context);
        $this->assertStringNotContainsString('beta', strtolower((string) $event->context));
    }
}
