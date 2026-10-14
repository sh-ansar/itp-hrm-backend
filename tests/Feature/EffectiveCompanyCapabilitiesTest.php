<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EffectiveCompanyCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function membership(User $user, string $role): string
    {
        $company = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Tenant '.$role]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company,
            'user_id' => $user->id,
            'role_code' => $role,
        ]);
        return $company;
    }

    public function test_guest_is_denied_and_user_without_membership_has_no_grants(): void
    {
        $this->getJson('/api/v1/me/access')->assertUnauthorized();

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/v1/me/access')->assertOk()
            ->assertJsonCount(0, 'data.companies')
            ->assertJsonPath('meta.schema_version', 1);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
    }

    public function test_admin_and_hr_get_only_the_capabilities_released_by_feature_flags(): void
    {
        $actor = User::factory()->create();
        $adminCompany = $this->membership($actor, 'company_admin');
        $hrCompany = $this->membership($actor, 'hr_specialist');

        $json = $this->actingAs($actor)->getJson('/api/v1/me/access')
            ->assertOk()->json('data.companies');
        $companies = collect($json)->keyBy('company_id');
        $admin = $companies[$adminCompany]['capabilities'];
        $hr = $companies[$hrCompany]['capabilities'];
        $this->assertTrue($admin['employees.view']);
        $this->assertTrue($hr['employees.view']);
        $this->assertFalse($admin['worktime.calendar.edit']);
        $this->assertFalse($admin['roles.assign']);
        $this->assertFalse($hr['leave.requests.approve']);
        $this->assertFalse($admin['roles.interface']);
        $this->assertFalse($admin['reports.view']);

        config()->set('hrm.worktime_writes_enabled', true);
        config()->set('hrm.leave_writes_enabled', true);
        config()->set('hrm.role_management_enabled', true);
        $enabled = collect($this->actingAs($actor)->getJson('/api/v1/me/access')
            ->assertOk()->json('data.companies'))->keyBy('company_id');
        $this->assertTrue($enabled[$adminCompany]['capabilities']['worktime.calendar.edit']);
        $this->assertTrue($enabled[$adminCompany]['capabilities']['leave.requests.approve']);
        $this->assertTrue($enabled[$adminCompany]['capabilities']['roles.assign']);
        $this->assertTrue($enabled[$hrCompany]['capabilities']['leave.requests.manage']);
        $this->assertFalse($enabled[$hrCompany]['capabilities']['roles.assign']);
        $this->assertFalse($enabled[$hrCompany]['capabilities']['leave.requests.approve']);
        $this->assertFalse($enabled[$hrCompany]['capabilities']['worktime.calendar.edit']);
    }

    public function test_scoped_manager_does_not_inherit_hr_rights_or_sibling_scopes(): void
    {
        $actor = User::factory()->create();
        $company = $this->membership($actor, 'department_manager');
        $department = (string) Str::uuid();
        $hidden = (string) Str::uuid();
        DB::table('departments')->insert([
            ['id' => $department, 'company_id' => $company, 'name' => 'Allowed'],
            ['id' => $hidden, 'company_id' => $company, 'name' => 'Not granted'],
        ]);
        DB::table('department_access_scopes')->insert([
            'company_id' => $company,
            'user_id' => $actor->id,
            'department_id' => $department,
        ]);
        $data = $this->actingAs($actor)->getJson('/api/v1/me/access')
            ->assertOk()->json('data.companies.0');

        $this->assertSame('department_manager', $data['role_code']);
        $this->assertCount(1, $data['department_scopes']);
        $this->assertSame($department, $data['department_scopes'][0]['id']);
        $this->assertTrue($data['capabilities']['employees.view']);
        $this->assertTrue($data['capabilities']['worktime.view']);
        $this->assertFalse($data['capabilities']['staffing.view']);
        $this->assertFalse($data['capabilities']['documents.view']);
        $this->assertFalse($data['capabilities']['employees.write']);
    }

    public function test_employee_membership_grants_no_hr_reads_and_revocation_takes_effect(): void
    {
        $actor = User::factory()->create();
        $company = $this->membership($actor, 'employee');
        $data = $this->actingAs($actor)->getJson('/api/v1/me/access')->assertOk()->json('data.companies.0');
        $this->assertSame($company, $data['company_id']);
        $this->assertFalse($data['capabilities']['overview.view']);
        $this->assertFalse($data['capabilities']['employees.view']);
        $this->assertFalse($data['capabilities']['leaves.view']);
        $this->assertTrue($data['capabilities']['settings.view']);

        DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $actor->id)->delete();
        $this->actingAs($actor)->getJson('/api/v1/me/access')->assertOk()
            ->assertJsonCount(0, 'data.companies');
    }

    public function test_cross_company_roles_do_not_union_privileges(): void
    {
        $actor = User::factory()->create();
        $hrCompany = $this->membership($actor, 'hr_specialist');
        $employeeCompany = $this->membership($actor, 'employee');
        $data = collect($this->actingAs($actor)->getJson('/api/v1/me/access')
            ->assertOk()->json('data.companies'))->keyBy('company_id');
        $this->assertTrue($data[$hrCompany]['capabilities']['documents.view']);
        $this->assertFalse($data[$employeeCompany]['capabilities']['documents.view']);
    }
}
