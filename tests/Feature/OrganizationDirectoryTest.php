<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrganizationDirectoryTest extends TestCase
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
        DB::table('departments')->insert(['id' => $id, 'company_id' => $company, 'name' => $name]);
        return $id;
    }

    private function grant(User $user, string $company, string $role): void
    {
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id, 'company_id' => $company, 'role_code' => $role,
        ]);
    }

    public function test_anonymous_company_departments_denied(): void
    {
        $id = $this->company('Demo');
        $this->getJson("/api/v1/companies/{$id}/departments")->assertUnauthorized();
    }

    public function test_directory_shows_only_memberships_and_company_departments(): void
    {
        $user = User::factory()->create();
        $a = $this->company('Allowed');
        $b = $this->company('Hidden');
        $department = $this->department($a, 'Finance');
        $this->department($b, 'Secret');
        $this->grant($user, $a, 'hr_specialist');

        $this->actingAs($user)->getJson('/api/v1/companies')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Allowed')
            ->assertJsonMissing(['name' => 'Hidden']);

        $this->actingAs($user)->getJson("/api/v1/companies/{$a}/departments")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $department)
            ->assertJsonMissing(['name' => 'Secret']);

        $this->actingAs($user)->getJson("/api/v1/companies/{$b}/departments")
            ->assertForbidden();
    }

    public function test_manager_only_reads_explicit_scopes_and_employee_cannot_browse(): void
    {
        $company = $this->company('Branch');
        $allowed = $this->department($company, 'Visible');
        $this->department($company, 'Restricted');
        $manager = User::factory()->create();
        $this->grant($manager, $company, 'department_manager');
        DB::table('department_access_scopes')->insert([
            'company_id' => $company,
            'user_id' => $manager->id,
            'department_id' => $allowed,
        ]);
        $this->actingAs($manager)->getJson("/api/v1/companies/{$company}/departments")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed)
            ->assertJsonMissing(['name' => 'Restricted']);

        $employee = User::factory()->create();
        $this->grant($employee, $company, 'employee');
        $this->actingAs($employee)->getJson('/api/v1/companies')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($employee)->getJson("/api/v1/companies/{$company}/departments")
            ->assertForbidden();
    }

    public function test_department_page_size_is_bounded(): void
    {
        $company = $this->company('Branch');
        $user = User::factory()->create();
        $this->grant($user, $company, 'company_admin');
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/departments?per_page=999")
            ->assertUnprocessable();
    }
}
