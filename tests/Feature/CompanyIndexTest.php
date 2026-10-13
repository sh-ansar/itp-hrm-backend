<?php

namespace Tests\Feature;

use App\Domain\Identity\CompanyRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CompanyIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_cannot_read_company_index(): void
    {
        $this->getJson('/api/v1/companies')->assertUnauthorized();
    }

    public function test_authenticated_user_without_membership_sees_no_companies(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_page_size_is_bounded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->getJson('/api/v1/companies?per_page=101')
            ->assertUnprocessable();
    }


    public function test_company_index_exposes_only_current_users_role_and_manager_scopes(): void
    {
        $user = User::factory()->create();
        $hrCompany = (string) Str::uuid();
        $managerCompany = (string) Str::uuid();
        $employeeOnlyCompany = (string) Str::uuid();
        $hrDepartment = (string) Str::uuid();
        $managerDepartment = (string) Str::uuid();

        DB::table('companies')->insert([
            ['id' => $hrCompany, 'name' => 'HR Company'],
            ['id' => $managerCompany, 'name' => 'Manager Company'],
            ['id' => $employeeOnlyCompany, 'name' => 'Employee Company'],
        ]);
        DB::table('departments')->insert([
            ['id' => $hrDepartment, 'company_id' => $hrCompany, 'name' => 'Stale HR scope'],
            ['id' => $managerDepartment, 'company_id' => $managerCompany, 'name' => 'Operations'],
        ]);
        DB::table('company_user_roles')->insert([
            [
                'company_id' => $hrCompany,
                'user_id' => $user->id,
                'role_code' => CompanyRole::HrSpecialist->value,
            ],
            [
                'company_id' => $managerCompany,
                'user_id' => $user->id,
                'role_code' => CompanyRole::DepartmentManager->value,
            ],
            [
                'company_id' => $employeeOnlyCompany,
                'user_id' => $user->id,
                'role_code' => CompanyRole::Employee->value,
            ],
        ]);
        DB::table('department_access_scopes')->insert([
            [
                'company_id' => $hrCompany,
                'user_id' => $user->id,
                'department_id' => $hrDepartment,
            ],
            [
                'company_id' => $managerCompany,
                'user_id' => $user->id,
                'department_id' => $managerDepartment,
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/companies?per_page=100')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.role_management_enabled', false);

        $companies = collect($response->json('data'))->keyBy('id');

        $this->assertSame('hr_specialist', $companies[$hrCompany]['role_code']);
        $this->assertSame([], $companies[$hrCompany]['department_scopes']);

        $this->assertSame('department_manager', $companies[$managerCompany]['role_code']);
        $this->assertSame([
            ['id' => $managerDepartment, 'name' => 'Operations'],
        ], $companies[$managerCompany]['department_scopes']);

        $this->assertArrayNotHasKey($employeeOnlyCompany, $companies->all());
    }
}
