<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DepartmentRecordWriteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'hr_specialist'): array
    {
        $company = (string) Str::uuid();
        $otherCompany = (string) Str::uuid();
        $root = (string) Str::uuid();
        $child = (string) Str::uuid();
        $other = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Primary'],
            ['id' => $otherCompany, 'name' => 'Other'],
        ]);
        DB::table('departments')->insert([
            ['id' => $root, 'company_id' => $company, 'parent_id' => null, 'name' => 'Root', 'revision' => 1],
            ['id' => $child, 'company_id' => $company, 'parent_id' => $root, 'name' => 'Child', 'revision' => 1],
            ['id' => $other, 'company_id' => $otherCompany, 'parent_id' => null, 'name' => 'Other', 'revision' => 1],
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $role,
        ]);

        config()->set('hrm.personnel_writes_enabled', true);

        return compact('company', 'otherCompany', 'root', 'child', 'other', 'user');
    }

    public function test_hr_can_create_and_revision_update_department_with_audit(): void
    {
        extract($this->fixture());

        $response = $this->actingAs($user)->postJson("/api/v1/companies/{$company}/departments", [
            'name' => 'New team',
            'parent_id' => $root,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'New team')
            ->assertJsonPath('data.parent_id', $root)
            ->assertJsonPath('data.revision', 1);

        $id = $response->json('data.id');

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/departments/{$id}", [
            'name' => 'Renamed team',
            'parent_id' => $root,
            'revision' => 1,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed team')
            ->assertJsonPath('data.revision', 2);

        $this->assertDatabaseHas('departments', [
            'id' => $id,
            'company_id' => $company,
            'name' => 'Renamed team',
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'organization.department_created',
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'organization.department_updated',
        ]);
    }

    public function test_cycle_and_cross_company_parent_are_rejected_without_partial_update(): void
    {
        extract($this->fixture());

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/departments/{$root}", [
            'name' => 'Root',
            'parent_id' => $child,
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/departments/{$child}", [
            'name' => 'Child',
            'parent_id' => $other,
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertDatabaseHas('departments', [
            'id' => $root,
            'parent_id' => null,
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('departments', [
            'id' => $child,
            'parent_id' => $root,
            'revision' => 1,
        ]);
    }

    public function test_stale_revision_and_manager_writes_are_denied(): void
    {
        extract($this->fixture());

        DB::table('departments')->where('id', $child)->update([
            'name' => 'Changed elsewhere',
            'revision' => 2,
        ]);

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/departments/{$child}", [
            'name' => 'Stale',
            'parent_id' => $root,
            'revision' => 1,
        ])->assertConflict();

        DB::table('company_user_roles')->where('user_id', $user->id)->update(['role_code' => 'department_manager']);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/departments", [
            'name' => 'Denied',
            'parent_id' => null,
        ])->assertForbidden();

        $this->assertDatabaseMissing('departments', [
            'company_id' => $company,
            'name' => 'Denied',
        ]);
    }

    public function test_explicit_department_manager_is_company_scoped_active_and_revisable(): void
    {
        extract($this->fixture());
        $employee = (string) Str::uuid();
        $foreignEmployee = (string) Str::uuid();
        $position = (string) Str::uuid();
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Lead']);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company,
                'personnel_number' => 'LEAD-1', 'last_name' => 'Manager', 'first_name' => 'Test'],
            ['id' => $foreignEmployee, 'company_id' => $otherCompany,
                'personnel_number' => 'LEAD-2', 'last_name' => 'Foreign', 'first_name' => 'Manager'],
        ]);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'department_id' => $root, 'position_id' => $position, 'valid_from' => '2026-01-01',
        ]);

        $url = "/api/v1/companies/{$company}/departments/{$child}";
        $this->actingAs($user)->putJson($url, [
            'name' => 'Child', 'parent_id' => $root,
            'manager_employee_id' => $foreignEmployee, 'revision' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('manager_employee_id');
        $this->assertDatabaseHas('departments', ['id' => $child, 'manager_employee_id' => null, 'revision' => 1]);

        $this->putJson($url, [
            'name' => 'Child', 'parent_id' => $root,
            'manager_employee_id' => $employee, 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.manager_employee_id', $employee)->assertJsonPath('data.revision', 2);
        $this->getJson("/api/v1/companies/{$company}/departments?per_page=100")
            ->assertOk()->assertJsonPath('meta.manager_assignment_supported', true)
            ->assertJsonFragment(['manager_employee_id' => $employee, 'manager_employee_name' => 'Manager Test']);

        $this->putJson($url, ['name' => 'Renamed', 'parent_id' => $root, 'revision' => 2])
            ->assertOk()->assertJsonPath('data.manager_employee_id', $employee);
        $this->putJson($url, [
            'name' => 'Renamed', 'parent_id' => $root,
            'manager_employee_id' => null, 'revision' => 3,
        ])->assertOk()->assertJsonPath('data.manager_employee_id', null);
        $this->assertDatabaseHas('departments', ['id' => $child, 'manager_employee_id' => null, 'revision' => 4]);
    }

    public function test_inactive_manager_cannot_be_selected(): void
    {
        extract($this->fixture());
        $employee = (string) Str::uuid();
        DB::table('employees')->insert(['id' => $employee, 'company_id' => $company,
            'personnel_number' => 'INACTIVE', 'last_name' => 'Inactive', 'first_name' => 'Employee']);
        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/departments/{$child}", [
            'name' => 'Child', 'parent_id' => $root,
            'manager_employee_id' => $employee, 'revision' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('manager_employee_id');
    }

    public function test_delete_only_allows_unused_leaf_department(): void
    {
        extract($this->fixture());

        $this->actingAs($user)->deleteJson("/api/v1/companies/{$company}/departments/{$root}", [
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['department']);

        $leaf = (string) Str::uuid();
        DB::table('departments')->insert([
            'id' => $leaf,
            'company_id' => $company,
            'parent_id' => $root,
            'name' => 'Temporary leaf',
            'revision' => 1,
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/companies/{$company}/departments/{$leaf}", [
            'revision' => 1,
        ])->assertOk()
            ->assertJsonPath('data.id', $leaf);

        $this->assertDatabaseMissing('departments', ['id' => $leaf]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'organization.department_deleted',
        ]);
    }
}
