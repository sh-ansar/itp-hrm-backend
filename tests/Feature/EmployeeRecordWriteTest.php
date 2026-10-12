<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeRecordWriteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'hr_specialist'): array
    {
        $company = (string) Str::uuid();
        $otherCompany = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Primary'],
            ['id' => $otherCompany, 'name' => 'Other'],
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $role,
        ]);
        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'EMP-001',
            'last_name' => 'Existing',
            'first_name' => 'Employee',
            'middle_name' => null,
            'revision' => 1,
        ]);

        return compact('company', 'otherCompany', 'employee', 'user');
    }

    public function test_create_is_fail_closed_when_personnel_writes_are_disabled(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', false);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/employees", [
            'personnel_number' => 'EMP-002',
            'last_name' => 'New',
            'first_name' => 'Employee',
        ])->assertNotFound();

        $this->assertDatabaseMissing('employees', ['personnel_number' => 'EMP-002']);
    }

    public function test_hr_can_create_employee_with_atomic_audit(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);

        $response = $this->actingAs($user)->postJson("/api/v1/companies/{$company}/employees", [
            'personnel_number' => 'EMP-002',
            'last_name' => 'New',
            'first_name' => 'Employee',
            'middle_name' => 'Test',
        ])->assertCreated()
            ->assertJsonPath('data.personnel_number', 'EMP-002')
            ->assertJsonPath('data.revision', 1);

        $id = $response->json('data.id');
        $this->assertDatabaseHas('employees', [
            'id' => $id,
            'company_id' => $company,
            'personnel_number' => 'EMP-002',
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'personnel.employee_created',
        ]);
    }

    public function test_personnel_number_is_unique_within_company_but_reusable_in_another_company(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/employees", [
            'personnel_number' => 'EMP-001',
            'last_name' => 'Duplicate',
            'first_name' => 'Employee',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['personnel_number']);

        DB::table('employees')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $otherCompany,
            'personnel_number' => 'EMP-001',
            'last_name' => 'Other',
            'first_name' => 'Company',
            'revision' => 1,
        ]);

        $this->assertDatabaseCount('employees', 2);
    }

    public function test_hr_can_update_employee_with_revision(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/employees/{$employee}", [
            'personnel_number' => 'EMP-009',
            'last_name' => 'Updated',
            'first_name' => 'Employee',
            'middle_name' => 'Middle',
            'revision' => 1,
        ])->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.personnel_number', 'EMP-009');

        $this->assertDatabaseHas('employees', [
            'id' => $employee,
            'personnel_number' => 'EMP-009',
            'last_name' => 'Updated',
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'personnel.employee_updated',
        ]);
    }

    public function test_stale_employee_update_is_rejected_without_overwrite_or_audit(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        DB::table('employees')->where('id', $employee)->update([
            'last_name' => 'Changed elsewhere',
            'revision' => 2,
        ]);

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/employees/{$employee}", [
            'personnel_number' => 'EMP-001',
            'last_name' => 'Stale',
            'first_name' => 'Employee',
            'middle_name' => null,
            'revision' => 1,
        ])->assertConflict();

        $this->assertDatabaseHas('employees', [
            'id' => $employee,
            'last_name' => 'Changed elsewhere',
            'revision' => 2,
        ]);
        $this->assertDatabaseMissing('hr_audit_events', ['action' => 'personnel.employee_updated']);
    }

    public function test_department_manager_cannot_create_or_update_employee(): void
    {
        extract($this->fixture('department_manager'));
        config()->set('hrm.personnel_writes_enabled', true);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/employees", [
            'personnel_number' => 'EMP-002',
            'last_name' => 'No',
            'first_name' => 'Access',
        ])->assertForbidden();

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/employees/{$employee}", [
            'personnel_number' => 'EMP-001',
            'last_name' => 'No',
            'first_name' => 'Access',
            'middle_name' => null,
            'revision' => 1,
        ])->assertForbidden();
    }
}
