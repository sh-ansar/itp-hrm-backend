<?php

namespace Tests\Feature;

use App\Domain\Identity\CompanyRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyEmployeeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $id, 'name' => $name]);

        return $id;
    }

    private function department(string $company, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('departments')->insert([
            'id' => $id,
            'company_id' => $company,
            'name' => $name,
        ]);

        return $id;
    }

    private function position(string $company): string
    {
        $id = (string) Str::uuid();
        DB::table('positions')->insert([
            'id' => $id,
            'company_id' => $company,
            'name' => 'Engineer',
        ]);

        return $id;
    }

    private function employee(string $company, string $number): string
    {
        $id = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $id,
            'company_id' => $company,
            'personnel_number' => $number,
            'last_name' => 'Sample',
            'first_name' => 'Worker',
        ]);

        return $id;
    }

    private function assignment(
        string $employee,
        string $department,
        string $position,
        string $validFrom,
        ?string $validTo = null,
    ): void {
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $employee,
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ]);
    }

    private function grant(User $user, string $company, string $roleCode): void
    {
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $roleCode,
        ]);
    }

    private function scope(User $user, string $company, string $department): void
    {
        DB::table('department_access_scopes')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'department_id' => $department,
        ]);
    }

    private function url(string $company): string
    {
        return "/api/v1/companies/{$company}/employees";
    }

    public function test_anonymous_user_cannot_read_personnel(): void
    {
        $company = $this->company('First company');

        $this->getJson($this->url($company))->assertUnauthorized();
    }

    public function test_user_without_company_membership_is_denied(): void
    {
        $company = $this->company('First company');
        $user = User::factory()->create();

        $this->actingAs($user)->getJson($this->url($company))->assertForbidden();
    }

    public function test_role_in_other_company_does_not_grant_access(): void
    {
        $a = $this->company('First company');
        $b = $this->company('Second company');
        $user = User::factory()->create();
        $this->grant($user, $a, CompanyRole::CompanyAdmin->value);
        $this->employee($b, 'B-01');

        $this->actingAs($user)
            ->getJson($this->url($b))
            ->assertForbidden()
            ->assertJsonMissing(['personnel_number' => 'B-01']);
    }

    public function test_hr_specialist_reads_only_their_company_with_minimal_fields(): void
    {
        $a = $this->company('First company');
        $b = $this->company('Second company');
        $this->employee($a, 'A-01');
        $this->employee($b, 'B-01');
        $user = User::factory()->create();
        $this->grant($user, $a, CompanyRole::HrSpecialist->value);

        $this->actingAs($user)
            ->getJson($this->url($a))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.personnel_number', 'A-01')
            ->assertJsonMissing(['personnel_number' => 'B-01'])
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.salary');
    }

    public function test_company_admin_access_is_limited_to_the_granted_company(): void
    {
        $a = $this->company('First company');
        $b = $this->company('Second company');
        $this->employee($a, 'A-01');
        $this->employee($b, 'B-01');
        $user = User::factory()->create();
        $this->grant($user, $a, CompanyRole::CompanyAdmin->value);

        $this->actingAs($user)
            ->getJson($this->url($a))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)->getJson($this->url($b))->assertForbidden();
    }

    public function test_employee_and_unknown_roles_are_denied_by_default(): void
    {
        $company = $this->company('First company');
        $user = User::factory()->create();
        $this->grant($user, $company, CompanyRole::Employee->value);
        $this->actingAs($user)->getJson($this->url($company))->assertForbidden();

        DB::table('company_user_roles')
            ->where('user_id', $user->id)
            ->update(['role_code' => 'some_future_role']);

        $this->actingAs($user)->getJson($this->url($company))->assertForbidden();
    }

    public function test_manager_sees_only_current_assignments_in_explicit_department(): void
    {
        $company = $this->company('First company');
        $allowed = $this->department($company, 'Allowed');
        $other = $this->department($company, 'Restricted');
        $position = $this->position($company);

        $visible = $this->employee($company, 'VISIBLE');
        $hidden = $this->employee($company, 'OTHER');
        $expired = $this->employee($company, 'EXPIRED');
        $future = $this->employee($company, 'FUTURE');

        $this->assignment($visible, $allowed, $position, now()->subDays(2)->toDateString());
        $this->assignment($hidden, $other, $position, now()->subDays(2)->toDateString());
        $this->assignment($expired, $allowed, $position, now()->subDays(9)->toDateString(), now()->subDay()->toDateString());
        $this->assignment($future, $allowed, $position, now()->addDay()->toDateString());

        $user = User::factory()->create();
        $this->grant($user, $company, CompanyRole::DepartmentManager->value);
        $this->scope($user, $company, $allowed);

        $this->actingAs($user)
            ->getJson($this->url($company))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.personnel_number', 'VISIBLE')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_manager_without_explicit_department_scope_sees_no_employees(): void
    {
        $company = $this->company('First company');
        $employee = $this->employee($company, 'A-01');
        $position = $this->position($company);
        $department = $this->department($company, 'Unassigned scope');
        $this->assignment($employee, $department, $position, now()->subDay()->toDateString());
        $user = User::factory()->create();
        $this->grant($user, $company, CompanyRole::DepartmentManager->value);

        $this->actingAs($user)
            ->getJson($this->url($company))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_query_page_size_is_bounded_and_validated(): void
    {
        $company = $this->company('First company');
        $user = User::factory()->create();
        $this->grant($user, $company, CompanyRole::HrSpecialist->value);

        $this->actingAs($user)
            ->getJson($this->url($company).'?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->actingAs($user)
            ->getJson($this->url($company).'?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_composite_foreign_key_rejects_scope_to_other_company_department(): void
    {
        $a = $this->company('First company');
        $b = $this->company('Second company');
        $otherDepartment = $this->department($b, 'Restricted');
        $user = User::factory()->create();
        $this->grant($user, $a, CompanyRole::DepartmentManager->value);

        $this->expectException(QueryException::class);
        $this->scope($user, $a, $otherDepartment);
    }

    public function test_malformed_company_identifier_is_not_a_valid_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/companies/not-a-uuid/employees')
            ->assertNotFound();
    }
}
