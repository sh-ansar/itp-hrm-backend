<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PositionRecordWriteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'hr_specialist'): array
    {
        $company = (string) Str::uuid();
        $position = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Primary']);
        DB::table('positions')->insert([
            'id' => $position,
            'company_id' => $company,
            'name' => 'Engineer',
            'revision' => 1,
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $role,
        ]);

        config()->set('hrm.personnel_writes_enabled', true);

        return compact('company', 'position', 'user');
    }

    public function test_hr_can_create_update_and_delete_unused_position_with_audit(): void
    {
        extract($this->fixture());

        $response = $this->actingAs($user)->postJson("/api/v1/companies/{$company}/positions", [
            'name' => 'Analyst',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Analyst')
            ->assertJsonPath('data.revision', 1);

        $id = $response->json('data.id');

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/positions/{$id}", [
            'name' => 'Senior analyst',
            'revision' => 1,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Senior analyst')
            ->assertJsonPath('data.revision', 2);

        $this->actingAs($user)->deleteJson("/api/v1/companies/{$company}/positions/{$id}", [
            'revision' => 2,
        ])->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseMissing('positions', ['id' => $id]);
        foreach (['organization.position_created', 'organization.position_updated', 'organization.position_deleted'] as $action) {
            $this->assertDatabaseHas('hr_audit_events', [
                'company_id' => $company,
                'actor_user_id' => $user->id,
                'action' => $action,
            ]);
        }
    }

    public function test_stale_revision_and_manager_write_are_denied(): void
    {
        extract($this->fixture());

        DB::table('positions')->where('id', $position)->update([
            'name' => 'Changed elsewhere',
            'revision' => 2,
        ]);

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/positions/{$position}", [
            'name' => 'Stale',
            'revision' => 1,
        ])->assertConflict();

        DB::table('company_user_roles')->where('user_id', $user->id)->update(['role_code' => 'department_manager']);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/positions", [
            'name' => 'Denied',
        ])->assertForbidden();

        $this->assertDatabaseMissing('positions', ['company_id' => $company, 'name' => 'Denied']);
    }

    public function test_position_used_by_assignment_or_staffing_plan_cannot_be_deleted(): void
    {
        extract($this->fixture());

        $department = (string) Str::uuid();
        $employee = (string) Str::uuid();
        DB::table('departments')->insert([
            'id' => $department,
            'company_id' => $company,
            'name' => 'Operations',
            'revision' => 1,
        ]);
        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'EMP-POS',
            'last_name' => 'Position',
            'first_name' => 'User',
            'revision' => 1,
        ]);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $employee,
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
            'revision' => 1,
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/companies/{$company}/positions/{$position}", [
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['position']);

        $this->assertDatabaseHas('positions', ['id' => $position]);
    }

    public function test_read_response_exposes_revision_and_write_capability(): void
    {
        extract($this->fixture());

        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/positions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $position)
            ->assertJsonPath('data.0.revision', 1)
            ->assertJsonPath('meta.position_writes_enabled', true);
    }
}
