<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeEducationRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function setupEmployee(): array
    {
        $user = User::factory()->create();
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Test Company']);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company,
            'personnel_number' => 'EDU-001', 'first_name' => 'Synthetic', 'last_name' => 'Student',
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $user->id, 'role_code' => 'hr_specialist',
        ]);
        $base = "/api/v1/companies/{$company}/employees/{$employee}";
        return compact('user', 'company', 'employee', 'base');
    }

    private function data(array $values = []): array
    {
        return array_merge([
            'education_type' => 'higher_education',
            'organization' => 'Synthetic Technical University',
            'specialization' => 'Engineering',
            'started_on' => '2010-09-01',
            'ended_on' => '2014-06-01',
            'document_reference' => null,
        ], $values);
    }

    public function test_read_projection_is_empty_and_writes_are_disabled_by_default(): void
    {
        extract($this->setupEmployee());
        $this->actingAs($user)->getJson($base)->assertOk()
            ->assertJsonCount(0, 'private_profile.education')
            ->assertJsonPath('meta.private_education_truncated', false);
        $this->actingAs($user)->postJson($base.'/education', $this->data())
            ->assertNotFound();
        $this->assertDatabaseCount('employee_education_records', 0);
    }

    public function test_hr_creates_edits_and_archives_education_without_deleting_history(): void
    {
        extract($this->setupEmployee());
        config()->set('hrm.personnel_writes_enabled', true);
        $url = $base.'/education';

        $first = $this->actingAs($user)->postJson($url, $this->data())
            ->assertCreated()->assertJsonPath('data.revision', 1)->json('data.id');
        $second = $this->actingAs($user)->postJson($url, $this->data([
            'organization' => 'Synthetic School Two', 'started_on' => null,
            'ended_on' => null, 'specialization' => null,
        ]))->assertCreated()->json('data.id');

        $this->actingAs($user)->getJson($base)
            ->assertOk()->assertJsonCount(2, 'private_profile.education');
        $this->assertDatabaseHas('employee_education_records', [
            'id' => $second, 'started_on' => null, 'ended_on' => null,
        ]);

        $this->actingAs($user)->putJson($url.'/'.$first, [
            ...$this->data(['organization' => 'Corrected University']), 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($user)->putJson($url.'/'.$first, [
            ...$this->data(), 'revision' => 1,
        ])->assertStatus(409);
        $this->actingAs($user)->deleteJson($url.'/'.$first, ['revision' => 2])
            ->assertOk()->assertJsonPath('data.archived', true);
        $this->actingAs($user)->deleteJson($url.'/'.$first, ['revision' => 2])
            ->assertNotFound();
        $this->actingAs($user)->getJson($base)
            ->assertOk()->assertJsonCount(1, 'private_profile.education')
            ->assertJsonPath('private_profile.education.0.id', $second);
        $this->assertDatabaseCount('employee_education_records', 2);
        $this->assertDatabaseHas('employee_education_records', [
            'id' => $first, 'organization' => 'Corrected University', 'revision' => 3,
        ]);
        $this->assertNotNull(DB::table('employee_education_records')
            ->where('id', $first)->value('archived_at'));
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.education_archived',
        ]);
        foreach (DB::table('hr_audit_events')->where('company_id', $company)->get() as $event) {
            $this->assertStringNotContainsString('Synthetic Technical University', $event->context);
            $this->assertStringNotContainsString('Corrected University', $event->context);
        }
    }

    public function test_invalid_period_unknown_fields_and_cross_company_writes_fail(): void
    {
        extract($this->setupEmployee());
        config()->set('hrm.personnel_writes_enabled', true);
        $url = $base.'/education';

        $this->actingAs($user)->postJson($url, $this->data([
            'ended_on' => '2009-08-31',
        ]))->assertUnprocessable();
        $this->actingAs($user)->postJson($url, [
            ...$this->data(), 'company_id' => $company,
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson($url, $this->data([
            'organization' => '',
        ]))->assertUnprocessable();
        $this->actingAs($user)->postJson($url, [
            ...$this->data(), 'revision' => 1,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('employee_education_records', 0);

        $other = User::factory()->create();
        $otherCompany = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $otherCompany, 'name' => 'Other Tenant']);
        DB::table('company_user_roles')->insert([
            'company_id' => $otherCompany, 'user_id' => $other->id, 'role_code' => 'company_admin',
        ]);
        $this->actingAs($other)->postJson($url, $this->data())->assertForbidden();
        $this->actingAs($other)->getJson($base)->assertForbidden();
    }

    public function test_department_manager_cannot_project_formal_education_records(): void
    {
        extract($this->setupEmployee());
        config()->set('hrm.personnel_writes_enabled', true);
        $manager = User::factory()->create();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        DB::table('departments')->insert([
            'id' => $department, 'company_id' => $company, 'name' => 'Restricted',
        ]);
        DB::table('positions')->insert([
            'id' => $position, 'company_id' => $company, 'name' => 'Engineer',
        ]);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'department_id' => $department, 'position_id' => $position,
            'valid_from' => '2020-01-01',
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $manager->id, 'role_code' => 'department_manager',
        ]);
        DB::table('department_access_scopes')->insert([
            'company_id' => $company, 'user_id' => $manager->id, 'department_id' => $department,
        ]);

        $this->actingAs($user)->postJson($base.'/education', $this->data())
            ->assertCreated();
        $this->actingAs($manager)->getJson($base)->assertOk()
            ->assertJsonMissingPath('private_profile');
        $this->actingAs($manager)->postJson($base.'/education', $this->data())
            ->assertForbidden();
    }
}
