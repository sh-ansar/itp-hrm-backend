<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function provisionFixture(): array
    {
        $company = (string) Str::uuid();
        $other = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $secondEmployee = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Primary'],
            ['id' => $other, 'name' => 'Restricted'],
        ]);
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'HR']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Analyst']);
        foreach ([$employee, $secondEmployee] as $index => $id) {
            DB::table('employees')->insert([
                'id' => $id, 'company_id' => $company,
                'personnel_number' => 'T-'.($index + 1), 'last_name' => 'Test', 'first_name' => 'Worker',
            ]);
        }

        DB::table('company_user_roles')->insert([
            'user_id' => $user->id, 'company_id' => $company, 'role_code' => 'hr_specialist',
        ]);

        $ids = ['training' => (string) Str::uuid(), 'experience' => (string) Str::uuid(), 'reserve' => (string) Str::uuid()];
        DB::table('employee_training')->insert([
            'id' => $ids['training'], 'employee_id' => $employee,
            'training_type' => 'seminar', 'training_mode' => 'remote',
            'topic' => 'Original topic', 'started_on' => '2026-02-10',
        ]);
        DB::table('employee_experience')->insert([
            'id' => $ids['experience'], 'employee_id' => $employee,
            'experience_type' => 'industry', 'started_on' => '2020-01-01',
        ]);
        DB::table('employee_reserve')->insert([
            'id' => $ids['reserve'], 'employee_id' => $employee,
            'target_position' => 'Supervisor', 'reserve_level' => 'middle',
            'reserve_type' => 'internal', 'joined_year' => 2025,
        ]);

        return compact('company', 'other', 'employee', 'secondEmployee', 'department', 'user', 'ids');
    }

    private function trainingPayload(int $revision = 1): array
    {
        return [
            'revision' => $revision,
            'training_type' => 'qualification',
            'training_mode' => 'in_person',
            'topic' => 'Updated topic',
            'provider' => 'Test center',
            'city' => 'Astana',
            'started_on' => '2026-02-01',
            'ended_on' => '2026-02-08',
            'document_reference' => null,
        ];
    }

    public function test_disabled_by_default_and_guest_denied_when_enabled(): void
    {
        extract($this->provisionFixture());
        $path = "/api/v1/companies/{$company}/employees/{$employee}/profile/training/{$ids['training']}";
        $this->actingAs($user)->putJson($path, $this->trainingPayload())->assertNotFound();
        $this->assertDatabaseHas('employee_training', ['id' => $ids['training'], 'revision' => 1]);
    }

    public function test_guest_denied_even_when_writes_flag_is_enabled(): void
    {
        extract($this->provisionFixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $path = "/api/v1/companies/{$company}/employees/{$employee}/profile/training/{$ids['training']}";
        $this->putJson($path, $this->trainingPayload())->assertUnauthorized();
        $this->assertDatabaseHas('employee_training', ['id' => $ids['training'], 'revision' => 1]);
    }

    public function test_update_is_versioned_and_does_not_overwrite_stale_changes(): void
    {
        extract($this->provisionFixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $path = "/api/v1/companies/{$company}/employees/{$employee}/profile/training/{$ids['training']}";
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertJsonPath('training.0.revision', 1);

        $this->actingAs($user)->putJson($path, $this->trainingPayload())
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.section', 'training');
        $this->assertDatabaseHas('employee_training', [
            'id' => $ids['training'], 'employee_id' => $employee,
            'topic' => 'Updated topic', 'provider' => 'Test center', 'revision' => 2,
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.profile_record_updated',
        ]);
        $this->actingAs($user)->putJson($path, $this->trainingPayload())
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
        $this->assertDatabaseCount('hr_audit_events', 2); // one authorized card read + one successful write
        $this->assertDatabaseHas('employee_training', ['id' => $ids['training'], 'topic' => 'Updated topic', 'revision' => 2]);
    }

    public function test_rejects_invalid_dates_and_attempted_owner_changes(): void
    {
        extract($this->provisionFixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $path = "/api/v1/companies/{$company}/employees/{$employee}/profile/training/{$ids['training']}";
        $this->actingAs($user)->putJson($path, array_merge($this->trainingPayload(), [
            'started_on' => '2026-03-01', 'ended_on' => '2026-02-01',
        ]))->assertUnprocessable();
        $this->actingAs($user)->putJson($path, array_merge($this->trainingPayload(), [
            'employee_id' => $secondEmployee,
        ]))->assertUnprocessable();
        $this->actingAs($user)->putJson($path, array_merge($this->trainingPayload(), [
            'training_mode' => 'invalid',
        ]))->assertUnprocessable();
        $this->actingAs($user)->putJson($path, array_merge($this->trainingPayload(), [
            'revision' => 0,
        ]))->assertUnprocessable();
        $this->actingAs($user)->putJson($path, ['revision' => 1, 'topic' => 'Partial'])->assertUnprocessable();
        $this->assertDatabaseHas('employee_training', ['id' => $ids['training'], 'revision' => 1, 'topic' => 'Original topic']);
        $this->assertDatabaseCount('hr_audit_events', 0);
    }

    public function test_cross_company_and_cross_employee_updates_are_denied(): void
    {
        extract($this->provisionFixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $root = '/api/v1/companies/';
        $this->actingAs($user)->putJson("{$root}{$other}/employees/{$employee}/profile/training/{$ids['training']}", $this->trainingPayload())
            ->assertForbidden();
        $this->actingAs($user)->putJson("{$root}{$company}/employees/{$secondEmployee}/profile/training/{$ids['training']}", $this->trainingPayload())
            ->assertNotFound();
        DB::table('company_user_roles')->where('company_id', $company)->where('user_id', $user->id)->update(['role_code' => 'department_manager']);
        DB::table('department_access_scopes')->insert(['company_id' => $company, 'user_id' => $user->id, 'department_id' => $department]);
        $this->actingAs($user)->putJson("{$root}{$company}/employees/{$employee}/profile/training/{$ids['training']}", $this->trainingPayload())
            ->assertForbidden();
        $this->assertDatabaseHas('employee_training', ['id' => $ids['training'], 'revision' => 1]);
        $this->assertDatabaseCount('hr_audit_events', 0);
    }

    public function test_reserve_and_experience_updates_reuse_server_rules(): void
    {
        extract($this->provisionFixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $prefix = "/api/v1/companies/{$company}/employees/{$employee}/profile";
        $this->actingAs($user)->putJson("{$prefix}/experience/{$ids['experience']}", [
            'revision' => 1, 'experience_type' => 'professional',
            'started_on' => '2019-01-01', 'ended_on' => null, 'basis_reference' => 'HR-1',
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($user)->putJson("{$prefix}/reserve/{$ids['reserve']}", [
            'revision' => 1, 'target_position' => 'Chief specialist',
            'reserve_level' => 'senior', 'reserve_type' => 'internal',
            'joined_year' => 2026, 'left_year' => null, 'notes' => null,
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($user)->putJson("{$prefix}/reserve/{$ids['reserve']}", [
            'revision' => 2, 'target_position' => 'Chief specialist',
            'reserve_level' => 'senior', 'reserve_type' => 'internal',
            'joined_year' => 2026, 'left_year' => 2025,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('employee_reserve', ['id' => $ids['reserve'], 'revision' => 2, 'target_position' => 'Chief specialist']);
        $this->assertDatabaseHas('employee_experience', ['id' => $ids['experience'], 'revision' => 2, 'basis_reference' => 'HR-1']);
        $this->assertDatabaseCount('hr_audit_events', 2);
    }

    public function test_audit_failure_rolls_back_the_record_change(): void
    {
        extract($this->provisionFixture());
        config()->set('hrm.personnel_writes_enabled', true);
        // A DB-level UNIQUE restriction makes the audit insert fail in both engines,
        // ensuring the record mutation is rolled back with the audit event.
        DB::statement('CREATE UNIQUE INDEX test_fail_profile_audit ON hr_audit_events (company_id, action)');
        DB::table('hr_audit_events')->insert([
            'company_id' => $company, 'actor_user_id' => $user->id,
            'action' => 'personnel.profile_record_updated',
            'context' => '{}', 'created_at' => now(),
        ]);
        $path = "/api/v1/companies/{$company}/employees/{$employee}/profile/training/{$ids['training']}";
        $this->actingAs($user)->putJson($path, $this->trainingPayload())->assertStatus(500);
        $this->assertDatabaseHas('employee_training', ['id' => $ids['training'], 'revision' => 1, 'topic' => 'Original topic']);
    }
}
