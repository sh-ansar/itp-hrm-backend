<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeePrivateRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $user = User::factory()->create();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Main Tenant']);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company,
            'personnel_number' => 'REC-1', 'last_name' => 'Example', 'first_name' => 'Person',
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $user->id, 'role_code' => 'hr_specialist',
        ]);
        $base = "/api/v1/companies/{$company}/employees/{$employee}";
        return compact('company', 'employee', 'user', 'base');
    }

    private function family(array $override = []): array
    {
        return array_merge([
            'relationship' => 'child', 'full_name' => 'Synthetic Person',
            'birth_date' => '2012-04-18', 'document_reference' => null,
        ], $override);
    }

    private function identity(array $override = []): array
    {
        return array_merge([
            'type' => 'passport',
            'number' => 'SYNTHETIC-ID-001',
            'personal_number' => 'SYNTHETIC-PERSONAL-001',
            'country_of_issue' => 'GE',
            'issued_by' => 'Test Registry',
            'issued_on' => '2020-01-01',
            'expires_on' => '2030-01-01',
            'is_primary' => true,
        ], $override);
    }

    public function test_disabled_writes_and_empty_reads_are_safe(): void
    {
        extract($this->fixture());
        $this->actingAs($user)->postJson($base.'/private-records/family', $this->family())
            ->assertNotFound();
        $this->actingAs($user)->getJson($base)->assertOk()
            ->assertJsonCount(0, 'private_profile.family')
            ->assertJsonCount(0, 'private_profile.identity_documents');
    }

    public function test_repeatable_relatives_update_and_archive_with_revision_and_audit(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $url = $base.'/private-records/family';

        $first = $this->actingAs($user)->postJson($url, $this->family())
            ->assertCreated()->assertJsonPath('data.revision', 1)->json('data.id');
        $second = $this->actingAs($user)->postJson($url, $this->family([
            'relationship' => 'spouse', 'full_name' => 'Another Synthetic Person', 'birth_date' => null,
        ]))->assertCreated()->json('data.id');

        $this->actingAs($user)->getJson($base)
            ->assertOk()->assertJsonCount(2, 'private_profile.family');

        $this->actingAs($user)->putJson($url.'/'.$first, [
            ...$this->family(['full_name' => 'Updated Synthetic Person']), 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($user)->putJson($url.'/'.$first, [
            ...$this->family(), 'revision' => 1,
        ])->assertStatus(409);

        $this->actingAs($user)->deleteJson($url.'/'.$first, ['revision' => 2])
            ->assertOk()->assertJsonPath('data.archived', true);
        $this->actingAs($user)->deleteJson($url.'/'.$first, ['revision' => 2])
            ->assertNotFound();
        $this->actingAs($user)->getJson($base)->assertOk()
            ->assertJsonCount(1, 'private_profile.family')
            ->assertJsonPath('private_profile.family.0.id', $second);

        $this->assertDatabaseCount('employee_family_members', 2);
        $this->assertDatabaseHas('employee_family_members', [
            'id' => $first, 'revision' => 3, 'full_name' => 'Updated Synthetic Person',
        ]);
        $this->assertNotNull(DB::table('employee_family_members')->where('id', $first)->value('archived_at'));
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.private_record_archived',
        ]);
    }

    public function test_identity_numbers_are_encrypted_and_only_one_primary_is_exposed(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $url = $base.'/private-records/identity';

        $first = $this->actingAs($user)->postJson($url, $this->identity())
            ->assertCreated()->json('data.id');
        $record = DB::table('employee_identity_documents')->where('id', $first)->first();
        $this->assertNotSame('SYNTHETIC-ID-001', $record->number_ciphertext);
        $this->assertStringNotContainsString('SYNTHETIC-PERSONAL-001', $record->personal_number_ciphertext);

        $second = $this->actingAs($user)->postJson($url, $this->identity([
            'number' => 'SYNTHETIC-ID-002', 'personal_number' => null,
        ]))->assertCreated()->json('data.id');

        $this->actingAs($user)->getJson($base)->assertOk()
            ->assertJsonCount(2, 'private_profile.identity_documents')
            ->assertJsonPath('private_profile.identity_documents.0.id', $second)
            ->assertJsonPath('private_profile.identity_documents.0.number', 'SYNTHETIC-ID-002')
            ->assertJsonPath('private_profile.identity_documents.0.is_primary', true);

        $this->assertDatabaseHas('employee_identity_documents', [
            'id' => $first, 'is_primary' => false, 'revision' => 2,
        ]);

        $this->actingAs($user)->putJson($url.'/'.$first, [
            ...$this->identity(['number' => 'SYNTHETIC-ID-003']), 'revision' => 1,
        ])->assertStatus(409);

        $this->actingAs($user)->putJson($url.'/'.$first, [
            ...$this->identity(['number' => 'SYNTHETIC-ID-003']), 'revision' => 2,
        ])->assertOk()->assertJsonPath('data.revision', 3);

        $this->assertDatabaseHas('employee_identity_documents', [
            'id' => $first, 'is_primary' => true, 'revision' => 3,
        ]);
        $this->assertDatabaseHas('employee_identity_documents', [
            'id' => $second, 'is_primary' => false, 'revision' => 2,
        ]);
        $this->actingAs($user)->deleteJson($url.'/'.$first, ['revision' => 3])
            ->assertOk();
        $this->assertNotNull(DB::table('employee_identity_documents')->where('id', $first)->value('archived_at'));
    }

    public function test_invalid_fields_and_cross_tenant_access_never_persist_private_records(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $url = $base.'/private-records/identity';

        $this->actingAs($user)->postJson($url, [
            ...$this->identity(), 'expires_on' => '2018-01-01',
        ])->assertUnprocessable();
        $this->actingAs($user)->postJson($url, [
            ...$this->identity(), 'company_id' => $company,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('employee_identity_documents', 0);

        $attacker = User::factory()->create();
        $otherCompany = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $otherCompany, 'name' => 'Other']);
        DB::table('company_user_roles')->insert([
            'user_id' => $attacker->id, 'company_id' => $otherCompany, 'role_code' => 'company_admin',
        ]);
        $this->actingAs($attacker)->postJson($url, $this->identity())->assertForbidden();
        $this->actingAs($attacker)->getJson($base)->assertForbidden();
        $this->assertDatabaseCount('employee_identity_documents', 0);
    }

    public function test_manager_cannot_read_or_write_family_or_document_numbers(): void
    {
        extract($this->fixture());
        config()->set('hrm.personnel_writes_enabled', true);
        $manager = User::factory()->create();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        DB::table('departments')->insert(['id'=>$department,'company_id'=>$company,'name'=>'Scoped']);
        DB::table('positions')->insert(['id'=>$position,'company_id'=>$company,'name'=>'Engineer']);
        DB::table('employee_assignments')->insert([
            'id'=>(string) Str::uuid(),'employee_id'=>$employee,
            'department_id'=>$department,'position_id'=>$position,'valid_from'=>'2026-01-01',
        ]);
        DB::table('company_user_roles')->insert([
            'company_id'=>$company,'user_id'=>$manager->id,'role_code'=>'department_manager',
        ]);
        DB::table('department_access_scopes')->insert([
            'company_id'=>$company,'user_id'=>$manager->id,'department_id'=>$department,
        ]);

        $this->actingAs($user)->postJson($base.'/private-records/identity', $this->identity())
            ->assertCreated();
        $this->actingAs($manager)->getJson($base)->assertOk()
            ->assertJsonMissingPath('private_profile');
        $this->actingAs($manager)->postJson($base.'/private-records/family', $this->family())
            ->assertForbidden();
    }
}
