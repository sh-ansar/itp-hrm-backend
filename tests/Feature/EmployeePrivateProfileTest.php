<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeePrivateProfileTest extends TestCase
{
    use RefreshDatabase;

    private function setupEmployee(): array
    {
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $hr = User::factory()->create();
        $manager = User::factory()->create();

        DB::table('companies')->insert(['id' => $company, 'name' => 'HRM Tenant']);
        DB::table('departments')->insert([
            'id' => $department, 'company_id' => $company, 'name' => 'Operations',
        ]);
        DB::table('positions')->insert([
            'id' => $position, 'company_id' => $company, 'name' => 'Engineer',
        ]);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company,
            'personnel_number' => 'HR-EMP-1', 'last_name' => 'Example',
            'first_name' => 'Employee',
        ]);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'department_id' => $department, 'position_id' => $position,
            'valid_from' => '2025-01-01',
        ]);
        DB::table('company_user_roles')->insert([
            ['company_id' => $company, 'user_id' => $hr->id, 'role_code' => 'hr_specialist'],
            ['company_id' => $company, 'user_id' => $manager->id, 'role_code' => 'department_manager'],
        ]);
        DB::table('department_access_scopes')->insert([
            'company_id' => $company, 'user_id' => $manager->id, 'department_id' => $department,
        ]);
        $base = "/api/v1/companies/{$company}/employees/{$employee}";
        return compact('company', 'employee', 'hr', 'manager', 'base');
    }

    private function personal(?int $revision = null): array
    {
        return [
            'revision' => $revision,
            'login' => 'hr-employee',
            'iin' => '61007557411',
            'nationality' => 'Georgian',
            'citizenship' => 'GE',
            'marital_status' => 'married',
            'residence_address' => 'Private Residence',
            'registration_address' => 'Registry Address',
            'address_entrance' => 'B',
            'address_floor' => '4',
            'address_apartment' => '27',
            'place_of_birth' => 'Batumi',
            'emergency_contact_name' => 'Contact Person',
            'emergency_contact_phone' => '+995000000000',
        ];
    }

    private function military(?int $revision = null): array
    {
        return [
            'revision' => $revision,
            'status' => 'liable',
            'category' => 'reserve',
            'document_reference' => 'MIL-TEST-REF',
            'comment' => 'Verified by HR',
        ];
    }

    public function test_hr_gets_empty_private_projection_even_while_writes_disabled(): void
    {
        extract($this->setupEmployee());
        $this->actingAs($hr)->getJson($base)
            ->assertOk()
            ->assertJsonPath('private_profile.personal.revision', null)
            ->assertJsonPath('private_profile.personal.iin', null)
            ->assertJsonPath('private_profile.military.status', null)
            ->assertJsonPath('meta.private_profile_writes_enabled', false);
        $this->actingAs($hr)->putJson($base.'/private-profile/military', $this->military())
            ->assertNotFound();
        $this->assertDatabaseCount('employee_military_registration', 0);
    }

    public function test_hr_can_create_and_update_encrypted_profile_with_revision_check(): void
    {
        extract($this->setupEmployee());
        config()->set('hrm.personnel_writes_enabled', true);

        $this->actingAs($hr)->putJson($base.'/private-profile/personal', $this->personal())
            ->assertOk()->assertJsonPath('data.revision', 1);
        $db = DB::table('employee_private_profiles')->where('employee_id', $employee)->first();
        $this->assertNotNull($db);
        $this->assertNotSame('61007557411', $db->iin_ciphertext);
        $this->assertStringNotContainsString('61007557411', $db->iin_ciphertext);

        $this->actingAs($hr)->getJson($base)
            ->assertOk()
            ->assertJsonPath('private_profile.personal.iin', '61007557411')
            ->assertJsonPath('private_profile.personal.residence_address', 'Private Residence')
            ->assertJsonPath('meta.private_profile_writes_enabled', true);

        $changed = $this->personal(1);
        $changed['registration_address'] = 'Updated Registry Address';
        $this->actingAs($hr)->putJson($base.'/private-profile/personal', $changed)
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($hr)->putJson($base.'/private-profile/personal', $this->personal(1))
            ->assertStatus(409);
        $this->actingAs($hr)->putJson($base.'/private-profile/personal', $this->personal())
            ->assertStatus(409);
        $this->assertDatabaseHas('employee_private_profiles', [
            'employee_id' => $employee,
            'company_id' => $company,
            'revision' => 2,
            'registration_address' => 'Updated Registry Address',
        ]);
        $audit = DB::table('hr_audit_events')
            ->where('company_id', $company)
            ->where('action', 'personnel.private_profile_updated')->get();
        $this->assertCount(2, $audit);
        foreach ($audit as $event) {
            $this->assertStringNotContainsString('61007557411', $event->context);
            $this->assertStringNotContainsString('Private Residence', $event->context);
        }
    }

    public function test_military_section_is_versioned_and_independent_of_personal(): void
    {
        extract($this->setupEmployee());
        config()->set('hrm.personnel_writes_enabled', true);

        $this->actingAs($hr)->putJson($base.'/private-profile/military', $this->military())
            ->assertOk()->assertJsonPath('data.section', 'military')
            ->assertJsonPath('data.revision', 1);
        $changed = $this->military(1);
        $changed['category'] = 'conscript';
        $this->actingAs($hr)->putJson($base.'/private-profile/military', $changed)
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($hr)->getJson($base)
            ->assertOk()
            ->assertJsonPath('private_profile.military.category', 'conscript')
            ->assertJsonPath('private_profile.military.revision', 2)
            ->assertJsonPath('private_profile.personal.revision', null);
        $this->assertDatabaseHas('employee_military_registration', [
            'employee_id' => $employee,
            'category' => 'conscript',
            'revision' => 2,
        ]);
    }

    public function test_manager_and_cross_tenant_user_cannot_read_or_modify_private_data(): void
    {
        extract($this->setupEmployee());
        config()->set('hrm.personnel_writes_enabled', true);
        $this->actingAs($hr)->putJson($base.'/private-profile/personal', $this->personal())
            ->assertOk();

        $this->actingAs($manager)->getJson($base)->assertOk()
            ->assertJsonMissingPath('private_profile')
            ->assertJsonPath('meta.private_profile_writes_enabled', false);
        $this->actingAs($manager)->putJson($base.'/private-profile/personal', $this->personal(1))
            ->assertForbidden();

        $other = User::factory()->create();
        $anotherCompany = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $anotherCompany, 'name' => 'Other']);
        DB::table('company_user_roles')->insert([
            'company_id' => $anotherCompany, 'user_id' => $other->id, 'role_code' => 'company_admin',
        ]);
        $this->actingAs($other)->getJson($base)->assertForbidden();
        $this->actingAs($other)->putJson($base.'/private-profile/military', $this->military())
            ->assertForbidden();

        $this->assertDatabaseCount('employee_military_registration', 0);
    }

    public function test_missing_and_unknown_fields_or_wrong_revision_are_rejected_atomically(): void
    {
        extract($this->setupEmployee());
        config()->set('hrm.personnel_writes_enabled', true);

        $noStatus = $this->military();
        unset($noStatus['status']);
        $this->actingAs($hr)->putJson($base.'/private-profile/military', $noStatus)
            ->assertUnprocessable();
        $this->actingAs($hr)->putJson($base.'/private-profile/military', [
            ...$this->military(), 'company_id' => $company,
        ])->assertUnprocessable();
        $this->actingAs($hr)->putJson($base.'/private-profile/personal', [
            ...$this->personal(), 'iin' => str_repeat('X', 40),
        ])->assertUnprocessable();

        $this->actingAs($hr)->putJson($base.'/private-profile/military', $this->military(14))
            ->assertStatus(409);
        $this->assertDatabaseCount('employee_private_profiles', 0);
        $this->assertDatabaseCount('employee_military_registration', 0);
        $this->assertDatabaseCount('hr_audit_events', 0);
    }
}
