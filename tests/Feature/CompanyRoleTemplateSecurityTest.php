<?php

namespace Tests\Feature;

use App\Domain\Audit\HrAuditAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyRoleTemplateSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hrm.role_management_enabled', true);
        config()->set('hrm.custom_role_templates_enabled', true);
        config()->set('hrm.personnel_writes_enabled', true);
    }

    private function company(string $name = 'Synthetic company'): string
    {
        $id = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $id, 'name' => $name]);
        return $id;
    }

    private function userRole(string $company, string $role): User
    {
        $user = User::factory()->create();
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id, 'company_id' => $company, 'role_code' => $role,
        ]);
        return $user;
    }

    private function templateUrl(string $company, ?string $id = null): string
    {
        return '/api/v1/companies/'.$company.'/role-templates'.($id ? '/'.$id : '');
    }

    private function payload(string $code = 'registry_hr', string $role = 'hr_specialist', ?array $permissions = null): array
    {
        return [
            'code' => $code,
            'name' => 'Кадровый реестр',
            'description' => 'Только выбранные разрешения',
            'base_role' => $role,
            'capabilities' => $permissions ?? ['employees.view' => true, 'organization.view' => true],
        ];
    }

    private function create(User $admin, string $company, ?array $data = null): string
    {
        $result = $this->actingAs($admin)
            ->postJson($this->templateUrl($company), $data ?? $this->payload());
        $result->assertCreated()->assertJsonPath('data.revision', 1);
        return $result->json('data.id');
    }

    private function assign(User $admin, string $company, User $target, string $template, string $base = 'hr_specialist', array $scopes = []): void
    {
        $this->actingAs($admin)
            ->putJson('/api/v1/companies/'.$company.'/memberships/'.$target->id, [
                'role_code' => $base,
                'custom_role_id' => $template,
                'department_ids' => $scopes,
            ])
            ->assertOk()->assertJsonPath('data.custom_role_id', $template);
    }

    public function test_flag_off_hides_custom_role_crud_but_preserves_existing_system_roles(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        config()->set('hrm.custom_role_templates_enabled', false);
        $this->actingAs($admin)->getJson($this->templateUrl($company))->assertNotFound();
        $this->actingAs($admin)->postJson($this->templateUrl($company), $this->payload())->assertNotFound();
        $this->actingAs($admin)->getJson('/api/v1/companies/'.$company.'/memberships')->assertOk();
        $this->assertSame(0, DB::table('company_role_templates')->count());
    }

    public function test_custom_role_requires_canonical_admin_of_that_company(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $adminA = $this->userRole($a, 'company_admin');
        $hr = $this->userRole($a, 'hr_specialist');
        $this->postJson($this->templateUrl($a), $this->payload())->assertUnauthorized();
        $this->actingAs($hr)->getJson($this->templateUrl($a))->assertForbidden();
        $this->actingAs($hr)->postJson($this->templateUrl($a), $this->payload())->assertForbidden();
        $this->actingAs($adminA)->postJson($this->templateUrl($b), $this->payload())->assertForbidden();
        $this->assertSame(0, DB::table('company_role_templates')->count());
    }

    public function test_validation_rejects_elevation_to_admin_or_permissions_above_base(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        $this->actingAs($admin)->postJson($this->templateUrl($company), $this->payload('admin_clone', 'company_admin'))
            ->assertUnprocessable()->assertJsonValidationErrors('base_role');
        $this->actingAs($admin)->postJson($this->templateUrl($company), $this->payload('manager_override', 'department_manager', [
            'roles.assign' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('capabilities');
        $this->actingAs($admin)->postJson($this->templateUrl($company), $this->payload('unknown_cap', 'hr_specialist', [
            'superuser.all' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('capabilities');
        $this->actingAs($admin)->postJson($this->templateUrl($company), $this->payload('bad_bool', 'hr_specialist', [
            'employees.view' => 'anything',
        ]))->assertUnprocessable();
        $this->assertSame(0, DB::table('company_role_templates')->count());
    }

    public function test_create_list_update_revisions_and_duplicate_code(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        $id = $this->create($admin, $company);
        $list = $this->actingAs($admin)->getJson($this->templateUrl($company));
        $list->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->assertTrue($list->json('data.0.capabilities')['employees.view']);
        $this->actingAs($admin)->postJson($this->templateUrl($company), $this->payload())
            ->assertStatus(409);
        $this->actingAs($admin)->putJson($this->templateUrl($company, $id), [
            'revision' => 9, 'name' => 'Другая роль',
        ])->assertStatus(409);
        $this->actingAs($admin)->putJson($this->templateUrl($company, $id), [
            'revision' => 1, 'name' => 'Кадровый наблюдатель',
            'capabilities' => ['employees.view' => true],
        ])->assertOk()->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.name', 'Кадровый наблюдатель');
        $this->actingAs($admin)->putJson($this->templateUrl($company, $id), [
            'revision' => 2, 'base_role' => 'company_admin',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('hr_audit_events', ['action' => HrAuditAction::RoleTemplateCreated->value]);
        $this->assertDatabaseHas('hr_audit_events', ['action' => HrAuditAction::RoleTemplateUpdated->value]);
    }

    public function test_custom_role_permissions_are_checked_by_backend_and_updated_immediately(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        $hr = $this->userRole($company, 'hr_specialist');
        $id = $this->create($admin, $company);

        $this->actingAs($hr)->getJson('/api/v1/companies/'.$company.'/employees')->assertOk();
        $this->assign($admin, $company, $hr, $id);

        $this->actingAs($hr)->getJson('/api/v1/companies/'.$company.'/employees')->assertOk();
        $this->actingAs($hr)->getJson('/api/v1/companies/'.$company.'/staffing-plans')->assertForbidden();
        $this->actingAs($hr)->postJson('/api/v1/companies/'.$company.'/employees', [])->assertForbidden();
        $this->actingAs($hr)->getJson('/api/v1/companies/'.$company.'/documents')->assertForbidden();
        $access = $this->actingAs($hr)->getJson('/api/v1/me/access');
        $access->assertOk()->assertJsonPath('data.companies.0.custom_role.id', $id);
        $caps = $access->json('data.companies.0.capabilities');
        $this->assertTrue($caps['employees.view']);
        $this->assertFalse($caps['staffing.view']);
        $this->assertFalse($caps['roles.assign']);

        $this->actingAs($admin)->putJson($this->templateUrl($company, $id), [
            'revision' => 1, 'capabilities' => ['employees.view' => false],
        ])->assertOk();

        $this->actingAs($hr)->getJson('/api/v1/companies/'.$company.'/employees')->assertForbidden();
        $after = $this->actingAs($hr)->getJson('/api/v1/me/access');
        $this->assertFalse($after->json('data.companies.0.capabilities')['employees.view']);
    }

    public function test_manager_custom_role_keeps_existing_company_department_scopes(): void
    {
        $company = $this->company();
        $other = $this->company('Other company');
        $admin = $this->userRole($company, 'company_admin');
        $manager = $this->userRole($company, 'department_manager');
        $inside = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        DB::table('departments')->insert(['id' => $inside, 'company_id' => $company, 'name' => 'QA']);
        DB::table('departments')->insert(['id' => $foreign, 'company_id' => $other, 'name' => 'Other']);
        $id = $this->create($admin, $company, $this->payload('team_viewer', 'department_manager', [
            'employees.view' => true, 'organization.view' => true,
        ]));
        $this->actingAs($admin)->putJson('/api/v1/companies/'.$company.'/memberships/'.$manager->id, [
            'role_code' => 'department_manager', 'department_ids' => [$inside, $foreign],
            'custom_role_id' => $id,
        ])->assertUnprocessable()->assertJsonValidationErrors('department_ids');
        $this->assign($admin, $company, $manager, $id, 'department_manager', [$inside]);
        $this->actingAs($manager)->getJson('/api/v1/companies/'.$company.'/employees')->assertOk();
        $this->actingAs($manager)->getJson('/api/v1/companies/'.$other.'/employees')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/me/access')
            ->assertJsonPath('data.companies.0.department_scopes.0.id', $inside);
    }

    public function test_cannot_assign_foreign_inactive_or_wrong_base_role_template(): void
    {
        $company = $this->company();
        $other = $this->company('Other');
        $admin = $this->userRole($company, 'company_admin');
        $target = User::factory()->create();
        $otherId = $this->create($this->userRole($other, 'company_admin'), $other);
        $id = $this->create($admin, $company, $this->payload('hr_visible'));
        foreach ([
            [$otherId, 'hr_specialist'],
            [$id, 'department_manager'],
        ] as [$template, $base]) {
            $this->actingAs($admin)->putJson('/api/v1/companies/'.$company.'/memberships/'.$target->id, [
                'role_code' => $base, 'department_ids' => [], 'custom_role_id' => $template,
            ])->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
        }
        $this->actingAs($admin)->putJson($this->templateUrl($company, $id), [
            'revision' => 1, 'status' => 'archived',
        ])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/companies/'.$company.'/memberships/'.$target->id, [
            'role_code' => 'hr_specialist', 'department_ids' => [], 'custom_role_id' => $id,
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
        $this->assertDatabaseMissing('company_user_roles', ['company_id' => $company, 'user_id' => $target->id]);
    }

    public function test_archive_and_deletion_are_blocked_while_role_is_assigned(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        $target = $this->userRole($company, 'hr_specialist');
        $id = $this->create($admin, $company);
        $this->assign($admin, $company, $target, $id);

        $this->actingAs($admin)->putJson($this->templateUrl($company, $id), [
            'revision' => 1, 'status' => 'archived',
        ])->assertStatus(409);
        $this->actingAs($admin)->deleteJson($this->templateUrl($company, $id), [
            'revision' => 1,
        ])->assertStatus(409);

        $this->actingAs($admin)->deleteJson('/api/v1/companies/'.$company.'/memberships/'.$target->id)->assertNoContent();
        $this->assertDatabaseMissing('company_user_roles', ['user_id' => $target->id, 'company_id' => $company]);
        $this->actingAs($admin)->putJson($this->templateUrl($company, $id), [
            'revision' => 1, 'status' => 'archived',
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($admin)->deleteJson($this->templateUrl($company, $id), [
            'revision' => 2,
        ])->assertNoContent();
        $this->assertSame(0, DB::table('company_role_templates')->where('id', $id)->count());
    }

    public function test_admin_memberships_and_self_grants_remain_protected(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        $otherAdmin = $this->userRole($company, 'company_admin');
        $id = $this->create($admin, $company);
        $this->actingAs($admin)->putJson('/api/v1/companies/'.$company.'/memberships/'.$admin->id, [
            'role_code' => 'hr_specialist', 'department_ids' => [], 'custom_role_id' => $id,
        ])->assertForbidden();
        $this->actingAs($admin)->putJson('/api/v1/companies/'.$company.'/memberships/'.$otherAdmin->id, [
            'role_code' => 'hr_specialist', 'department_ids' => [], 'custom_role_id' => $id,
        ])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/me/access')
            ->assertJsonPath('data.companies.0.role_code', 'company_admin');
    }

    public function test_company_permissions_still_fail_closed_when_feature_flag_changes(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        $target = $this->userRole($company, 'hr_specialist');
        $id = $this->create($admin, $company, $this->payload('doc_reader', 'hr_specialist', [
            'documents.view' => true, 'employees.view' => true,
        ]));
        $this->assign($admin, $company, $target, $id);

        $this->actingAs($target)->getJson('/api/v1/companies/'.$company.'/documents')->assertOk();
        config()->set('hrm.custom_role_templates_enabled', false);
        $this->actingAs($target)->getJson('/api/v1/companies/'.$company.'/staffing-plans')->assertForbidden();
        $this->actingAs($target)->getJson('/api/v1/companies/'.$company.'/documents')->assertOk();
        $after = $this->actingAs($target)->getJson('/api/v1/me/access');
        $this->assertFalse($after->json('data.companies.0.capabilities')['staffing.view']);
    }
    public function test_restricted_employee_registry_does_not_expose_private_card_or_edit_metadata(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        $hr = $this->userRole($company, 'hr_specialist');
        $employeeId = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $employeeId, 'company_id' => $company,
            'personnel_number' => 'T-100', 'last_name' => 'Тестов',
            'first_name' => 'Иван', 'birth_date' => '1990-01-01',
            'email' => 'private-qa@example.test',
        ]);

        $standard = $this->actingAs($hr)->getJson('/api/v1/companies/'.$company.'/employees/'.$employeeId);
        $standard->assertOk()->assertJsonPath('meta.extended_sections_visible', true)
            ->assertJsonPath('meta.employee_writes_enabled', true)
            ->assertJsonPath('data.email', 'private-qa@example.test');

        $id = $this->create($admin, $company, $this->payload('basic_register', 'hr_specialist', [
            'employees.view' => true,
        ]));
        $this->assign($admin, $company, $hr, $id);
        $restricted = $this->actingAs($hr)->getJson('/api/v1/companies/'.$company.'/employees/'.$employeeId);
        $restricted->assertOk()->assertJsonPath('meta.extended_sections_visible', false)
            ->assertJsonPath('meta.employee_writes_enabled', false);
        $restricted->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('private_profile');
        $this->actingAs($hr)->getJson('/api/v1/me/access')->assertOk()
            ->assertJsonPath('data.companies.0.custom_role.id', $id);
        $this->assertFalse($this->actingAs($hr)->getJson('/api/v1/me/access')
            ->json('data.companies.0.capabilities')['employees.extended_view']);
    }

    public function test_protected_leave_files_are_separately_gated_from_leave_registry(): void
    {
        $company = $this->company();
        $admin = $this->userRole($company, 'company_admin');
        $hr = $this->userRole($company, 'hr_specialist');
        $id = $this->create($admin, $company, $this->payload('leave_observer', 'hr_specialist', [
            'leaves.view' => true,
        ]));
        $this->assign($admin, $company, $hr, $id);
        $fake = (string) Str::uuid();
        $url = '/api/v1/companies/'.$company.'/leave-requests/'.$fake.'/evidence/file';
        $this->actingAs($hr)->getJson($url)->assertForbidden();

        $this->actingAs($admin)->putJson($this->templateUrl($company, $id), [
            'revision' => 1,
            'capabilities' => ['leaves.view' => true, 'leaves.evidence.view' => true],
        ])->assertOk();
        $this->actingAs($hr)->getJson($url)->assertNotFound();
    }


}
