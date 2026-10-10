<?php

namespace Tests\Feature;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Identity\CompanyRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditedCompanyRoleAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hrm.role_management_enabled', true);
    }

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

    private function grant(User $user, string $company, CompanyRole $role): void
    {
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $role->value,
        ]);
    }

    private function url(string $company, User $user): string
    {
        return "/api/v1/companies/{$company}/memberships/{$user->id}";
    }

    private function payload(string $role, array $scopes = []): array
    {
        return ['role_code' => $role, 'department_ids' => $scopes];
    }

    private function auditCount(?string $action = null): int
    {
        $query = DB::table('hr_audit_events');

        return $action === null ? $query->count() : $query->where('action', $action)->count();
    }

    private function admin(string $company): User
    {
        $admin = User::factory()->create();
        $this->grant($admin, $company, CompanyRole::CompanyAdmin);

        return $admin;
    }

    public function test_management_is_disabled_by_default_even_for_company_admin(): void
    {
        config()->set('hrm.role_management_enabled', false);
        $company = $this->company('A');
        $admin = $this->admin($company);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('employee'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->deleteJson($this->url($company, $target))
            ->assertNotFound();

        $this->assertSame(0, DB::table('company_user_roles')->where('user_id', $target->id)->count());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_anonymous_management_is_rejected(): void
    {
        $company = $this->company('A');
        $target = User::factory()->create();

        $this->putJson($this->url($company, $target), $this->payload('employee'))
            ->assertUnauthorized();

        $this->deleteJson($this->url($company, $target))
            ->assertUnauthorized();
    }

    public function test_unprivileged_roles_cannot_assign_or_revoke(): void
    {
        $company = $this->company('A');
        $target = User::factory()->create();
        foreach ([null, CompanyRole::HrSpecialist, CompanyRole::DepartmentManager, CompanyRole::Employee] as $role) {
            $actor = User::factory()->create();
            if ($role !== null) {
                $this->grant($actor, $company, $role);
            }

            $this->actingAs($actor)
                ->putJson($this->url($company, $target), $this->payload('employee'))
                ->assertForbidden();

            $this->actingAs($actor)
                ->deleteJson($this->url($company, $target))
                ->assertForbidden();
        }

        $this->assertSame(0, DB::table('company_user_roles')->where('user_id', $target->id)->count());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_admin_in_another_company_cannot_manage_membership(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $admin = $this->admin($a);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->putJson($this->url($b, $target), $this->payload('employee'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson($this->url($b, $target))
            ->assertForbidden();

        $this->assertSame(0, $this->auditCount());
    }

    public function test_admin_can_grant_lower_role_with_minimal_audited_response(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $target = User::factory()->create(['email' => 'synthetic-target@example.test']);

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('hr_specialist'))
            ->assertOk()
            ->assertExactJson(['data' => [
                'user_id' => $target->id,
                'role_code' => 'hr_specialist',
                'department_ids' => [],
            ]])
            ->assertDontSee('synthetic-target@example.test');

        $this->assertDatabaseHas('company_user_roles', [
            'user_id' => $target->id, 'company_id' => $company, 'role_code' => 'hr_specialist',
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'actor_user_id' => $admin->id,
            'subject_user_id' => $target->id, 'action' => HrAuditAction::RoleGranted->value,
        ]);
        $this->assertSame(1, $this->auditCount());
    }

    public function test_repeat_put_is_idempotent_and_does_not_duplicate_audit(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('employee'))
            ->assertOk();

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('employee'))
            ->assertOk();

        $this->assertSame(1, DB::table('company_user_roles')->where('user_id', $target->id)->count());
        $this->assertSame(1, $this->auditCount());
    }

    public function test_admin_can_replace_manager_scopes_atomically(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $target = User::factory()->create();
        $d1 = $this->department($company, 'D1');
        $d2 = $this->department($company, 'D2');

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('department_manager', [$d1]))
            ->assertOk();

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('department_manager', [$d2]))
            ->assertOk()
            ->assertJsonPath('data.department_ids.0', $d2);

        $this->assertDatabaseHas('department_access_scopes', [
            'user_id' => $target->id, 'company_id' => $company, 'department_id' => $d2,
        ]);
        $this->assertDatabaseMissing('department_access_scopes', [
            'user_id' => $target->id, 'company_id' => $company, 'department_id' => $d1,
        ]);
        $this->assertSame(1, $this->auditCount(HrAuditAction::RoleChanged->value));

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('employee'))
            ->assertOk();

        $this->assertSame(0, DB::table('department_access_scopes')->where('user_id', $target->id)->count());
        $this->assertSame(2, $this->auditCount(HrAuditAction::RoleChanged->value));
    }

    public function test_wrong_company_department_is_rejected_without_partial_mutation(): void
    {
        $company = $this->company('A');
        $other = $this->company('B');
        $admin = $this->admin($company);
        $target = User::factory()->create();
        $allowed = $this->department($company, 'Allowed');
        $foreign = $this->department($other, 'Other company');

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('department_manager', [$allowed, $foreign]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_ids');

        $this->assertSame(0, DB::table('company_user_roles')->where('user_id', $target->id)->count());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_rejected_scope_update_keeps_previous_role_intact(): void
    {
        $company = $this->company('A');
        $other = $this->company('B');
        $admin = $this->admin($company);
        $target = User::factory()->create();
        $foreign = $this->department($other, 'Other company');
        $this->grant($target, $company, CompanyRole::HrSpecialist);

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('department_manager', [$foreign]))
            ->assertUnprocessable();

        $this->assertDatabaseHas('company_user_roles', [
            'user_id' => $target->id, 'company_id' => $company, 'role_code' => 'hr_specialist',
        ]);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_rejects_admin_role_and_managing_admin_memberships(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $otherAdmin = $this->admin($company);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('company_admin'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_code');

        $this->actingAs($admin)
            ->putJson($this->url($company, $otherAdmin), $this->payload('employee'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson($this->url($company, $otherAdmin))
            ->assertForbidden();

        $this->assertDatabaseHas('company_user_roles', [
            'user_id' => $otherAdmin->id, 'company_id' => $company, 'role_code' => 'company_admin',
        ]);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_admin_cannot_change_own_membership(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);

        $this->actingAs($admin)
            ->putJson($this->url($company, $admin), $this->payload('employee'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson($this->url($company, $admin))
            ->assertForbidden();

        $this->assertSame(0, $this->auditCount());
    }

    public function test_only_managers_may_hold_department_scopes(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $target = User::factory()->create();
        $department = $this->department($company, 'D1');

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('hr_specialist', [$department]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_ids');

        $this->assertSame(0, $this->auditCount());
    }

    public function test_invalid_and_duplicate_scopes_are_rejected(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $target = User::factory()->create();
        $department = $this->department($company, 'D1');

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('department_manager', [$department, $department]))
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('department_manager', ['not-uuid']))
            ->assertUnprocessable();

        $this->assertSame(0, $this->auditCount());
    }

    public function test_revoke_deletes_scopes_and_prevents_future_employee_reads(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $target = User::factory()->create();
        $department = $this->department($company, 'D1');

        $this->actingAs($admin)
            ->putJson($this->url($company, $target), $this->payload('department_manager', [$department]))
            ->assertOk();

        $this->actingAs($target)
            ->getJson("/api/v1/companies/{$company}/employees")
            ->assertOk();

        $this->actingAs($admin)
            ->deleteJson($this->url($company, $target))
            ->assertNoContent();

        $this->assertDatabaseMissing('company_user_roles', [
            'user_id' => $target->id, 'company_id' => $company,
        ]);
        $this->assertSame(0, DB::table('department_access_scopes')->where('user_id', $target->id)->count());
        $this->assertDatabaseHas('hr_audit_events', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $target->id,
            'action' => HrAuditAction::RoleRevoked->value,
        ]);

        $this->actingAs($target)
            ->getJson("/api/v1/companies/{$company}/employees")
            ->assertForbidden();
    }

    public function test_revoke_is_company_scoped(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $admin = $this->admin($a);
        $target = User::factory()->create();
        $this->grant($target, $a, CompanyRole::Employee);
        $this->grant($target, $b, CompanyRole::HrSpecialist);

        $this->actingAs($admin)
            ->deleteJson($this->url($a, $target))
            ->assertNoContent();

        $this->assertDatabaseHas('company_user_roles', [
            'user_id' => $target->id, 'company_id' => $b, 'role_code' => 'hr_specialist',
        ]);
        $this->assertDatabaseMissing('company_user_roles', [
            'user_id' => $target->id, 'company_id' => $a,
        ]);
    }

    public function test_unknown_target_is_not_provisioned_and_raises_404(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $unknown = new User();
        $unknown->id = 99999999;

        $this->actingAs($admin)
            ->putJson($this->url($company, $unknown), $this->payload('employee'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->deleteJson($this->url($company, $unknown))
            ->assertNotFound();

        $this->assertSame(0, $this->auditCount());
    }

    public function test_revoking_nonmember_returns_404_without_audit(): void
    {
        $company = $this->company('A');
        $admin = $this->admin($company);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->deleteJson($this->url($company, $target))
            ->assertNotFound();

        $this->assertSame(0, $this->auditCount());
    }

    public function test_successful_employee_reads_are_audited_without_personnel_data(): void
    {
        $company = $this->company('A');
        $reader = User::factory()->create();
        $this->grant($reader, $company, CompanyRole::HrSpecialist);

        $this->actingAs($reader)
            ->getJson("/api/v1/companies/{$company}/employees")
            ->assertOk();

        $audit = DB::table('hr_audit_events')->first();
        $this->assertSame(HrAuditAction::EmployeesListed->value, $audit->action);
        $this->assertSame((int) $reader->id, (int) $audit->actor_user_id);
        $this->assertSame($company, $audit->company_id);
        $this->assertNull($audit->subject_user_id);
        $context = json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $context['returned_count']);
        $this->assertArrayNotHasKey('personnel_number', $context);
        $this->assertArrayNotHasKey('salary', $context);
        $this->assertArrayNotHasKey('employee_ids', $context);
    }

    public function test_denied_reads_do_not_create_success_audit_events(): void
    {
        $company = $this->company('A');
        $reader = User::factory()->create();

        $this->actingAs($reader)
            ->getJson("/api/v1/companies/{$company}/employees")
            ->assertForbidden();

        $this->assertSame(0, $this->auditCount());
    }

    public function test_admin_does_not_leak_membership_validation_to_foreign_company(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $admin = $this->admin($a);
        $target = User::factory()->create();

        // Unauthorized company check happens before payload validation.
        $this->actingAs($admin)
            ->putJson($this->url($b, $target), ['role_code' => 'not-valid'])
            ->assertForbidden();
    }
}
