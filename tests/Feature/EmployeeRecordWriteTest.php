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
    public function test_legacy_identity_edit_preserves_contacts_and_workplace_and_audits_field_names_only(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        DB::table('employees')->where('id', $employee)->update([
            'birth_date' => '1990-06-01', 'email' => 'synthetic@example.test',
            'phone' => '+77770000000', 'work_team' => 'Synthetic team',
        ]);
        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/employees/{$employee}", [
            'personnel_number' => 'EMP-001', 'last_name' => 'Updated',
            'first_name' => 'Employee', 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.email', 'synthetic@example.test')
            ->assertJsonPath('data.birth_date', '1990-06-01')
            ->assertJsonPath('data.work_team', 'Synthetic team');
        $this->assertDatabaseHas('employees', ['id' => $employee, 'phone' => '+77770000000']);
        $event = DB::table('hr_audit_events')->where('action', 'personnel.employee_updated')->first();
        $context = json_decode($event->context, true);
        $this->assertSame(['last_name'], $context['changed_fields']);
        $this->assertStringNotContainsString('synthetic@example.test', $event->context);
        $this->assertStringNotContainsString('Updated', $event->context);
    }

    public function test_explicit_null_clears_one_contact_without_erasing_other_fields(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        DB::table('employees')->where('id', $employee)->update([
            'email' => 'synthetic@example.test', 'phone' => '+77770000000',
        ]);
        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/employees/{$employee}", [
            'personnel_number' => 'EMP-001', 'last_name' => 'Existing',
            'first_name' => 'Employee', 'email' => null, 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.email', null)
            ->assertJsonPath('data.phone', '+77770000000');
        $this->assertDatabaseHas('employees', ['id' => $employee, 'email' => null]);
    }

    public function test_invalid_contacts_and_unknown_fields_do_not_create_employees(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $body = ['personnel_number' => 'EMP-002', 'last_name' => 'New', 'first_name' => 'Person'];
        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/employees", [
            ...$body, 'birth_date' => now()->addDay()->toDateString(), 'email' => 'invalid', 'gender' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['birth_date', 'email', 'gender']);
        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/employees", [
            ...$body, 'company_id' => $otherCompany,
        ])->assertUnprocessable()->assertJsonValidationErrors(['employee']);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseMissing('hr_audit_events', ['action' => 'personnel.employee_created']);
    }

    public function test_card_history_is_company_and_employee_scoped_and_excludes_raw_context(): void
    {
        extract($this->fixture());
        $events = [
            [$company, $employee, 'personnel.employee_updated'],
            [$otherCompany, $employee, 'personnel.employee_updated'],
            [$company, (string) Str::uuid(), 'personnel.employee_updated'],
            [$company, $employee, 'personnel.employees_listed'],
        ];
        foreach ($events as [$eventCompany, $eventEmployee, $action]) {
            DB::table('hr_audit_events')->insert([
                'company_id' => $eventCompany, 'actor_user_id' => $user->id,
                'action' => $action,
                'context' => json_encode(['employee_id' => $eventEmployee, 'private' => 'SENSITIVE']),
                'created_at' => now(),
            ]);
        }
        $response = $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertOk()->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.action', 'personnel.employee_updated')
            ->assertJsonPath('meta.history_truncated', false);
        $this->assertSame(['id', 'action', 'created_at', 'actor'], array_keys($response->json('history.0')));
        $this->assertStringNotContainsString('SENSITIVE', $response->getContent());
        $this->actingAs($user)->getJson("/api/v1/companies/{$otherCompany}/employees/{$employee}")
            ->assertForbidden();
    }

    public function test_card_history_is_bounded_and_latest_first(): void
    {
        extract($this->fixture());
        for ($i = 0; $i < 101; $i++) {
            DB::table('hr_audit_events')->insert([
                'company_id' => $company, 'actor_user_id' => $user->id,
                'action' => 'personnel.employee_updated',
                'context' => json_encode(['employee_id' => $employee]), 'created_at' => now(),
            ]);
        }
        $latestId = DB::table('hr_audit_events')->max('id');
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertOk()->assertJsonCount(100, 'history')
            ->assertJsonPath('history.0.id', $latestId)
            ->assertJsonPath('meta.history_truncated', true);
    }

    public function test_profile_writes_are_linked_to_employee_history(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $body = ['experience_type' => 'Work', 'started_on' => '2020-01-01'];
        $record = $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/profile/experience", $body)
            ->assertCreated()->json('data.id');
        $this->actingAs($user)
            ->putJson("/api/v1/companies/{$company}/employees/{$employee}/profile/experience/{$record}", [
                ...$body, 'experience_type' => 'Updated work', 'revision' => 1,
            ])->assertOk();
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertOk()->assertJsonCount(2, 'history')
            ->assertJsonPath('history.0.action', 'personnel.profile_record_updated')
            ->assertJsonPath('history.1.action', 'personnel.profile_record_created')
            ->assertJsonMissingPath('history.0.context');
    }

}
