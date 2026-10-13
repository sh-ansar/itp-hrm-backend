<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeIdentityDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_fields_are_saved_and_hidden_from_department_managers(): void
    {
        config()->set('hrm.personnel_writes_enabled', true);
        $company = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $user = User::factory()->create();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Example']);
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'Operations']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Operator']);
        DB::table('company_user_roles')->insert(['user_id' => $user->id, 'company_id' => $company, 'role_code' => 'hr_specialist']);
        $response = $this->actingAs($user)->postJson("/api/v1/companies/{$company}/employees", [
            'personnel_number' => 'P-001', 'first_name' => 'Test', 'last_name' => 'Person',
            'birth_date' => '1990-06-01', 'gender' => 'female',
            'email' => 'test@example.org', 'phone' => '+77770000000', 'address' => 'Example Street',
            'work_site' => 'Terminal A', 'work_zone' => 'North', 'work_team' => 'Shift 1', 'supervisor_name' => 'Supervisor',
        ])->assertCreated();
        $employee = $response->json('data.id');
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertOk()->assertJsonPath('data.email', 'test@example.org')
            ->assertJsonPath('data.birth_date', '1990-06-01')->assertJsonPath('data.work_site', 'Terminal A');
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'department_id' => $department, 'position_id' => $position, 'valid_from' => '2025-01-01',
        ]);
        DB::table('company_user_roles')->where('user_id', $user->id)->where('company_id', $company)->update(['role_code' => 'department_manager']);
        DB::table('department_access_scopes')->insert(['user_id' => $user->id, 'company_id' => $company, 'department_id' => $department]);
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertOk()->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.birth_date');
    }
}
